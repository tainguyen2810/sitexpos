<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Support;

/**
 * Maps an AI task key (Phase 3 registry) to a cost-accounting category and
 * to the plan feature it needs.
 */
final class CostCategory
{
    public const CATEGORIES = ['research', 'writing', 'fact_check', 'image', 'other'];

    public static function of(string $taskKey): string
    {
        return match (true) {
            str_starts_with($taskKey, 'media.') => 'image',
            in_array($taskKey, ['verification.claims', 'writer.claims'], true) => 'fact_check',
            str_starts_with($taskKey, 'research.') || str_starts_with($taskKey, 'keywords.') || str_starts_with($taskKey, 'topics.')
                || in_array($taskKey, ['writer.research_questions', 'writer.source_discovery', 'writer.source_analysis'], true) => 'research',
            str_starts_with($taskKey, 'writer.') || str_starts_with($taskKey, 'content.') || str_starts_with($taskKey, 'seo.') => 'writing',
            default => 'other',
        };
    }

    public static function feature(string $taskKey): ?string
    {
        return match (self::of($taskKey)) {
            'image' => 'ai_images',
            'research' => 'research',
            'writing', 'fact_check' => 'ai_writer',
            default => null,
        };
    }
}
