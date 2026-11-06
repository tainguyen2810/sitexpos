<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Engine;

use SiteXPost\Modules\Agent\Domain\AgentMemory;

/**
 * Deterministic candidate generation from observations. Every candidate is
 * a (tool, input) pair built by the server from tenant rows; the optional
 * AI advisor may only re-order / drop candidates, never add tools or edit
 * inputs.
 */
final class Planner
{
    /** Opportunity rules whose first step is an SEO audit of the article. */
    private const AUDIT_RULES = ['missing_metadata', 'duplicate_title_meta', 'broken_internal_links'];

    /**
     * @param  array<string, mixed>  $obs  SeoInsights::observations()
     * @return list<array{key: string, tool: string, input: array<string, mixed>, reason: string, evidence: array<string, mixed>, priority: int}>
     */
    public function candidates(string $siteId, string $kind, array $obs): array
    {
        $out = [];
        $blocked = $this->rejectedArticles($siteId);

        // Human-approved refreshed content waiting for its WordPress update.
        foreach ($obs['awaiting_publish'] ?? [] as $j) {
            $out[] = ['key' => 'publish:'.$j['id'], 'tool' => 'publish_via_workflow', 'input' => ['refresh_job_id' => $j['id'], 'article_id' => $j['article_id']],
                'reason' => 'Nội dung làm mới đã được người duyệt, đang chờ bước cập nhật WordPress.', 'evidence' => ['refresh_job_id' => $j['id'], 'approved_by' => $j['approved_by']], 'priority' => 90];
        }
        if ($kind !== 'daily_monitoring') {
            foreach ($obs['recommendations'] ?? [] as $r) {
                if (! $r['refresh'] || $r['article_id'] === null || isset($blocked[$r['article_id']])) {
                    continue;
                }
                $out[] = ['key' => 'refresh:'.$r['article_id'], 'tool' => 'create_refresh_brief', 'input' => ['article_id' => $r['article_id'], 'recommendation_id' => $r['id']],
                    'reason' => 'Đề xuất "'.$r['type'].'" cho bài này: chuẩn bị brief và bản nháp làm mới để người duyệt.', 'evidence' => ['recommendation_id' => $r['id'], 'opportunity_id' => $r['opportunity_id']],
                    'priority' => (int) $r['priority']];
            }
        }
        $withRec = array_flip(array_filter(array_column($obs['recommendations'] ?? [], 'opportunity_id')));
        foreach ($obs['opportunities'] ?? [] as $o) {
            if ($o['article_id'] !== null && in_array($o['rule'], self::AUDIT_RULES, true)) {
                $out[] = ['key' => 'audit:'.$o['article_id'], 'tool' => 'run_seo_audit', 'input' => ['article_id' => $o['article_id']],
                    'reason' => 'Opportunity "'.$o['rule'].'": chạy SEO Audit để xác nhận vấn đề.', 'evidence' => ['opportunity_id' => $o['id']], 'priority' => 60];
            }
            if (! isset($withRec[$o['id']]) && in_array($o['severity'], ['high', 'critical'], true) && $o['recommended_action'] === 'investigate') {
                $out[] = ['key' => 'proposal:'.$o['id'], 'tool' => 'create_proposal',
                    'input' => ['opportunity_id' => $o['id'], 'article_id' => $o['article_id'], 'type' => 'refresh_content', 'target_label' => (string) ($o['target_label'] ?? $o['rule'])],
                    'reason' => 'Opportunity mức '.$o['severity'].' chưa có đề xuất: tạo đề xuất để người xem xét.', 'evidence' => ['opportunity_id' => $o['id'], 'confidence' => $o['confidence']], 'priority' => 50];
            }
        }
        // Dedupe by key, highest priority first; stable by key.
        $uniq = [];
        foreach ($out as $c) {
            if (! isset($uniq[$c['key']]) || $uniq[$c['key']]['priority'] < $c['priority']) {
                $uniq[$c['key']] = $c;
            }
        }
        $list = array_values($uniq);
        usort($list, fn ($a, $b) => [$b['priority'], $a['key']] <=> [$a['priority'], $b['key']]);

        return $list;
    }

    /**
     * Memory in use: articles whose agent refresh a person rejected in the
     * last 30 days are not proposed again.
     *
     * @return array<string, true>
     */
    private function rejectedArticles(string $siteId): array
    {
        $ids = AgentMemory::query()->where('site_id', $siteId)->where('kind', 'user_feedback')->where('verified', true)
            ->where('created_at', '>=', now()->subDays(30))->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('source_ref')->all();
        $out = [];
        foreach ($ids as $ref) {
            if (is_string($ref) && str_starts_with($ref, 'article:')) {
                $out[substr($ref, 8)] = true;
            }
        }

        return $out;
    }
}
