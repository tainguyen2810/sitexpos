<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaLimit;

/**
 * Effective media limits for a workspace: workspace override > global row >
 * config defaults. Checked before every upload / AI generation.
 */
final class MediaLimits
{
    /**
     * @return array{max_storage_bytes: int, max_uploads_per_day: int, max_ai_images_per_day: int, max_ai_cost_per_job_micro_usd: int, ai_images_enabled: bool}
     */
    public function for(string $workspaceId): array
    {
        $defaults = [
            'max_storage_bytes' => (int) config('sitex.media.max_storage_bytes', 2 * 1024 * 1024 * 1024),
            'max_uploads_per_day' => (int) config('sitex.media.max_uploads_per_day', 200),
            'max_ai_images_per_day' => (int) config('sitex.media.max_ai_images_per_day', 30),
            'max_ai_cost_per_job_micro_usd' => (int) config('sitex.media.max_ai_cost_per_job_micro_usd', 500_000),
            'ai_images_enabled' => true,
        ];
        foreach ([MediaLimit::query()->where('scope_key', 'global')->first(), MediaLimit::query()->where('scope_key', 'ws:'.$workspaceId)->first()] as $row) {
            if ($row === null) {
                continue;
            }
            foreach (array_keys($defaults) as $k) {
                $v = $row->getAttribute($k);
                if ($v !== null) {
                    $defaults[$k] = $k === 'ai_images_enabled' ? (bool) $v : (int) $v;
                }
            }
        }

        return $defaults;
    }

    /**
     * @throws MediaRejected
     */
    public function assertCanUpload(string $workspaceId, int $bytes): void
    {
        $l = $this->for($workspaceId);
        $used = (int) MediaAsset::query()->whereNotNull('storage_path')->sum('bytes');
        if ($used + $bytes > $l['max_storage_bytes']) {
            throw new MediaRejected('storage_quota_exceeded', '', ['used' => $used, 'limit' => $l['max_storage_bytes']]);
        }
        $today = MediaAsset::query()->where('source_type', 'upload')->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= $l['max_uploads_per_day']) {
            throw new MediaRejected('daily_upload_limit', '', ['limit' => $l['max_uploads_per_day']]);
        }
    }

    /**
     * @throws MediaRejected
     */
    public function assertCanGenerate(string $workspaceId, int $count): void
    {
        $l = $this->for($workspaceId);
        if (! $l['ai_images_enabled']) {
            throw new MediaRejected('ai_images_disabled');
        }
        $today = (int) ImageGenerationJob::query()->where('created_at', '>=', now()->startOfDay())
            ->whereIn('status', ['queued', 'running', 'succeeded'])->sum('image_count');
        if ($today + $count > $l['max_ai_images_per_day']) {
            throw new MediaRejected('daily_ai_image_limit', '', ['limit' => $l['max_ai_images_per_day'], 'used' => $today]);
        }
    }
}
