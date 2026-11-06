<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Actions;

use Illuminate\Database\QueryException;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Domain\ContentPerformanceSnapshot;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\SearchQueryPageMetric;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Jobs\AdvanceRefreshJob;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;
use SiteXPost\Modules\Analytics\Support\UrlNormalizer;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Contracts\Entitlements;
use SiteXPost\Modules\Publishing\Contracts\PublicationDirectory;
use SiteXPost\Modules\Publishing\Contracts\PublishRequests;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;
use SiteXPost\Modules\Writer\Contracts\ArticleRefresh;
use Throwable;

/**
 * Content Refresh Engine (CONTENT REFRESH ENGINE §10).
 *
 *  1 latest WordPress state  2 revision/hash check (manual edits → stop)
 *  3 GSC analysis  4 issues  5 research questions  6 refresh brief
 *  7 AI section proposals (Writer)  8 fact-check (Writer recheck)
 *  9 SEO audit (Phase 6)  10 diff  11 human approval  12 Publisher update
 *  13 before/after tracking (experiment)
 *
 * Nothing is written to WordPress outside the Publisher, and a post that
 * was edited outside SiteX is never overwritten.
 */
final class ContentRefresh
{
    public const ACTIVE = ['queued', 'checking_wordpress', 'analyzing', 'brief_ready', 'drafting', 'fact_checking', 'awaiting_approval', 'approved', 'seo_audit', 'awaiting_publish', 'publishing', 'monitoring'];

    public const TERMINAL = ['completed', 'conflict', 'failed', 'cancelled', 'seo_blocked'];

    public const MAX_SECTIONS = 3;

    public function __construct(
        private readonly ArticleCatalog $articles,
        private readonly ArticleRefresh $writer,
        private readonly PublicationDirectory $publications,
        private readonly PublishRequests $publisher,
        private readonly SeoGate $seo,
        private readonly TrackExperiments $experiments,
        private readonly TenantContext $context,
    ) {}

    public function create(string $articleId, ?string $recommendationId, string $userId, ?string $agentTaskId = null, ?string $idempotencyKey = null): ContentRefreshJob
    {
        $key = $idempotencyKey !== null ? 'refresh:'.$articleId.':'.mb_substr($idempotencyKey, 0, 80) : null;
        if ($key !== null && ($existing = ContentRefreshJob::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }
        $article = $this->articles->find($articleId) ?? throw ApiException::notFound();
        // An open refresh moves the article out of APPROVED; report the real reason.
        if (ContentRefreshJob::query()->where('active_key', $articleId)->exists()) {
            throw ApiException::conflict('refresh_in_progress', __('analytics.errors.refresh_in_progress'));
        }
        if ($article['status'] !== 'APPROVED') {
            throw ApiException::conflict('refresh_requires_approved_article', __('analytics.errors.refresh_requires_approved'));
        }
        if ($this->publications->current($articleId) === null) {
            throw ApiException::conflict('refresh_not_published', __('analytics.errors.refresh_not_published'));
        }
        app(Entitlements::class)->assertActive($this->context->workspaceId(), 'ai_writer');
        $rec = $recommendationId !== null ? SeoRecommendation::query()->find($recommendationId) : null;
        if ($recommendationId !== null && ($rec === null || $rec->article_id !== $articleId)) {
            throw ApiException::notFound();
        }
        $job = new ContentRefreshJob;
        $job->forceFill(['site_id' => $article['site_id'], 'article_id' => $articleId, 'recommendation_id' => $rec?->id, 'agent_task_id' => $agentTaskId,
            'status' => 'queued', 'active_key' => $articleId, 'requested_by' => $userId, 'idempotency_key' => $key,
            'estimated_cost_micro_usd' => AnalyticsConfig::ACTION_COST['refresh_content']]);
        try {
            $job->save();
        } catch (QueryException) {
            throw ApiException::conflict('refresh_in_progress', __('analytics.errors.refresh_in_progress'));
        }
        $rec?->forceFill(['status' => 'in_progress'])->save();
        Audit::record('analytics.refresh_created', $job, ['article_id' => $articleId, 'recommendation_id' => $rec?->id, 'agent_task_id' => $agentTaskId]);
        AdvanceRefreshJob::dispatch($job->id);

        return $job->refresh();
    }

    /**
     * Run every automatic step until the job waits for AI, fact-check or a human.
     */
    public function advance(ContentRefreshJob $job): ContentRefreshJob
    {
        for ($guard = 0; $guard < 8; $guard++) {
            $before = $job->status;
            try {
                match ($job->status) {
                    'queued', 'checking_wordpress' => $this->checkWordPress($job),
                    'analyzing' => $this->analyze($job),
                    'brief_ready' => $this->draft($job),
                    'drafting' => $this->collectDrafts($job),
                    'fact_checking' => $this->collectFactCheck($job),
                    'publishing' => $this->collectPublish($job),
                    default => null,
                };
            } catch (ApiException $e) {
                $this->fail($job, $e->errorCode, $e->getMessage());
            } catch (Throwable $e) {
                report($e);
                $this->fail($job, 'server_error', __('api.server_error'));
            }
            if ($job->status === $before) {
                break;
            }
        }

        return $job->refresh();
    }

    public function approve(ContentRefreshJob $job, string $userId, ?string $note): ContentRefreshJob
    {
        if ($job->status !== 'awaiting_approval' || $job->new_version_id === null) {
            throw ApiException::conflict('invalid_state_transition', __('analytics.errors.refresh_not_awaiting'));
        }
        // Re-check WordPress right before approval: never overwrite a manual edit.
        $check = $this->publications->remoteCheck($job->article_id);
        if ($check['reachable'] && $check['conflicts'] !== []) {
            $job->forceFill(['status' => 'conflict', 'active_key' => null, 'wp_check' => $check, 'error_code' => 'wordpress_changed'])->save();
            throw ApiException::conflict('wordpress_changed', __('analytics.errors.wordpress_changed'));
        }
        $this->writer->approve($job->article_id, $job->new_version_id, $userId, $note);
        $job->forceFill(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()])->save();
        Audit::record('analytics.refresh_approved', $job, ['version_id' => $job->new_version_id]);

        $audit = $this->seo->audit($job->article_id, $userId);
        $job->forceFill(['seo_audit' => ['id' => $audit['id'], 'score' => $audit['score'], 'blocked' => $audit['blocked'], 'errors' => $audit['errors'], 'warnings' => $audit['warnings']]])->save();
        if ($audit['blocked'] > 0) {
            $job->forceFill(['status' => 'seo_blocked', 'active_key' => null, 'error_code' => 'seo_audit_blocked'])->save();

            return $job;
        }
        $pj = $this->publisher->requestUpdate($job->article_id, $userId, 'refresh:'.$job->id, 'analytics');
        $job->forceFill(['status' => 'awaiting_publish', 'publishing_job_id' => $pj['id']])->save();

        return $job;
    }

    /** Requires content.publish (checked by the controller / agent policy). */
    public function publish(ContentRefreshJob $job, string $userId): ContentRefreshJob
    {
        if ($job->status !== 'awaiting_publish' || $job->publishing_job_id === null) {
            throw ApiException::conflict('invalid_state_transition', __('analytics.errors.refresh_not_awaiting'));
        }
        $this->publisher->approve($job->publishing_job_id, $userId);
        $job->forceFill(['status' => 'publishing'])->save();
        Audit::record('analytics.refresh_publish_approved', $job, ['publishing_job_id' => $job->publishing_job_id]);

        return $this->advance($job);
    }

    public function cancel(ContentRefreshJob $job, string $userId): ContentRefreshJob
    {
        if (in_array($job->status, self::TERMINAL, true) || in_array($job->status, ['publishing', 'monitoring'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('analytics.errors.refresh_not_cancellable'));
        }
        $job->forceFill(['status' => 'cancelled', 'active_key' => null])->save();
        if ($job->recommendation_id !== null) {
            SeoRecommendation::query()->whereKey($job->recommendation_id)->where('status', 'in_progress')->update(['status' => 'approved']);
        }
        Audit::record('analytics.refresh_cancelled', $job, [], actorId: $userId);

        return $job;
    }

    private function checkWordPress(ContentRefreshJob $job): void
    {
        $job->forceFill(['status' => 'checking_wordpress'])->save();
        $check = $this->publications->remoteCheck($job->article_id);
        $state = $this->writer->state($job->article_id);
        $pub = $this->publications->current($job->article_id);
        $job->forceFill(['wp_check' => $check + ['published_version_id' => $pub['last_published_version_id'] ?? null]])->save();
        if (! $check['reachable']) {
            $this->fail($job, 'wordpress_unreachable', __('analytics.errors.wordpress_unreachable'));

            return;
        }
        if ($check['conflicts'] !== []) {
            $job->forceFill(['status' => 'conflict', 'active_key' => null, 'error_code' => 'wordpress_changed', 'error_message' => implode(', ', $check['conflicts'])])->save();
            Audit::record('analytics.refresh_conflict', $job, ['conflicts' => $check['conflicts']]);

            return;
        }
        if ($state === null || $state['approved_version_id'] === null || ($pub !== null && $pub['last_published_version_id'] !== null && $pub['last_published_version_id'] !== $state['approved_version_id'])) {
            $this->fail($job, 'version_mismatch', __('analytics.errors.version_mismatch'));

            return;
        }
        $v = $this->writer->version((string) $state['approved_version_id']);
        $job->forceFill(['status' => 'analyzing', 'base_version_id' => $state['approved_version_id'], 'base_content_hash' => $v['content_hash'] ?? null])->save();
    }

    private function analyze(ContentRefreshJob $job): void
    {
        $pub = $this->publications->current($job->article_id);
        $hash = $pub !== null && $pub['url'] !== null ? UrlNormalizer::hash($pub['url']) : null;
        $snap = $hash !== null ? ContentPerformanceSnapshot::query()->where('site_id', $job->site_id)->where('page_hash', $hash)->orderByDesc('period_end')->first() : null;
        $period = SearchQueryPageMetric::query()->where('site_id', $job->site_id)->max('period_end');
        $queries = $hash !== null && $period !== null
            ? SearchQueryPageMetric::query()->where('site_id', $job->site_id)->where('page_hash', $hash)->where('period_end', $period)->orderByDesc('impressions')->limit(15)
                ->get(['query', 'clicks', 'impressions', 'position'])->map(fn ($r) => ['query' => $r->query, 'clicks' => $r->clicks, 'impressions' => $r->impressions, 'position' => (float) $r->position])->all()
            : [];
        $opps = SeoOpportunity::query()->where('site_id', $job->site_id)->where('article_id', $job->article_id)->whereIn('status', ['open', 'accepted'])
            ->get(['id', 'rule_code', 'detection_rule', 'evidence'])->map(fn ($o) => ['id' => $o->id, 'rule' => $o->rule_code, 'detection_rule' => $o->detection_rule])->all();
        $analysis = [
            'page_url' => $pub['url'] ?? null,
            'performance' => $snap === null ? null : ['period_end' => $snap->period_end, 'classification' => $snap->classification, 'labels' => $snap->labels,
                'clicks' => $snap->clicks, 'impressions' => $snap->impressions, 'position' => $snap->position, 'prev_clicks' => $snap->prev_clicks, 'reasons' => $snap->reasons],
            'top_queries' => $queries, 'issues' => $opps,
            'data_note' => 'Dữ liệu Search Console có độ trễ và có thể ẩn một phần truy vấn; không phải toàn bộ lượt tìm kiếm thực tế.',
        ];
        $sections = $this->writer->sections($job->article_id);
        $rec = $job->recommendation_id !== null ? SeoRecommendation::query()->find($job->recommendation_id) : null;
        $type = $rec->type ?? 'refresh_content';
        $current = $this->articles->approvedContent($job->article_id);
        $text = mb_strtolower(strip_tags((string) ($current['html'] ?? '')));
        $targets = $this->targets($sections, $type, (string) ($current['html'] ?? ''));
        $uncovered = array_values(array_filter($queries, function ($q) use ($text) {
            $words = array_filter(preg_split('/\s+/u', mb_strtolower($q['query'])) ?: [], fn ($w) => mb_strlen($w) >= 3);

            return $words !== [] && count(array_filter($words, fn ($w) => str_contains($text, $w))) / count($words) < 0.5;
        }));
        $brief = [
            'goal' => $type, 'target_sections' => $targets,
            'questions_to_research' => array_map(fn ($q) => 'Người đọc tìm "'.$q['query'].'" — bài đã trả lời chưa? Cần nguồn kiểm chứng nào?', array_slice($uncovered, 0, 5)),
            'keep' => 'Giữ nguyên dữ kiện đã kiểm chứng; mọi dữ kiện mới phải có nguồn và qua fact-check.',
            'limits' => 'AI chỉ đề xuất viết lại/mở rộng theo từng section (Writer). Phần mới hoàn toàn cần biên tập viên thêm thủ công.',
        ];
        $job->forceFill(['analysis' => $analysis, 'brief' => $brief, 'status' => 'brief_ready'])->save();
    }

    /**
     * @param  list<array{key: string, heading: string, words: int}>  $sections
     * @return list<array{key: string, heading: string, action: string, reason: string}>
     */
    private function targets(array $sections, string $type, string $html): array
    {
        $out = [];
        if ($type === 'update_outdated_facts') {
            $limit = (int) now()->format('Y') - (int) AnalyticsConfig::THRESHOLDS['outdated_years'];
            foreach (preg_split('#(?=<h2\b)#i', $html) ?: [] as $part) {
                preg_match('#<h2[^>]*>(.*?)</h2>#is', $part, $h);
                preg_match_all('/\b(19|20)\d{2}\b/', strip_tags($part), $y);
                if (array_filter(array_map('intval', $y[0]), fn ($v) => $v <= $limit && $v >= 1990) === []) {
                    continue;
                }
                $heading = trim(strip_tags($h[1] ?? ''));
                foreach ($sections as $s) {
                    if (($heading === '' && $s['key'] === 'intro') || ($heading !== '' && mb_strtolower($s['heading']) === mb_strtolower($heading))) {
                        $out[] = ['key' => $s['key'], 'heading' => $s['heading'], 'action' => 'regenerate_section', 'reason' => 'Có mốc năm cũ cần kiểm tra lại.'];
                    }
                }
            }
        }
        if ($out === []) {
            $candidates = array_values(array_filter($sections, fn ($s) => $s['key'] !== 'intro' && ! str_contains(mb_strtolower($s['heading']), 'câu hỏi')));
            usort($candidates, fn ($a, $b) => $a['words'] <=> $b['words']);
            foreach (array_slice($candidates, 0, 2) as $s) {
                $out[] = ['key' => $s['key'], 'heading' => $s['heading'], 'action' => 'expand', 'reason' => 'Section ngắn nhất — mở rộng bằng giải thích/ví dụ, không thêm số liệu mới ngoài nguồn.'];
            }
        }

        return array_slice($out, 0, self::MAX_SECTIONS);
    }

    private function draft(ContentRefreshJob $job): void
    {
        $user = (string) ($job->requested_by ?? '');
        $ids = [];
        foreach ((array) ($job->brief['target_sections'] ?? []) as $i => $t) {
            $ids[] = $this->writer->proposeSectionRewrite($job->article_id, (string) $t['key'], (string) $t['action'], $user, 'refresh:'.$job->id.':'.$i);
        }
        if ($ids === []) {
            $this->fail($job, 'no_target_sections', __('analytics.errors.no_target_sections'));

            return;
        }
        $job->forceFill(['revision_ids' => $ids, 'status' => 'drafting'])->save();
    }

    private function collectDrafts(ContentRefreshJob $job): void
    {
        $revs = array_map(fn ($id) => $this->writer->revision($id), (array) $job->revision_ids);
        if (array_filter($revs, fn ($r) => $r !== null && in_array($r['status'], ['queued', 'running'], true)) !== []) {
            return; // AI still working
        }
        $proposed = array_values(array_filter(array_map(null, (array) $job->revision_ids, $revs), fn ($p) => $p[1] !== null && $p[1]['status'] === 'proposed'));
        if ($proposed === []) {
            $this->fail($job, 'ai_draft_failed', __('analytics.errors.ai_draft_failed'));

            return;
        }
        $version = null;
        foreach ($proposed as [$id]) {
            $version = $this->writer->applyRevision($job->article_id, (string) $id, (string) $job->requested_by);
        }
        $before = $this->writer->version((string) $job->base_version_id);
        $after = $this->writer->version((string) $version);
        $job->forceFill(['new_version_id' => $version, 'diff' => self::diff((string) ($before['html'] ?? ''), (string) ($after['html'] ?? ''))])->save();
        $this->writer->startRecheck($job->article_id, (string) $job->requested_by, 'refresh:'.$job->id);
        $job->forceFill(['status' => 'fact_checking'])->save();
    }

    private function collectFactCheck(ContentRefreshJob $job): void
    {
        $state = $this->writer->state($job->article_id);
        if ($state === null || $state['workflow_active']) {
            return;
        }
        if ($state['current_version_id'] !== null && $state['current_version_id'] !== $job->new_version_id) {
            // The recheck may have produced a bounded auto-fix version: review that one.
            $after = $this->writer->version((string) $state['current_version_id']);
            $before = $this->writer->version((string) $job->base_version_id);
            $job->forceFill(['new_version_id' => $state['current_version_id'], 'diff' => self::diff((string) ($before['html'] ?? ''), (string) ($after['html'] ?? ''))]);
        }
        $job->forceFill(['status' => 'awaiting_approval', 'fact_check' => ['article_status' => $state['status'], 'unresolved_critical' => $state['unresolved_critical'],
            'unresolved_high' => $state['unresolved_high'], 'checked_at' => now()->toIso8601ZuluString()]])->save();
    }

    private function collectPublish(ContentRefreshJob $job): void
    {
        $pj = $job->publishing_job_id !== null ? $this->publisher->job($job->publishing_job_id) : null;
        if ($pj === null) {
            return;
        }
        if (in_array($pj['status'], ['FAILED', 'CANCELLED', 'CONFLICT'], true)) {
            $this->fail($job, 'publish_'.strtolower($pj['status']), (string) ($pj['blocked_reason'] ?? $pj['status']));

            return;
        }
        if ($pj['status'] !== 'PUBLISHED') {
            return;
        }
        $pub = $this->publications->current($job->article_id);
        $exp = $this->experiments->start($job->site_id, $job->article_id, (string) ($pub['url'] ?? ''), 'content_refresh', $job->id, now(), $job->approved_by);
        $job->forceFill(['status' => 'monitoring', 'experiment_id' => $exp->id, 'active_key' => null])->save();
        if ($job->recommendation_id !== null) {
            SeoRecommendation::query()->whereKey($job->recommendation_id)->update(['status' => 'applied', 'applied_at' => now()]);
        }
    }

    private function fail(ContentRefreshJob $job, string $code, string $message): void
    {
        $job->forceFill(['status' => 'failed', 'active_key' => null, 'error_code' => mb_substr($code, 0, 64), 'error_message' => mb_substr($message, 0, 500)])->save();
    }

    /**
     * Section-level diff (heading → before / after text).
     *
     * @return array{sections: list<array{heading: string, changed: bool, before: string, after: string}>, changed: int}
     */
    public static function diff(string $before, string $after): array
    {
        $split = function (string $html): array {
            $out = [];
            foreach (preg_split('#(?=<h2\b)#i', (string) preg_replace('#^\s*<h1\b[^>]*>.*?</h1>#is', '', $html)) ?: [] as $part) {
                preg_match('#<h2[^>]*>(.*?)</h2>#is', $part, $h);
                $out[trim(strip_tags($h[1] ?? '')) ?: '(mở đầu)'] = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($part))) ?? '');
            }

            return $out;
        };
        $b = $split($before);
        $a = $split($after);
        $sections = [];
        $changed = 0;
        foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $heading) {
            $x = $b[$heading] ?? '';
            $y = $a[$heading] ?? '';
            $sections[] = ['heading' => (string) $heading, 'changed' => $x !== $y, 'before' => mb_substr($x, 0, 3000), 'after' => mb_substr($y, 0, 3000)];
            $changed += $x !== $y ? 1 : 0;
        }

        return ['sections' => $sections, 'changed' => $changed];
    }
}
