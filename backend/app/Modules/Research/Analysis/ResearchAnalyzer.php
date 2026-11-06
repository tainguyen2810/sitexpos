<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Analysis;

use Illuminate\Support\Collection;
use SiteXPost\Modules\Research\Domain\CompetitorFinding;
use SiteXPost\Modules\Research\Domain\CompetitorWebsite;
use SiteXPost\Modules\Research\Domain\ContentGap;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\KeywordMetric;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\TopicCluster;
use SiteXPost\Modules\Research\Support\KeywordStore;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Turns collected keywords into decisions, deterministically:
 * 1. intent (rules; AI/user overrides are kept);
 * 2. coverage against existing WordPress content (topic similarity AND intent);
 * 3. opportunity score (OpportunityScorer v1, transparent);
 * 4. topic clusters (pillar = broadest keyword of the topic);
 * 5. content gaps: uncovered clusters, competitor topics, unanswered
 *    questions, keyword cannibalization, overlapping posts, Search Console
 *    "striking distance" queries.
 */
final class ResearchAnalyzer
{
    public const COVERED = 0.62;

    public const PARTIAL = 0.4;

    public function __construct(
        private readonly IntentClassifier $classifier,
        private readonly OpportunityScorer $scorer,
        private readonly KeywordExtractor $extractor,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * @return array<string, int>
     */
    public function analyze(SeoCampaign $campaign, ?string $runId): array
    {
        $vocab = $this->extractor->vocabulary($campaign);
        $docs = $this->siteDocs($campaign);
        /** @var Collection<int, Keyword> $keywords */
        $keywords = Keyword::query()->where('campaign_id', $campaign->id)->where('status', '!=', 'rejected')->limit(KeywordStore::MAX_KEYWORDS_PER_CAMPAIGN)->get();
        $metrics = $this->latestMetrics($keywords->pluck('id')->all());

        $rows = [];
        foreach ($keywords as $kw) {
            $cls = $this->classifier->classify($kw->keyword, $campaign->brand_terms ?? []);
            // A bare product name from the store's catalogue is searched to evaluate it before buying.
            if ($cls['rule'] === null && collect($kw->evidence ?? [])->contains(fn ($e) => is_array($e) && ($e['type'] ?? null) === 'product')) {
                $cls = ['intent' => 'commercial', 'content_type' => 'commercial', 'rule' => 'product', 'confidence' => 60];
            }
            if ($kw->intent_source === 'rule') {
                $kw->intent = $cls['intent'];
            }
            $contentType = $cls['content_type'];
            $tokens = Text::topicTokens($kw->keyword);

            // coverage: similar topic; same-intent match = duplicate risk
            $covered = [];
            $best = 0.0;
            $sameIntentDup = false;
            foreach ($docs as $doc) {
                $sim = Text::similarity($tokens, $doc['tokens']);
                if ($sim >= self::PARTIAL) {
                    $sameIntent = IntentClassifier::family($doc['content_type']) === IntentClassifier::family($contentType);
                    $covered[] = ['id' => $doc['id'], 'title' => $doc['title'], 'url' => $doc['url'], 'similarity' => round($sim, 2), 'same_intent' => $sameIntent];
                    $best = max($best, $sim);
                    if ($sim >= self::COVERED && $sameIntent) {
                        $sameIntentDup = true;
                    }
                }
            }
            usort($covered, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
            $coverage = $sameIntentDup ? 'covered' : ($best >= self::PARTIAL ? 'partial' : 'none');

            $m = $metrics[$kw->id] ?? [];
            $score = $this->scorer->score([
                'topic_tokens' => $tokens, 'intent' => $kw->intent, 'sources' => $kw->sources ?? [],
                'vocabulary' => $vocab['all'], 'product_vocabulary' => $vocab['products'], 'goals' => $campaign->goals ?? [],
                'coverage' => $coverage, 'duplicate_same_intent' => $sameIntentDup,
                'gsc' => $m['search_console'] ?? null, 'provider' => $m['provider'] ?? null,
            ]);

            $kw->forceFill([
                'score_breakdown' => $score + ['coverage' => $coverage, 'content_type' => $contentType],
                'opportunity_score' => $score['score'],
                'covered_by' => array_slice($covered, 0, 5),
            ])->save();

            $rows[] = ['kw' => $kw, 'tokens' => $tokens, 'content_type' => $contentType, 'coverage' => $coverage, 'covered' => $covered, 'score' => $score['score'], 'gsc' => $m['search_console'] ?? null];
        }

        $clusters = $this->cluster($campaign, $rows, $docs);
        $gaps = $this->gaps($campaign, $rows, $docs, $runId);

        return ['keywords' => count($rows), 'clusters' => $clusters, 'gaps' => $gaps];
    }

    /**
     * Greedy topic clustering. Keywords are visited broadest-first (fewest
     * topic words, highest score); a keyword joins the first cluster whose
     * core it contains (similarity ≥ 0.6), else it seeds a new cluster.
     * Clusters with < 3 keywords are not kept as clusters.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $docs
     */
    private function cluster(SeoCampaign $campaign, array $rows, array $docs): int
    {
        usort($rows, fn ($a, $b) => [count($a['tokens']), -$a['score']] <=> [count($b['tokens']), -$b['score']]);
        $groups = [];
        foreach ($rows as $row) {
            if ($row['tokens'] === [] || $row['kw']->intent === 'navigational') {
                continue;
            }
            $placed = false;
            foreach ($groups as &$g) {
                if (Text::containment($g['core'], $row['tokens']) >= 0.99 || Text::similarity($g['core'], $row['tokens']) >= 0.6) {
                    $g['rows'][] = $row;
                    $placed = true;
                    break;
                }
            }
            unset($g);
            if (! $placed && count($row['tokens']) <= 4) {
                $groups[] = ['core' => $row['tokens'], 'rows' => [$row]];
            }
        }

        $keepIds = [];
        $assigned = [];
        foreach ($groups as $g) {
            if (count($g['rows']) < 3) {
                continue;
            }
            $pillar = $g['rows'][0]['kw'];
            $core = $g['core'];
            sort($core);
            $fp = Text::fingerprint(...$core);
            $intents = [];
            $covered = 0;
            $scores = [];
            foreach ($g['rows'] as $r) {
                $intents[$r['content_type']] = ($intents[$r['content_type']] ?? 0) + 1;
                $covered += $r['coverage'] === 'covered' ? 1 : 0;
                $scores[] = $r['score'];
            }
            arsort($intents);
            $pillarDoc = null;
            foreach ($docs as $doc) {
                if (Text::similarity($core, $doc['tokens']) >= 0.75) {
                    $pillarDoc = $doc['id'];
                    break;
                }
            }

            $cluster = TopicCluster::query()->where('campaign_id', $campaign->id)->where('fingerprint', $fp)->first() ?? new TopicCluster;
            $cluster->forceFill([
                'campaign_id' => $campaign->id,
                'name' => mb_convert_case($pillar->keyword, MB_CASE_TITLE, 'UTF-8'),
                'fingerprint' => $fp,
                'intent' => IntentClassifier::family((string) array_key_first($intents)) === 'commercial' ? 'commercial' : $pillar->intent,
                'pillar_keyword_id' => $pillar->id,
                'pillar_content_item_id' => $pillarDoc,
                'description' => null,
                'opportunity_score' => (int) round(array_sum($scores) / max(1, count($scores))),
                'keywords_count' => count($g['rows']),
                'coverage' => ['covered_keywords' => $covered, 'total_keywords' => count($g['rows']), 'by_content_type' => $intents],
                'status' => $cluster->exists ? $cluster->status : 'active',
            ])->save();
            $keepIds[] = $cluster->id;
            foreach ($g['rows'] as $r) {
                $assigned[$r['kw']->id] = $cluster->id;
            }
        }

        foreach ($rows as $r) {
            $cid = $assigned[$r['kw']->id] ?? null;
            if ($r['kw']->cluster_id !== $cid) {
                $r['kw']->forceFill(['cluster_id' => $cid])->save();
            }
        }
        // Clusters no longer supported by data are archived, never deleted (ideas may point at them).
        TopicCluster::query()->where('campaign_id', $campaign->id)->whereNotIn('id', $keepIds)->where('status', 'active')->update(['status' => 'archived']);

        return count($keepIds);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $docs
     */
    private function gaps(SeoCampaign $campaign, array $rows, array $docs, ?string $runId): int
    {
        $n = 0;

        // a) clusters the site does not cover at all
        foreach (TopicCluster::query()->where('campaign_id', $campaign->id)->where('status', 'active')->get() as $cluster) {
            if (($cluster->coverage['covered_keywords'] ?? 0) === 0 && $cluster->pillar_content_item_id === null) {
                $n += $this->gap($campaign, $runId, 'missing_topic', $cluster->name, 'Website chưa có bài nào cho cụm chủ đề này ('.$cluster->keywords_count.' từ khóa).',
                    ['cluster_id' => $cluster->id, 'keywords' => $cluster->keywords_count], $cluster->id, null, [], (int) $cluster->opportunity_score);
            }
        }

        // b) cannibalization: ≥ 2 existing posts with the same intent target the keyword
        foreach ($rows as $r) {
            $same = array_values(array_filter($r['covered'], fn ($c) => $c['same_intent'] && $c['similarity'] >= self::COVERED));
            if (count($same) >= 2) {
                $n += $this->gap($campaign, $runId, 'cannibalization', $r['kw']->keyword,
                    count($same).' bài hiện có cùng nhắm từ khóa với cùng search intent — nên hợp nhất hoặc phân vai rõ.',
                    ['keyword_id' => $r['kw']->id, 'posts' => array_slice($same, 0, 5)], $r['kw']->cluster_id, null, array_column(array_slice($same, 0, 10), 'id'), 60);
            }
        }

        // c) overlapping existing posts (near-duplicate titles with the same intent)
        $count = count($docs);
        for ($i = 0; $i < $count && $i < 400; $i++) {
            for ($j = $i + 1; $j < $count && $j < 400; $j++) {
                if ($docs[$i]['post_type'] === 'post' && $docs[$j]['post_type'] === 'post'
                    && IntentClassifier::family($docs[$i]['content_type']) === IntentClassifier::family($docs[$j]['content_type'])
                    && Text::jaccard($docs[$i]['tokens'], $docs[$j]['tokens']) >= 0.75) {
                    $n += $this->gap($campaign, $runId, 'overlap', $docs[$i]['title'], 'Hai bài viết hiện có gần trùng chủ đề và search intent.',
                        ['posts' => [['id' => $docs[$i]['id'], 'title' => $docs[$i]['title'], 'url' => $docs[$i]['url']], ['id' => $docs[$j]['id'], 'title' => $docs[$j]['title'], 'url' => $docs[$j]['url']]]],
                        null, null, [$docs[$i]['id'], $docs[$j]['id']], 40);
                }
            }
        }

        // d) competitor topics and questions the site has not answered
        $competitors = CompetitorWebsite::query()->where('campaign_id', $campaign->id)->get()->keyBy('id');
        $vocab = $this->extractor->vocabulary($campaign)['all'];
        $findings = CompetitorFinding::query()->whereIn('competitor_website_id', $competitors->keys())->whereIn('kind', ['page', 'question', 'category'])
            ->orderByDesc('fetched_at')->limit(600)->get();
        foreach ($findings as $f) {
            $tokens = Text::topicTokens($f->title);
            if (! $this->extractor->touches($f->title, $vocab)) {
                continue;
            }
            $best = 0.0;
            foreach ($docs as $doc) {
                $best = max($best, Text::similarity($tokens, $doc['tokens']));
            }
            if ($best >= self::PARTIAL) {
                continue;
            }
            $type = $f->kind === 'question' ? 'unanswered_question' : 'competitor_topic';
            $name = $competitors->get($f->competitor_website_id)->name ?? '';
            $n += $this->gap($campaign, $runId, $type, $f->title,
                $type === 'unanswered_question' ? "Đối thủ {$name} có trả lời câu hỏi này, website của bạn chưa có." : "Đối thủ {$name} có nội dung về chủ đề này, website của bạn chưa có.",
                ['competitor' => $name, 'url' => $f->url, 'fetched_at' => $f->fetched_at?->toIso8601ZuluString()], null, $f->competitor_website_id, [], 55);
        }

        // e) Search Console striking distance: impressions but position 8–30
        foreach ($rows as $r) {
            $g = $r['gsc'];
            if ($g !== null && $g['impressions'] >= 50 && $g['position'] >= 8 && $g['position'] <= 30) {
                $n += $this->gap($campaign, $runId, 'striking_distance', $r['kw']->keyword,
                    sprintf('Đã có %d lượt hiển thị nhưng vị trí trung bình %.1f — nên tối ưu/viết bài chuyên sâu.', $g['impressions'], $g['position']),
                    ['keyword_id' => $r['kw']->id, 'search_console' => $g], $r['kw']->cluster_id, null, array_column($r['covered'], 'id'), 75);
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $related
     */
    private function gap(SeoCampaign $campaign, ?string $runId, string $type, string $topic, string $description, array $evidence, ?string $clusterId, ?string $competitorId, array $related, int $score): int
    {
        $fp = Text::fingerprint($type, Text::fold($topic));
        $gap = ContentGap::query()->where('campaign_id', $campaign->id)->where('fingerprint', $fp)->first();
        $new = $gap === null;
        $gap ??= new ContentGap;
        $gap->forceFill([
            'campaign_id' => $campaign->id, 'research_run_id' => $runId, 'cluster_id' => $clusterId, 'competitor_website_id' => $competitorId,
            'gap_type' => $type, 'topic' => Text::clean($topic, 300), 'description' => $description, 'evidence' => $evidence,
            'related_content_item_ids' => array_values(array_unique($related)), 'score' => max(0, min(100, $score)),
            'status' => $gap->exists ? $gap->status : 'open', 'fingerprint' => $fp,
        ])->save();

        return $new ? 1 : 0;
    }

    /**
     * Existing site content as topic documents.
     *
     * @return list<array{id: string, title: string, url: string|null, post_type: string, tokens: list<string>, content_type: string}>
     */
    public function siteDocs(SeoCampaign $campaign): array
    {
        $docs = [];
        foreach ($this->sites->content($campaign->site_id) as $c) {
            $tokens = Text::topicTokens($c['title']);
            if ($tokens === []) {
                continue;
            }
            $docs[] = [
                'id' => $c['id'], 'title' => $c['title'], 'url' => $c['url'], 'post_type' => $c['post_type'], 'tokens' => $tokens,
                'content_type' => $c['post_type'] === 'product' ? 'transactional' : $this->classifier->classify($c['title'], $campaign->brand_terms ?? [])['content_type'],
            ];
        }

        return $docs;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{search_console?: array{impressions: int, clicks: int, position: float}, provider?: array{search_volume: int|null, difficulty: int|null, cpc_micro_usd: int|null}}>
     */
    private function latestMetrics(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (KeywordMetric::query()->whereIn('keyword_id', $chunk)->orderBy('period_start')->get() as $m) {
                if ($m->source === 'search_console') {
                    $out[$m->keyword_id]['search_console'] = ['impressions' => (int) $m->impressions, 'clicks' => (int) $m->clicks, 'position' => (float) $m->position];
                } else {
                    $prev = $out[$m->keyword_id]['provider'] ?? ['search_volume' => null, 'difficulty' => null, 'cpc_micro_usd' => null];
                    $out[$m->keyword_id]['provider'] = [
                        'search_volume' => $m->search_volume ?? $prev['search_volume'],
                        'difficulty' => $m->difficulty ?? $prev['difficulty'],
                        'cpc_micro_usd' => $m->cpc_micro_usd ?? $prev['cpc_micro_usd'],
                    ];
                }
            }
        }

        return $out;
    }
}
