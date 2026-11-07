<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Writer\Support\SourceFilter;

final class UpdateWriterSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $int = fn (int $min, int $max): array => ['sometimes', 'integer', "min:{$min}", "max:{$max}"];

        return [
            'max_articles_per_month' => $int(0, 100000),
            'max_concurrent_workflows' => $int(1, 50),
            'max_batch_size' => $int(1, 100),
            'max_batch_concurrency' => $int(1, 10),
            'default_article_budget_micro_usd' => $int(50_000, 100_000_000),
            'max_article_budget_micro_usd' => $int(50_000, 100_000_000),
            'autonomous_enabled' => ['sometimes', 'boolean'],
            'autonomous_articles_per_day' => $int(0, 100),
            'max_sources_basic' => $int(1, 50),
            'max_sources_standard' => $int(1, 50),
            'min_evidence_items' => $int(0, 50),
            'max_sources_deep' => $int(1, 50),
            'source_cache_hours' => $int(1, 720),
            'max_source_chars_for_ai' => $int(1000, 15000),
            'max_revision_rounds' => $int(0, 5),
            'high_risk_min_reliability' => $int(0, 100),
            'outdated_after_months' => $int(1, 120),
            'min_quality_score_for_approval' => $int(0, 100),
            'require_ack_for_high_risk' => ['sometimes', 'boolean'],
            'auto_approve_enabled' => ['sometimes', 'boolean'],
            'web_search_enabled' => ['sometimes', 'boolean'],
            'serp_discovery_enabled' => ['sometimes', 'boolean'],
            'serp_results' => $int(1, 10),
            'blocked_source_domains' => ['sometimes', 'array', 'max:500'],
            'blocked_source_domains.*' => ['string', 'max:253', function (string $attr, mixed $value, \Closure $fail): void {
                if (! is_string($value) || SourceFilter::normalizeDomain($value) === null) {
                    $fail(__('writer.errors.invalid_domain'));
                }
            }],
            'high_risk_min_independent_sources' => $int(1, 3),
            'auto_approve_min_quality' => $int(0, 100),
            'abuse_articles_per_hour' => $int(1, 10000),
            'abuse_cost_per_day_micro_usd' => $int(0, 10_000_000_000),
            'abuse_failed_ratio_pct' => $int(1, 100),
        ];
    }
}
