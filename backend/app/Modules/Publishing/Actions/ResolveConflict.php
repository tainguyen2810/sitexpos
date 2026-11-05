<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Support\PublishingStatus as S;
use SiteXPost\Modules\Sites\Contracts\WordPressGateway;

/**
 * Human resolution of a WordPress conflict. Nothing is overwritten
 * automatically:
 * - keep_wordpress: accept WordPress as the truth (mapping re-based on its
 *   current state), cancel the pending update;
 * - overwrite: content.publish only, explicit; the next attempt sends
 *   `force` + the expected content hash (the plugin still refuses if
 *   WordPress changed AGAIN since this decision);
 * - merge_manually: the editor merges in WordPress / Writer; pending update
 *   cancelled, mapping re-based;
 * - new_draft: the post is left untouched; a new mapping (new key) sends
 *   the approved content as a new WordPress draft;
 * - cancel_update: nothing changes, the update is cancelled.
 */
final class ResolveConflict
{
    public const RESOLUTIONS = ['keep_wordpress', 'overwrite', 'merge_manually', 'new_draft', 'cancel_update'];

    public function __construct(private readonly WordPressGateway $wp, private readonly ManageJobs $jobs) {}

    public function handle(PublishingConflict $conflict, string $resolution, ?string $note, string $userId): PublishingConflict
    {
        if ($conflict->status !== 'open') {
            throw ApiException::conflict('conflict_already_resolved', __('publishing.codes.conflict_already_resolved'));
        }
        /** @var WordPressPostMapping $mapping */
        $mapping = WordPressPostMapping::query()->findOrFail($conflict->mapping_id);
        $job = $conflict->publishing_job_id !== null ? PublishingJob::query()->find($conflict->publishing_job_id) : null;
        $requeue = null;

        DB::transaction(function () use ($conflict, $resolution, $note, $userId, $mapping, $job, &$requeue): void {
            $types = (array) $conflict->types;
            switch ($resolution) {
                case 'overwrite':
                    if (in_array('post_missing', $types, true) || in_array('domain_changed', $types, true)) {
                        throw new ApiException(422, 'overwrite_not_possible', __('publishing.codes.overwrite_not_possible'));
                    }
                    // Re-base the expected state on what WordPress reported, so the plugin
                    // accepts exactly this overwrite and nothing that changes afterwards.
                    $wp = (array) $conflict->wp_state;
                    $mapping->forceFill(['last_synced_hash' => $wp['content_hash'] ?? $mapping->last_synced_hash, 'slug' => $wp['slug'] ?? $mapping->slug,
                        'last_seo_hash' => $wp['seo_hash'] ?? $mapping->last_seo_hash, 'last_revision_id' => $wp['revision_id'] ?? $mapping->last_revision_id])->save();
                    if ($job !== null && $job->status === S::UPDATE_PENDING) {
                        $job->forceFill(['payload_meta' => [...($job->payload_meta ?? []), 'force_overwrite' => true, 'overwrite_approved_by' => $userId]])->save();
                        $requeue = $job;
                    }
                    break;
                case 'keep_wordpress':
                case 'merge_manually':
                    $wp = (array) $conflict->wp_state;
                    if (! in_array('post_missing', $types, true)) {
                        $mapping->forceFill(['last_synced_hash' => $wp['content_hash'] ?? null, 'slug' => $wp['slug'] ?? $mapping->slug, 'last_seo_hash' => $wp['seo_hash'] ?? null,
                            'last_revision_id' => $wp['revision_id'] ?? null, 'last_modified_gmt' => $wp['modified_gmt'] ?? null,
                            'site_host' => (string) parse_url((string) ($wp['url'] ?? ''), PHP_URL_HOST) ?: $mapping->site_host])->save();
                    }
                    $this->cancel($job, $userId);
                    break;
                case 'new_draft':
                    $mapping->forceFill(['is_current' => false])->save();
                    if ($job !== null && in_array($job->status, [S::UPDATE_PENDING, S::FAILED], true)) {
                        $job->forceFill(['wp_target_status' => 'draft', 'mode' => 'wp_draft', 'mapping_id' => null])->save();
                        $requeue = $job;
                    }
                    break;
                case 'cancel_update':
                    $this->cancel($job, $userId);
                    break;
            }
            $conflict->forceFill(['status' => 'resolved', 'resolution' => $resolution, 'note' => $note !== null ? mb_substr($note, 0, 1000) : null,
                'resolved_by' => $userId, 'resolved_at' => now()])->save();
        });
        Audit::record('publishing.conflict_resolved', $conflict, ['resolution' => $resolution, 'types' => $conflict->types, 'job_id' => $job?->id], actorId: $userId);

        if ($requeue !== null) {
            $this->jobs->startPublishing($requeue);
        }

        return $conflict;
    }

    private function cancel(?PublishingJob $job, string $userId): void
    {
        if ($job !== null && ! in_array($job->status, S::TERMINAL, true)) {
            $job->forceFill(['status' => S::CANCELLED, 'active_key' => null, 'next_attempt_at' => null, 'blocked_reason' => null])->save();
        }
    }
}
