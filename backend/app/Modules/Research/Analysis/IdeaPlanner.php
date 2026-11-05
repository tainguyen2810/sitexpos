<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Analysis;

use Illuminate\Database\QueryException;
use SiteXPost\Modules\Research\Domain\ContentGap;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\ContentIdeaKeyword;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\TopicCluster;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Builds article candidates from scored keywords (one article = one primary
 * keyword + same-intent secondaries), lets AI phrase them, then validates.
 *
 * Anti-duplication (DB-enforced by a unique fingerprint per campaign):
 *   fingerprint = sha1(intent family | sorted topic words of the primary keyword)
 * plus a similarity check against existing ideas and existing WordPress posts
 * with the same intent family. Keywords with similar words but a different
 * intent (e.g. "card wifi laptop giá" vs "cách thay card wifi laptop") become
 * different articles on purpose; same-intent near-duplicates are merged.
 */
final class IdeaPlanner
{
    public function __construct(private readonly SiteDirectory $sites) {}

    /**
     * @return list<array<string, mixed>> groups, best first
     */
    public function candidates(SeoCampaign $campaign, int $limit): array
    {
        $existing = ContentIdea::query()->where('campaign_id', $campaign->id)->with('primaryKeyword:id,keyword')->get(['id', 'fingerprint', 'search_intent', 'content_type', 'title', 'primary_keyword_id', 'cluster_id', 'is_pillar']);
        $fingerprints = $existing->pluck('fingerprint')->flip()->all();
        $existingTokens = $existing->map(fn (ContentIdea $i) => [
            'family' => IntentClassifier::family($i->content_type),
            'tokens' => Text::topicTokens($i->primaryKeyword->keyword ?? $i->title),
        ])->all();

        $keywords = Keyword::query()->where('campaign_id', $campaign->id)->whereIn('status', ['suggested', 'approved'])
            ->whereNotNull('opportunity_score')->orderByDesc('opportunity_score')->limit(800)->get();
        $gapsByCluster = ContentGap::query()->where('campaign_id', $campaign->id)->where('status', 'open')->get()->groupBy('cluster_id');

        $groups = [];
        $used = [];
        // 1) pillar pages for active clusters without pillar content/idea
        foreach (TopicCluster::query()->where('campaign_id', $campaign->id)->where('status', 'active')->orderByDesc('opportunity_score')->get() as $cluster) {
            if ($cluster->pillar_content_item_id !== null || $existing->contains(fn ($i) => $i->cluster_id === $cluster->id && $i->is_pillar)) {
                continue;
            }
            $pillar = $keywords->firstWhere('id', $cluster->pillar_keyword_id);
            if ($pillar === null) {
                continue;
            }
            $fp = self::fingerprint('pillar', $pillar->keyword);
            if (isset($fingerprints[$fp])) {
                continue;
            }
            $secondary = $keywords->where('cluster_id', $cluster->id)->where('id', '!=', $pillar->id)->take(8)->values();
            $groups[] = $this->group($pillar, $secondary->all(), 'pillar', $cluster, $gapsByCluster->get($cluster->id)?->all() ?? [], $fp, true);
            $fingerprints[$fp] = true;
            $used[$pillar->id] = true;
        }

        // 2) articles
        foreach ($keywords as $kw) {
            if (isset($used[$kw->id])) {
                continue;
            }
            $breakdown = $kw->score_breakdown ?? [];
            // Covered topics, navigational queries and the bare industry name are not articles.
            if (($breakdown['coverage'] ?? 'none') === 'covered' || $kw->intent === 'navigational' || Text::fold($kw->keyword) === Text::fold($campaign->industry)) {
                continue;
            }
            $type = (string) ($breakdown['content_type'] ?? 'informational');
            $family = IntentClassifier::family($type);
            $tokens = Text::topicTokens($kw->keyword);
            $fp = self::fingerprint($family, $kw->keyword);
            if (isset($fingerprints[$fp])) {
                continue;
            }
            foreach ($existingTokens as $e) {
                if ($e['family'] === $family && Text::similarity($e['tokens'], $tokens) >= 0.8) {
                    continue 2;
                }
            }

            $secondary = [];
            foreach ($keywords as $other) {
                if ($other->id === $kw->id || isset($used[$other->id]) || count($secondary) >= 6) {
                    continue;
                }
                $otherType = (string) (($other->score_breakdown ?? [])['content_type'] ?? 'informational');
                if (IntentClassifier::family($otherType) === $family && Text::similarity($tokens, Text::topicTokens($other->keyword)) >= 0.5) {
                    $secondary[] = $other;
                    $used[$other->id] = true;
                }
            }
            $used[$kw->id] = true;
            $cluster = $kw->cluster_id !== null ? TopicCluster::query()->find($kw->cluster_id) : null;
            $groups[] = $this->group($kw, $secondary, $type, $cluster, $kw->cluster_id !== null ? ($gapsByCluster->get($kw->cluster_id)?->all() ?? []) : [], $fp, false);
            $fingerprints[$fp] = true;
            $existingTokens[] = ['family' => $family, 'tokens' => $tokens];
            if (count($groups) >= $limit * 2) {
                break;
            }
        }

        usort($groups, fn ($a, $b) => [$b['is_pillar'], $b['score']] <=> [$a['is_pillar'], $a['score']]);

        return array_slice($groups, 0, $limit);
    }

    /**
     * Save validated ideas. $ai maps group number (1-based) → AI phrasing.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<int, array<string, mixed>>  $ai
     * @return array{created: int, ai: int, rule: int, dropped: int}
     */
    public function save(SeoCampaign $campaign, ?string $runId, array $groups, array $ai): array
    {
        $excluded = $campaign->settingsOrDefault()->excluded_topics ?? [];
        $titles = ContentIdea::query()->where('campaign_id', $campaign->id)->pluck('title')->map(fn ($t) => Text::tokens($t))->all();
        $stats = ['created' => 0, 'ai' => 0, 'rule' => 0, 'dropped' => 0];

        foreach ($groups as $i => $g) {
            $phr = $ai[$i + 1] ?? null;
            $source = 'ai';
            if ($phr !== null) {
                $title = Text::clean($phr['title'] ?? '', 300);
                $type = in_array($phr['content_type'] ?? null, IntentClassifier::CONTENT_TYPES, true) ? (string) $phr['content_type'] : $g['content_type'];
                // AI may refine the format, not change the search intent family.
                if ($g['is_pillar']) {
                    $type = 'pillar';
                } elseif (IntentClassifier::family($type) !== IntentClassifier::family($g['content_type']) && ! in_array($g['content_type'], ['informational'], true)) {
                    $type = $g['content_type'];
                }
                if ($title === '' || Text::matchesAny($title, $excluded) || Text::containment(Text::topicTokens($g['primary']), Text::tokens($title)) < 0.5) {
                    $phr = null; // off-topic or keyword missing → deterministic phrasing
                }
            }
            if ($phr === null) {
                $source = 'rule';
                $type = $g['content_type'];
                $title = self::ruleTitle($g['primary'], $type, $campaign->language);
            }
            $titleTokens = Text::tokens($title);
            foreach ($titles as $t) {
                if (Text::jaccard($t, $titleTokens) >= 0.8) {
                    $stats['dropped']++;

                    continue 2;
                }
            }

            $idea = new ContentIdea;
            $idea->forceFill([
                'campaign_id' => $campaign->id,
                'research_run_id' => $runId,
                'cluster_id' => $g['cluster_id'],
                'primary_keyword_id' => $g['primary_id'],
                'title' => $title,
                'search_intent' => $g['intent'],
                'content_type' => $type,
                'audience' => $phr !== null ? Text::clean($phr['audience'] ?? '', 300) : implode(', ', array_slice($campaign->audiences ?? [], 0, 3)),
                'angle' => $phr !== null ? Text::clean($phr['angle'] ?? '', 2000) : null,
                'priority' => $g['score'] >= 70 ? 'high' : ($g['score'] >= 45 ? 'medium' : 'low'),
                'opportunity_score' => $g['score'],
                'score_breakdown' => $g['breakdown'],
                'rationale' => $phr !== null ? Text::clean($phr['rationale'] ?? '', 2000) : $g['rule_rationale'],
                'evidence' => $g['evidence'] + ['outline' => $phr !== null ? array_slice(array_values(array_filter(array_map(fn ($o) => Text::clean($o, 200), (array) ($phr['outline'] ?? [])))), 0, 8) : []],
                'source' => $source,
                'is_pillar' => $g['is_pillar'],
                'status' => 'suggested',
                'fingerprint' => $g['fingerprint'],
                'overlap_content_item_id' => $g['overlap_id'],
                'overlap_score' => $g['overlap_score'],
            ]);
            try {
                $idea->save();
            } catch (QueryException) {
                $stats['dropped']++; // same fingerprint saved by a previous attempt

                continue;
            }
            $this->link($idea, $g['primary_id'], 'primary');
            foreach ($g['secondary_ids'] as $sid) {
                $this->link($idea, $sid, 'secondary');
            }
            $titles[] = $titleTokens;
            $stats['created']++;
            $stats[$source]++;
        }

        return $stats;
    }

    /**
     * Context for the AI call (no secrets, bounded size).
     *
     * @return array<string, mixed>
     */
    public function aiContext(SeoCampaign $campaign): array
    {
        $titles = array_slice(array_map(fn ($c) => $c['title'], $this->sites->content($campaign->site_id, ['post', 'page'], 80)), 0, 80);

        return [
            'industry' => $campaign->industry, 'market' => $campaign->market, 'language' => $campaign->language,
            'products' => array_slice($campaign->products ?? [], 0, 20), 'services' => array_slice($campaign->services ?? [], 0, 20),
            'audiences' => array_slice($campaign->audiences ?? [], 0, 10), 'goals' => $campaign->goals ?? [], 'brand' => $campaign->brand_terms ?? [],
            'excluded_topics' => $campaign->settingsOrDefault()->excluded_topics ?? [],
            'existing_titles' => $titles,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public static function forAi(array $groups): array
    {
        return array_map(fn ($g, $i) => [
            'group' => $i + 1, 'primary_keyword' => $g['primary'], 'secondary_keywords' => $g['secondary'],
            'search_intent' => $g['intent'], 'suggested_content_type' => $g['content_type'], 'is_pillar' => $g['is_pillar'],
            'evidence' => $g['ai_evidence'],
        ], $groups, array_keys($groups));
    }

    public static function fingerprint(string $family, string $keyword): string
    {
        $tokens = Text::topicTokens($keyword);
        sort($tokens);

        return Text::fingerprint($family, ...$tokens);
    }

    public static function ruleTitle(string $keyword, string $type, string $language): string
    {
        $k = mb_strtoupper(mb_substr($keyword, 0, 1)).mb_substr($keyword, 1);
        $f = Text::fold($keyword);
        // A real question from the site / competitors / Search Console is already the best title.
        if (preg_match('/(\?\s*$)|\b(gi|nao|bao nhieu|co nen|tai sao|vi sao|the nao|o dau)\b|\bkhong$|^(what|why|how|which|is|can|should|does)\b/', $f.(str_ends_with(trim($keyword), '?') ? '?' : '')) === 1) {
            return rtrim($k, ' ?').'?';
        }
        if ($language !== 'vi') {
            return match ($type) {
                'pillar' => "{$k}: the complete guide",
                'tutorial' => str_starts_with($f, 'how') ? $k : "How to: {$k}",
                'comparison' => "{$k}: detailed comparison",
                'troubleshooting' => "{$k}: causes and fixes",
                'faq' => rtrim($k, '?').'?',
                'commercial' => "{$k}: review and buying advice",
                'transactional' => "{$k}: prices and where to buy",
                default => "{$k}: tips, pitfalls and common questions",
            };
        }

        return match ($type) {
            'pillar' => "{$k}: hướng dẫn toàn diện từ A đến Z",
            'tutorial' => str_starts_with($f, 'cach') || str_starts_with($f, 'huong dan') ? $k : "Hướng dẫn {$keyword}",
            'comparison' => "{$k}: so sánh chi tiết và nên chọn loại nào",
            'troubleshooting' => "{$k}: nguyên nhân và cách khắc phục",
            'faq' => rtrim($k, '?').'?',
            'commercial' => "{$k}: đánh giá và kinh nghiệm chọn mua",
            'transactional' => "{$k}: bảng giá và lưu ý khi mua",
            default => "{$k}: kinh nghiệm, lưu ý và câu hỏi thường gặp",
        };
    }

    /**
     * @param  list<Keyword>  $secondary
     * @param  list<ContentGap>  $gaps
     * @return array<string, mixed>
     */
    private function group(Keyword $primary, array $secondary, string $type, ?TopicCluster $cluster, array $gaps, string $fp, bool $pillar): array
    {
        $breakdown = $primary->score_breakdown ?? [];
        $covered = $primary->covered_by ?? [];
        $sources = $primary->sources ?? [];
        foreach ($secondary as $s) {
            $sources = array_merge($sources, $s->sources ?? []);
        }
        $sources = array_values(array_unique($sources));
        $gapRefs = array_map(fn (ContentGap $g) => ['type' => $g->gap_type, 'topic' => $g->topic], array_slice($gaps, 0, 3));
        $evidence = [
            'keyword_sources' => $sources,
            'keyword_evidence' => array_slice($primary->evidence ?? [], 0, 4),
            'gaps' => $gapRefs,
            'related_existing' => array_slice($covered, 0, 3),
            'score_measured' => (bool) ($breakdown['measured'] ?? false),
        ];
        $why = [];
        if (($breakdown['coverage'] ?? '') === 'none') {
            $why[] = 'website chưa có bài cho chủ đề này';
        }
        if (in_array('search_console', $sources, true)) {
            $why[] = 'đã xuất hiện trong Search Console';
        }
        if (in_array('competitor', $sources, true)) {
            $why[] = 'đối thủ đã khai thác';
        }
        if (array_intersect(['wordpress', 'woocommerce', 'website_crawl'], $sources) !== []) {
            $why[] = 'liên quan sản phẩm/danh mục trên website';
        }
        if ($sources === ['ai_expansion']) {
            $why[] = 'AI đề xuất khi nghiên cứu ngành (chưa có số liệu xác minh)';
        }

        return [
            'primary' => $primary->keyword,
            'primary_id' => $primary->id,
            'secondary' => array_map(fn (Keyword $k) => $k->keyword, $secondary),
            'secondary_ids' => array_map(fn (Keyword $k) => $k->id, $secondary),
            'intent' => $pillar ? ($cluster->intent ?? $primary->intent) : $primary->intent,
            'content_type' => $type,
            'is_pillar' => $pillar,
            'cluster_id' => $cluster?->id,
            'score' => (int) ($primary->opportunity_score ?? 0),
            'breakdown' => $breakdown,
            'fingerprint' => $fp,
            'overlap_id' => $covered[0]['id'] ?? null,
            'overlap_score' => isset($covered[0]['similarity']) ? (int) round($covered[0]['similarity'] * 100) : null,
            'evidence' => $evidence,
            'ai_evidence' => ['sources' => $sources, 'gaps' => $gapRefs, 'related_existing_titles' => array_column(array_slice($covered, 0, 3), 'title')],
            'rule_rationale' => $why === [] ? 'Từ khóa liên quan ngành hàng, điểm cơ hội '.($primary->opportunity_score ?? 0).'/100.' : ucfirst(implode('; ', $why)).'.',
        ];
    }

    private function link(ContentIdea $idea, string $keywordId, string $role): void
    {
        if (ContentIdeaKeyword::query()->where(['content_idea_id' => $idea->id, 'keyword_id' => $keywordId])->exists()) {
            return;
        }
        $row = new ContentIdeaKeyword;
        $row->forceFill(['content_idea_id' => $idea->id, 'keyword_id' => $keywordId, 'role' => $role])->save();
    }
}
