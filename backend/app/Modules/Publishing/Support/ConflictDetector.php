<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Support;

use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Domain\WordPressSyncState;

/**
 * Compares what WordPress reports (signed state endpoint) with what SiteX
 * last wrote. Never trusts an unsigned error body for this.
 */
final class ConflictDetector
{
    /**
     * @param  array<string, mixed>|null  $state  null = post missing on WordPress
     * @return list<string> conflict types
     */
    public static function types(WordPressPostMapping $m, ?array $state, string $currentHost): array
    {
        if ($state === null) {
            return ['post_missing'];
        }
        $types = [];
        if ($m->site_host !== $currentHost) {
            $types[] = 'domain_changed';
        }
        if ($m->last_synced_hash !== null && (string) ($state['content_hash'] ?? '') !== $m->last_synced_hash) {
            $types[] = 'manual_edit';
        }
        if ($m->slug !== null && (string) ($state['slug'] ?? '') !== '' && (string) $state['slug'] !== $m->slug) {
            $types[] = 'slug_changed';
        }
        if ($m->last_seo_hash !== null && (string) ($state['seo_hash'] ?? '') !== '' && (string) $state['seo_hash'] !== $m->last_seo_hash) {
            $types[] = 'seo_modified';
        }
        if ($types !== [] && $m->last_revision_id !== null && isset($state['revision_id']) && (int) $state['revision_id'] !== $m->last_revision_id) {
            $types[] = 'revision_changed';
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public static function record(WordPressPostMapping $m, ?array $state, string $syncState, array $details = []): WordPressSyncState
    {
        $row = new WordPressSyncState;
        $row->forceFill([
            'mapping_id' => $m->id, 'state' => $syncState, 'wp_status' => $state['status'] ?? null, 'wp_slug' => isset($state['slug']) ? mb_substr((string) $state['slug'], 0, 200) : null,
            'wp_content_hash' => $state['content_hash'] ?? null, 'wp_seo_hash' => $state['seo_hash'] ?? null,
            'wp_modified_gmt' => isset($state['modified_gmt']) ? mb_substr((string) $state['modified_gmt'], 0, 32) : null,
            'wp_revision_id' => isset($state['revision_id']) ? (int) $state['revision_id'] : null, 'details' => $details, 'checked_at' => now(),
        ])->save();
        $m->forceFill(['last_checked_at' => now()])->save();

        return $row;
    }

    /**
     * Opens (or refreshes) the single open conflict of a mapping.
     *
     * @param  list<string>  $types
     * @param  array<string, mixed>|null  $state
     */
    public static function open(WordPressPostMapping $m, ?string $jobId, array $types, ?array $state): PublishingConflict
    {
        $c = PublishingConflict::query()->where('mapping_id', $m->id)->where('status', 'open')->first() ?? new PublishingConflict;
        $c->forceFill([
            'site_id' => $m->site_id, 'article_id' => $m->article_id, 'mapping_id' => $m->id, 'publishing_job_id' => $jobId ?? $c->publishing_job_id,
            'types' => array_values(array_unique($types)), 'status' => 'open',
            'wp_state' => $state === null ? null : array_intersect_key($state, array_flip(['id', 'status', 'title', 'slug', 'url', 'modified_gmt', 'content_hash', 'written_hash', 'revision_id', 'seo', 'seo_hash', 'seo_adapter'])),
            'expected_state' => ['content_hash' => $m->last_synced_hash, 'slug' => $m->slug, 'seo_hash' => $m->last_seo_hash, 'site_host' => $m->site_host,
                'revision_id' => $m->last_revision_id, 'last_published_at' => $m->last_published_at?->toIso8601ZuluString()],
        ])->save();

        return $c;
    }
}
