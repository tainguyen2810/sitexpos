<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\EvidenceSnapshot;
use SiteXPost\Modules\Writer\Domain\EvidenceSource;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Domain\ResearchQuestion;
use SiteXPost\Modules\Writer\Retrieval\SourceRetriever;
use SiteXPost\Modules\Writer\Support\Verbatim;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Evidence-first research (AI-WRITER-PIPELINE.md §3):
 * Topic → Research Questions → Source Discovery → Source Retrieval →
 * Evidence Extraction → Evidence Evaluation → Research Dossier.
 *
 * A source only becomes evidence after the system itself fetched it and the
 * excerpt was found verbatim in the stored snapshot. AI-suggested URLs are
 * candidates, never evidence. Every step is idempotent (re-running it after
 * a retry adds nothing twice).
 */
class ResearchEngine
{
    public function __construct(
        private readonly WriterAi $ai,
        private readonly ArticleContext $context,
        private readonly SourceRetriever $retriever,
        private readonly WriterSettings $settings,
        private readonly ResearchSettings $researchSettings,
    ) {}

    public function dossierFor(Article $a): ResearchDossier
    {
        $d = ResearchDossier::query()->where('article_id', $a->id)->whereIn('status', ['pending', 'running'])->latest()->first();
        if ($d !== null) {
            return $d;
        }
        $d = new ResearchDossier;
        $d->forceFill(['article_id' => $a->id, 'status' => 'running', 'depth' => (string) $a->setting('research_depth', 'standard')])->save();

        return $d;
    }

    /**
     * @return array<string, mixed>
     */
    public function questions(Article $a, ResearchDossier $d, string $key, ?string $run): array
    {
        if (ResearchQuestion::query()->where('research_dossier_id', $d->id)->exists()) {
            return ['skipped' => 'already_done'];
        }
        $origin = 'ai';
        $warnings = [];
        $list = [];
        try {
            $res = $this->ai->questions($a, $this->context->base($a) + ['idea_outline' => $a->setting('idea_outline', [])], $key, $run);
            foreach ((array) ($res->json['questions'] ?? []) as $q) {
                $text = Text::clean($q['question'] ?? '', 500);
                if ($text !== '') {
                    $list[] = ['question' => $text, 'purpose' => Text::clean($q['purpose'] ?? '', 500)];
                }
            }
        } catch (AiGatewayException $e) {
            $warnings[] = 'questions_ai_failed:'.$e->errorCode;
        }
        if ($list === []) {
            $origin = 'rule';
            $list = $this->templateQuestions($a);
        }
        foreach (array_slice($list, 0, 10) as $i => $q) {
            $row = new ResearchQuestion;
            $row->forceFill(['research_dossier_id' => $d->id, 'position' => $i + 1, 'question' => $q['question'], 'purpose' => $q['purpose'] ?: null, 'origin' => $origin])->save();
        }
        $this->warn($d, $warnings);

        return ['questions' => min(10, count($list)), 'origin' => $origin, 'warnings' => $warnings];
    }

    /**
     * @return list<array{question: string, purpose: string}>
     */
    private function templateQuestions(Article $a): array
    {
        $k = $a->primary_keyword;
        $out = [
            ['question' => "{$k} là gì và dùng để làm gì?", 'purpose' => 'Định nghĩa, bối cảnh'],
            ['question' => "Thông số / đặc điểm chính của {$k} theo tài liệu chính thức là gì?", 'purpose' => 'Thông số cần nguồn gốc'],
            ['question' => "{$k} tương thích hoặc phù hợp với trường hợp nào, có giới hạn gì?", 'purpose' => 'Tương thích, giới hạn'],
            ['question' => "Cần lưu ý gì khi chọn mua / sử dụng {$k}?", 'purpose' => 'Giá trị thực tế cho người đọc'],
            ['question' => "Thông tin nào về {$k} dễ lỗi thời (phiên bản, giá, quy định)?", 'purpose' => 'Phát hiện thông tin cũ'],
        ];
        foreach (array_slice((array) $a->setting('idea_outline', []), 0, 3) as $h) {
            if (is_string($h) && trim($h) !== '') {
                $out[] = ['question' => Text::clean($h, 300).'?', 'purpose' => 'Mục trong dàn ý ý tưởng'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function discover(Article $a, ResearchDossier $d, string $key, ?string $run): array
    {
        $max = $this->settings->maxSources($d->depth, $a->workspace_id);
        $added = ['user' => 0, 'customer_site' => 0, 'ai_suggestion' => 0];
        $warnings = [];

        foreach ((array) $a->setting('source_urls', []) as $u) {
            $url = is_array($u) ? (string) ($u['url'] ?? '') : (string) $u;
            $type = is_array($u) ? ($u['type'] ?? null) : null;
            if ($this->add($d, $url, 'user', $max, is_string($type) ? $type : null)) {
                $added['user']++;
            }
        }
        foreach (array_slice($this->context->linkCandidates($a, 3), 0, 3) as $c) {
            if ($this->add($d, $c['url'], 'customer_site', $max)) {
                $added['customer_site']++;
            }
        }
        $search = false;
        if ((bool) $a->setting('ai_source_discovery', true) && $this->count($d) < $max) {
            // Web search only when the article allows it AND a model on the
            // discovery route can do it; otherwise the gateway would refuse the
            // call and discovery would silently find nothing.
            $search = $this->searchAllowed($a) && $this->ai->canSearch();
            if ($this->searchAllowed($a) && ! $search) {
                $warnings[] = 'web_search_unavailable';
            }
            $questions = ResearchQuestion::query()->where('research_dossier_id', $d->id)->orderBy('position')->pluck('question')->all();
            $added['ai_suggestion'] += $this->suggest($a, $d, ['questions' => $questions], $key, $run, $search, $max, $warnings);
        }
        if ($this->count($d) === 0) {
            $warnings[] = 'no_source_candidates';
        }
        $this->warn($d, $warnings);

        return ['added' => $added, 'total' => $this->count($d), 'web_search' => $search, 'warnings' => $warnings];
    }

    /**
     * One AI discovery call; a failed web-search call is retried once
     * without search (the model then only proposes URLs it knows).
     *
     * @param  array<string, mixed>  $extra
     * @param  list<string>  $warnings
     */
    private function suggest(Article $a, ResearchDossier $d, array $extra, string $key, ?string $run, bool $search, int $max, array &$warnings): int
    {
        $ctx = ['topic' => $a->title, 'primary_keyword' => $a->primary_keyword, 'language' => $a->language, 'country' => $a->country] + $extra;
        try {
            $res = $this->ai->discovery($a, $ctx, $key, $run, $search);
        } catch (AiGatewayException $e) {
            $warnings[] = 'discovery_ai_failed:'.$e->errorCode;
            if (! $search) {
                return 0;
            }
            try {
                $res = $this->ai->discovery($a, $ctx, $key.':nosearch', $run, false);
            } catch (AiGatewayException $e) {
                $warnings[] = 'discovery_ai_failed:'.$e->errorCode;

                return 0;
            }
        }
        $n = 0;
        foreach (array_slice((array) ($res->json['urls'] ?? []), 0, 8) as $u) {
            if ($this->add($d, (string) ($u['url'] ?? ''), 'ai_suggestion', $max)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Follow-up lookup ("tự tra cứu"): when the first pass left the dossier
     * below the evidence minimum and web search is available, search again
     * for the questions that still have no evidence (avoiding URLs already
     * tried), then fetch and extract the new sources. One extra round only.
     *
     * @return array<string, mixed>
     */
    public function lookup(Article $a, ResearchDossier $d, string $key, ?string $run): array
    {
        $min = max(0, $this->settings->int('min_evidence_items', $a->workspace_id));
        $have = EvidenceItem::query()->where('research_dossier_id', $d->id)->count();
        if ($have >= $min) {
            return ['skipped' => 'enough_evidence', 'evidence' => $have];
        }
        if (! (bool) $a->setting('ai_source_discovery', true) || ! $this->searchAllowed($a) || ! $this->ai->canSearch()) {
            return ['skipped' => 'web_search_unavailable', 'evidence' => $have];
        }
        $answered = EvidenceItem::query()->where('research_dossier_id', $d->id)->whereNotNull('research_question_id')->distinct()->pluck('research_question_id')->all();
        $open = ResearchQuestion::query()->where('research_dossier_id', $d->id)->whereNotIn('id', $answered)->orderBy('position')->pluck('question')->all();
        $tried = EvidenceSource::query()->where('research_dossier_id', $d->id)->pluck('url')->all();
        $warnings = [];
        $max = $this->settings->maxSources($d->depth, $a->workspace_id) + 6;
        $added = $this->suggest($a, $d, ['questions' => $open !== [] ? $open : ResearchQuestion::query()->where('research_dossier_id', $d->id)->pluck('question')->all(),
            'already_tried_urls' => array_slice($tried, 0, 30),
            'note' => 'Vòng tìm thứ hai: các URL đã thử không cho đủ bằng chứng. Tìm trang KHÁC, cụ thể hơn (tài liệu chính thức, trang hỗ trợ, bài phân tích uy tín), không lặp lại URL đã thử.'],
            $key, $run, true, $max, $warnings);
        $retrieved = $added > 0 ? $this->retrieve($a, $d) : ['fetches' => 0];
        $extracted = $added > 0 ? $this->extract($a, $d, $key.':x', $run) : ['evidence' => 0];
        $this->warn($d, $warnings);

        return ['added' => $added, 'fetches' => (int) ($retrieved['fetches'] ?? 0), 'evidence' => $have + (int) ($extracted['evidence'] ?? 0), 'warnings' => $warnings];
    }

    /** Web search is on for the platform/workspace AND for this article. */
    private function searchAllowed(Article $a): bool
    {
        return (bool) $this->settings->get('web_search_enabled', $a->workspace_id) && (bool) $a->setting('allow_web_search', false);
    }

    private function count(ResearchDossier $d): int
    {
        return EvidenceSource::query()->where('research_dossier_id', $d->id)->count();
    }

    private function add(ResearchDossier $d, string $url, string $via, int $max, ?string $declaredType = null): bool
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 1000 || preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url) !== 1 || $this->count($d) >= $max) {
            return false;
        }
        $hash = SourceRetriever::hashUrl($url);
        if (EvidenceSource::query()->where('research_dossier_id', $d->id)->where('url_hash', $hash)->exists()) {
            return false;
        }
        $s = new EvidenceSource;
        $s->forceFill(['research_dossier_id' => $d->id, 'url' => $url, 'url_hash' => $hash, 'discovered_via' => $via, 'retrieval_status' => 'pending',
            'source_type' => $declaredType !== null && in_array($declaredType, ['manufacturer', 'technical_doc', 'regulator', 'research', 'reputable'], true) && $via === 'user' ? $declaredType : 'other',
            'reliability' => $declaredType !== null && $via === 'user' ? ['declared_type' => $declaredType] : null])->save();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(Article $a, ResearchDossier $d): array
    {
        if (! $this->researchSettings->enabled('evidence_fetch')) {
            EvidenceSource::query()->where('research_dossier_id', $d->id)->where('retrieval_status', 'pending')->update(['retrieval_status' => 'skipped', 'error_code' => 'retrieval_disabled']);
            $d->forceFill(['retrieval_available' => false])->save();
            $this->warn($d, ['retrieval_disabled']);

            return ['fetched' => 0, 'skipped' => 'retrieval_disabled'];
        }
        $host = app(VersionManager::class)->siteHost($a);
        $brand = $this->context->brandTokens($a);
        $counts = [];
        $fetches = 0;
        foreach (EvidenceSource::query()->where('research_dossier_id', $d->id)->where('retrieval_status', 'pending')->orderBy('created_at')->get() as $s) {
            $declared = $s->reliability['declared_type'] ?? null;
            $this->retriever->retrieve($s, $host, $brand, is_string($declared) ? $declared : null);
            $counts[$s->retrieval_status] = ($counts[$s->retrieval_status] ?? 0) + 1;
            $fetches++;
        }

        return ['statuses' => $counts, 'fetches' => $fetches];
    }

    /**
     * @return array<string, mixed>
     */
    public function extract(Article $a, ResearchDossier $d, string $key, ?string $run): array
    {
        $questions = ResearchQuestion::query()->where('research_dossier_id', $d->id)->orderBy('position')->get();
        $qTexts = $questions->pluck('question')->all();
        $qIds = $questions->pluck('id', 'position')->all();
        $added = 0;
        $rejected = 0;
        $warnings = [];
        $maxChars = $this->settings->int('max_source_chars_for_ai');
        $topic = Text::tokens($a->primary_keyword.' '.$a->title.' '.implode(' ', $qTexts));

        $sources = EvidenceSource::query()->where('research_dossier_id', $d->id)->where('retrieval_status', 'fetched')->whereNotNull('snapshot_id')
            ->orderBy('created_at')->get()->sortBy(fn (EvidenceSource $s) => $s->reliability['rank'] ?? 7);
        foreach ($sources->values() as $i => $s) {
            if (($s->reliability['extracted'] ?? false) === true) {
                continue;
            }
            $snap = EvidenceSnapshot::query()->find($s->snapshot_id);
            if ($snap === null) {
                continue;
            }
            $window = self::window($snap->content_text, $topic, $maxChars);
            try {
                $res = $this->ai->source($a, $qTexts, $s->final_url ?? $s->url, $window, $key.':src'.$s->id, $run);
            } catch (AiGatewayException $e) {
                $warnings[] = 'extract_failed:'.$e->errorCode;
                $s->forceFill(['reliability' => array_merge($s->reliability ?? [], ['extract_error' => $e->errorCode])])->save();

                continue;
            }
            [$ok, $bad] = DB::transaction(function () use ($res, $snap, $s, $d, $qIds): array {
                $ok = 0;
                $bad = 0;
                $ref = (int) EvidenceItem::query()->where('research_dossier_id', $d->id)->lockForUpdate()->max('ref');
                foreach (array_slice((array) ($res->json['items'] ?? []), 0, 12) as $item) {
                    $excerpt = trim((string) ($item['excerpt'] ?? ''));
                    // Anti-fabrication: the excerpt must exist verbatim in what WE fetched.
                    if (! Verbatim::contains($snap->content_text, $excerpt)) {
                        $bad++;

                        continue;
                    }
                    $e = new EvidenceItem;
                    $e->forceFill([
                        'research_dossier_id' => $d->id, 'evidence_source_id' => $s->id, 'snapshot_id' => $snap->id,
                        'research_question_id' => $qIds[(int) ($item['question'] ?? 0)] ?? null, 'ref' => ++$ref,
                        'excerpt' => mb_substr($excerpt, 0, 2000), 'statement' => Text::clean($item['statement'] ?? '', 1000) ?: mb_substr($excerpt, 0, 300),
                        'date_context' => Text::clean($item['date_context'] ?? '', 190) ?: null, 'version_context' => Text::clean($item['version_context'] ?? '', 190) ?: null,
                        'relevance' => max(0, min(100, (int) ($item['relevance'] ?? 50))), 'extracted_by_model' => $res->modelKey,
                    ])->save();
                    $ok++;
                }
                $s->forceFill(['reliability' => array_merge($s->reliability ?? [], ['extracted' => true, 'evidence' => $ok, 'rejected_excerpts' => $bad])])->save();

                return [$ok, $bad];
            });
            $added += $ok;
            $rejected += $bad;
        }
        $d->forceFill(['rejected_evidence_count' => $d->rejected_evidence_count + $rejected])->save();
        $this->warn($d, $warnings);

        return ['evidence' => $added, 'rejected_not_verbatim' => $rejected, 'warnings' => $warnings];
    }

    /**
     * The most topic-dense window of a long text (paragraph granularity).
     *
     * @param  list<string>  $topic
     */
    public static function window(string $text, array $topic, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $paras = preg_split('/\n+/u', $text) ?: [];
        $scores = array_map(static fn (string $p): float => Text::containment($topic, Text::tokens($p)) + 0.0001, $paras);
        $best = 0;
        $bestScore = -1.0;
        $n = count($paras);
        for ($i = 0; $i < $n; $i++) {
            $len = 0;
            $sum = 0.0;
            for ($j = $i; $j < $n && $len + mb_strlen($paras[$j]) <= $maxChars; $j++) {
                $len += mb_strlen($paras[$j]) + 1;
                $sum += $scores[$j];
            }
            if ($sum > $bestScore) {
                $bestScore = $sum;
                $best = $i;
            }
        }
        $out = '';
        for ($j = $best; $j < $n && mb_strlen($out) + mb_strlen($paras[$j]) <= $maxChars; $j++) {
            $out .= $paras[$j]."\n";
        }

        return $out !== '' ? trim($out) : mb_substr($text, 0, $maxChars);
    }

    /**
     * @return array<string, mixed>
     */
    public function finalize(ResearchDossier $d): array
    {
        $questions = ResearchQuestion::query()->where('research_dossier_id', $d->id)->get();
        $answered = EvidenceItem::query()->where('research_dossier_id', $d->id)->whereNotNull('research_question_id')->distinct()->pluck('research_question_id')->all();
        foreach ($questions as $q) {
            $q->forceFill(['status' => in_array($q->id, $answered, true) ? 'answered' : 'unanswered'])->save();
        }
        $sources = EvidenceSource::query()->where('research_dossier_id', $d->id)->get();
        $usable = $sources->filter(fn (EvidenceSource $s) => $s->retrieval_status === 'fetched')->count();
        $evidence = EvidenceItem::query()->where('research_dossier_id', $d->id)->count();
        $warnings = [];
        if ($evidence === 0) {
            $warnings[] = 'no_verified_evidence';
        }
        $unanswered = $questions->count() - count($answered);
        $d->forceFill([
            'status' => $evidence > 0 ? 'complete' : 'partial',
            'questions_count' => $questions->count(), 'sources_count' => $sources->count(), 'usable_sources_count' => $usable, 'evidence_count' => $evidence,
            'summary' => "{$sources->count()} nguồn ứng viên, {$usable} nguồn truy xuất thành công, {$evidence} đoạn bằng chứng nguyên văn, "
                .count($answered)."/{$questions->count()} câu hỏi có bằng chứng".($unanswered > 0 ? " ({$unanswered} câu chưa có bằng chứng)." : '.'),
            'completed_at' => now(),
        ])->save();
        $this->warn($d, $warnings);

        return ['status' => $d->status, 'sources' => $sources->count(), 'usable' => $usable, 'evidence' => $evidence, 'unanswered' => $unanswered];
    }

    /**
     * Why the dossier is too thin to write from, and what the user can do —
     * shown on the article instead of writing an empty article.
     */
    public function shortfall(Article $a, ResearchDossier $d, int $min): string
    {
        $sources = EvidenceSource::query()->where('research_dossier_id', $d->id)->get(['discovered_via', 'retrieval_status']);
        $fetched = $sources->where('retrieval_status', 'fetched')->count();
        $failed = $sources->count() - $fetched;
        $evidence = EvidenceItem::query()->where('research_dossier_id', $d->id)->count();
        $userUrls = count((array) $a->setting('source_urls', []));

        $parts = [__('writer.shortfall.summary', ['sources' => $sources->count(), 'fetched' => $fetched, 'evidence' => $evidence, 'min' => $min])];
        if ($failed > 0) {
            $parts[] = __('writer.shortfall.failed', ['n' => $failed]);
        }
        if (! $this->researchSettings->enabled('evidence_fetch')) {
            $parts[] = __('writer.shortfall.retrieval_off');
        }
        if (! (bool) $a->setting('ai_source_discovery', true)) {
            $parts[] = __('writer.shortfall.discovery_off');
        } elseif (! (bool) $this->settings->get('web_search_enabled', $a->workspace_id)) {
            $parts[] = __('writer.shortfall.search_disabled');
        } elseif (! (bool) $a->setting('allow_web_search', false)) {
            $parts[] = __('writer.shortfall.search_off');
        } elseif (! $this->ai->canSearch()) {
            $parts[] = __('writer.shortfall.search_unavailable');
        }
        $parts[] = $userUrls === 0 ? __('writer.shortfall.add_urls') : __('writer.shortfall.add_more_urls', ['n' => $userUrls]);

        return mb_substr(implode(' ', $parts), 0, 1000);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function warn(ResearchDossier $d, array $warnings): void
    {
        if ($warnings === []) {
            return;
        }
        $d->forceFill(['warnings' => array_values(array_unique([...($d->warnings ?? []), ...$warnings]))])->save();
    }
}
