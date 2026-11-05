<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;

/**
 * Unresolved-issue counters of the CURRENT version (used by the approval
 * gate, the library and the revision loop).
 */
final class ClaimStats
{
    /**
     * @return array{total: int, by_status: array<string, int>, unresolved_critical: int, unresolved_high: int, unchecked_sections: list<string>}
     */
    public static function compute(Article $article): array
    {
        $versionId = $article->current_version_id;
        if ($versionId === null) {
            return ['total' => 0, 'by_status' => [], 'unresolved_critical' => 0, 'unresolved_high' => 0, 'unchecked_sections' => []];
        }
        $claims = ArticleClaim::query()->where('article_version_id', $versionId)->get(['status', 'risk_level']);
        $by = $claims->countBy('status')->all();
        $unresolved = $claims->where('status', '!=', 'VERIFIED');
        $version = ArticleVersion::query()->find($versionId);
        $unchecked = collect($version?->section_hashes ?? [])->reject(fn ($s) => (bool) ($s['claims_checked'] ?? false))->pluck('key')->values()->all();

        return [
            'total' => $claims->count(),
            'by_status' => $by,
            'unresolved_critical' => $unresolved->where('risk_level', 'CRITICAL')->count(),
            'unresolved_high' => $unresolved->where('risk_level', 'HIGH')->count(),
            'unchecked_sections' => $unchecked,
        ];
    }

    public static function refresh(Article $article): void
    {
        $s = self::compute($article);
        Article::query()->whereKey($article->id)->update(['unresolved_critical' => $s['unresolved_critical'], 'unresolved_high' => $s['unresolved_high']]);
        $article->unresolved_critical = $s['unresolved_critical'];
        $article->unresolved_high = $s['unresolved_high'];
    }
}
