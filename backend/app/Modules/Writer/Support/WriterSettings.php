<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

use SiteXPost\Modules\Writer\Domain\WriterSetting;

/**
 * Super Admin writer policy: generation limits, quality policy, abuse
 * thresholds. Global row + optional per-workspace override (limits only).
 */
final class WriterSettings
{
    public const DEFAULTS = [
        // Generation limits
        'max_articles_per_month' => 100,
        'max_concurrent_workflows' => 3,
        'max_batch_size' => 20,
        'max_batch_concurrency' => 3,
        'default_article_budget_micro_usd' => 1_000_000,
        'max_article_budget_micro_usd' => 10_000_000,
        'autonomous_enabled' => false,
        'autonomous_articles_per_day' => 3,
        // Research
        'max_sources_basic' => 5,
        'max_sources_standard' => 10,
        'max_sources_deep' => 20,
        'source_cache_hours' => 72,
        'max_source_chars_for_ai' => 9000,
        // Below this many verbatim evidence excerpts the research stops with
        // "insufficient_evidence" instead of writing a hollow article (0 = off).
        'min_evidence_items' => 3,
        // Let the AI search the web for sources (discovery + follow-up lookup).
        // Per article it is still the article's own "allow_web_search" choice.
        'web_search_enabled' => true,
        // Fact-check & revision policy
        'max_revision_rounds' => 2,
        'high_risk_min_reliability' => 60,
        'outdated_after_months' => 24,
        // Approval policy
        'min_quality_score_for_approval' => 50,
        'require_ack_for_high_risk' => true,
        'auto_approve_enabled' => false,
        'auto_approve_min_quality' => 75,
        // Abuse detection
        'abuse_articles_per_hour' => 30,
        'abuse_cost_per_day_micro_usd' => 20_000_000,
        'abuse_failed_ratio_pct' => 60,
    ];

    /** Keys a per-workspace override may change. */
    public const WORKSPACE_KEYS = ['max_articles_per_month', 'max_concurrent_workflows', 'max_batch_size', 'max_batch_concurrency',
        'default_article_budget_micro_usd', 'max_article_budget_micro_usd', 'autonomous_enabled', 'autonomous_articles_per_day',
        'auto_approve_enabled', 'auto_approve_min_quality', 'web_search_enabled'];

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * @return array<string, mixed>
     */
    public function all(?string $workspaceId = null): array
    {
        $key = $workspaceId ?? '_global';
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $global = WriterSetting::query()->whereNull('workspace_id')->first();
        $values = array_merge(self::DEFAULTS, array_intersect_key($global->values ?? [], self::DEFAULTS));
        if ($workspaceId !== null) {
            $override = WriterSetting::query()->where('workspace_id', $workspaceId)->first();
            $values = array_merge($values, array_intersect_key($override->values ?? [], array_flip(self::WORKSPACE_KEYS)));
        }

        return $this->cache[$key] = $values;
    }

    public function get(string $key, ?string $workspaceId = null): mixed
    {
        return $this->all($workspaceId)[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function int(string $key, ?string $workspaceId = null): int
    {
        return (int) $this->get($key, $workspaceId);
    }

    public function maxSources(string $depth, ?string $workspaceId = null): int
    {
        return $this->int('max_sources_'.(in_array($depth, ['basic', 'standard', 'deep'], true) ? $depth : 'standard'), $workspaceId);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(?string $workspaceId, array $values, ?string $userId): WriterSetting
    {
        $allowed = $workspaceId === null ? self::DEFAULTS : array_flip(self::WORKSPACE_KEYS);
        $row = WriterSetting::query()->where('workspace_id', $workspaceId)->first() ?? new WriterSetting;
        $row->forceFill(['workspace_id' => $workspaceId, 'values' => array_intersect_key(array_merge($row->values ?? [], $values), $allowed), 'updated_by' => $userId])->save();
        $this->cache = [];

        return $row;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
