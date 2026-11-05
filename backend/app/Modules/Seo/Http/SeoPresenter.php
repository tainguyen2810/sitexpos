<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Http;

use SiteXPost\Modules\Seo\Domain\SeoAuditIssue;
use SiteXPost\Modules\Seo\Domain\SeoAuditRun;
use SiteXPost\Modules\Seo\Domain\SeoLinkSuggestion;
use SiteXPost\Modules\Seo\Domain\SeoMetadata;

final class SeoPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function metadata(SeoMetadata $m): array
    {
        return $m->only(['id', 'article_id', 'site_id', 'seo_title', 'meta_description', 'slug', 'canonical_url', 'focus_keyword', 'secondary_keywords',
            'og_title', 'og_description', 'social_image_asset_id', 'robots_noindex', 'robots_nofollow', 'schema_types', 'manual_fields', 'source', 'lock_version'])
            + ['updated_at' => $m->updated_at?->toIso8601ZuluString()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(SeoAuditRun $r, bool $withIssues = false): array
    {
        $out = $r->only(['id', 'article_id', 'site_id', 'article_version_id', 'rules_version', 'status', 'score', 'pass_count', 'warning_count', 'error_count',
            'blocked_count', 'links_checked', 'summary', 'is_demo']) + ['created_at' => $r->created_at?->toIso8601ZuluString(),
                'disclaimer' => __('seo.labels.disclaimer')];
        if ($withIssues) {
            $out['issues'] = $r->issues()->get()->map(fn (SeoAuditIssue $i) => $i->only(['id', 'rule', 'category', 'severity', 'weight', 'points', 'message', 'explanation', 'fix', 'context']))->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function link(SeoLinkSuggestion $s): array
    {
        return $s->only(['id', 'article_id', 'target_wp_post_id', 'target_post_type', 'target_url', 'target_title', 'anchor_text', 'section_key',
            'paragraph_index', 'relevance', 'signals', 'reason', 'status']) + ['verified_at' => $s->verified_at?->toIso8601ZuluString()];
    }
}
