<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Actions;

use Illuminate\Http\UploadedFile;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Media\Domain\ImagePlacement;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Jobs\MediaJob;
use SiteXPost\Modules\Media\Jobs\ProcessMediaAsset;
use SiteXPost\Modules\Media\Support\ImageProcessor;
use SiteXPost\Modules\Media\Support\MediaLimits;
use SiteXPost\Modules\Media\Support\MediaPipeline;
use SiteXPost\Modules\Media\Support\MediaRejected;
use SiteXPost\Modules\Media\Support\MediaStorage;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Upload (validated synchronously, processed in the `media` queue), edit
 * metadata, derive a cropped / resized copy, delete.
 */
final class ManageAssets
{
    public function __construct(
        private readonly ImageProcessor $images,
        private readonly MediaStorage $storage,
        private readonly MediaLimits $limits,
        private readonly MediaPipeline $pipeline,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * @param  array{site_id?: string|null, title?: string|null, alt_text?: string|null, caption?: string|null, license: string, attribution?: string|null}  $meta
     * @return array{asset: MediaAsset, job_run: JobRun}
     *
     * @throws MediaRejected
     */
    public function upload(string $workspaceId, UploadedFile $file, array $meta, ?string $userId): array
    {
        if (! empty($meta['site_id']) && $this->sites->find((string) $meta['site_id']) === null) {
            throw new MediaRejected('site_not_found');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $name = $file->getClientOriginalName();
        // Cheap checks before anything is stored: real type, extension, size, dimensions, script markers.
        $info = $this->images->inspect($bytes, $name);
        $this->limits->assertCanUpload($workspaceId, $info['bytes']);
        if ($meta['license'] === 'licensed_stock' && trim((string) ($meta['attribution'] ?? '')) === '') {
            throw new MediaRejected('attribution_required');
        }

        $asset = new MediaAsset;
        $asset->forceFill([
            'site_id' => $meta['site_id'] ?? null, 'source_type' => 'upload', 'original_filename' => mb_substr(basename($name), 0, 190),
            'mime' => $info['mime'], 'width' => $info['width'], 'height' => $info['height'], 'bytes' => $info['bytes'], 'sha256' => $info['sha256'],
            'license' => $meta['license'], 'attribution' => $meta['attribution'] ?? null, 'title' => $meta['title'] ?? pathinfo($name, PATHINFO_FILENAME),
            'alt_text' => $meta['alt_text'] ?? null, 'caption' => $meta['caption'] ?? null, 'status' => 'processing', 'created_by' => $userId,
        ])->save();
        $incoming = 'media-incoming/'.$workspaceId.'/'.$asset->id;
        $this->storage->put($incoming, $bytes);
        $run = MediaJob::startRun('media.process', 'media_asset', $asset->id, $userId);
        ProcessMediaAsset::dispatch($asset->id, $incoming, $run->id);
        Audit::record('media.uploaded', $asset, ['license' => $asset->license, 'bytes' => $asset->bytes]);

        return ['asset' => $asset->refresh(), 'job_run' => $run->refresh()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MediaAsset $asset, array $data): MediaAsset
    {
        $fill = array_intersect_key($data, array_flip(['title', 'alt_text', 'caption', 'attribution', 'license']));
        if ($asset->is_ai_generated) {
            unset($fill['license']); // AI images stay labelled as AI
        }
        if (in_array($asset->source_type, ['wordpress', 'woocommerce'], true)) {
            unset($fill['license']);
        }
        if (($fill['license'] ?? $asset->license) === 'licensed_stock' && trim((string) ($fill['attribution'] ?? $asset->attribution)) === '') {
            throw new MediaRejected('attribution_required');
        }
        $asset->forceFill($fill)->save();
        Audit::record('media.updated', $asset, ['fields' => array_keys($fill)]);

        return $asset;
    }

    /**
     * Crop to a ratio / box or resize: creates a NEW asset (the original is kept).
     *
     * @param  array{operation: string, ratio?: string|null, box?: array{x: int, y: int, width: int, height: int}|null, max_width?: int|null}  $op
     */
    public function derive(MediaAsset $asset, array $op, ?string $userId): MediaAsset
    {
        $src = $this->pipeline->bytesFor($asset);
        if ($src === null) {
            throw new MediaRejected('remote_asset_not_editable');
        }
        $img = $this->images->decode($src['bytes'], $src['mime']);
        $img = $op['operation'] === 'crop'
            ? $this->images->crop($img, $op['ratio'] ?? null, $op['box'] ?? null)
            : $this->images->fit($img, (int) ($op['max_width'] ?? 1200), (int) ($op['max_width'] ?? 1200));
        $enc = $this->images->encode($img, ImageProcessor::formatFor($src['mime']), 90);

        $copy = new MediaAsset;
        $copy->forceFill([
            'site_id' => $asset->site_id, 'source_type' => $asset->source_type === 'ai' ? 'ai' : 'upload', 'original_filename' => $asset->original_filename,
            'license' => $asset->license, 'attribution' => $asset->attribution, 'is_ai_generated' => $asset->is_ai_generated,
            'ai_provider' => $asset->ai_provider, 'ai_model' => $asset->ai_model, 'ai_prompt' => $asset->ai_prompt,
            'title' => mb_substr(($asset->title ?? 'Ảnh').' ('.($op['operation'] === 'crop' ? 'cắt '.($op['ratio'] ?? 'tùy chỉnh') : 'thu nhỏ').')', 0, 200),
            'alt_text' => $asset->alt_text, 'caption' => $asset->caption, 'status' => 'processing', 'created_by' => $userId,
            'metadata' => ['derived_from' => $asset->id, 'operation' => $op],
        ])->save();
        $this->pipeline->ingest($copy, $enc['bytes'], 'derived.'.$enc['ext']);
        Audit::record('media.derived', $copy, ['from' => $asset->id, 'operation' => $op['operation']]);

        return $copy->refresh();
    }

    public function delete(MediaAsset $asset): void
    {
        ImagePlacement::query()->where('media_asset_id', $asset->id)->update(['media_asset_id' => null, 'status' => 'suggested', 'updated_at' => now()]);
        $this->storage->deleteAsset($asset->workspace_id, $asset->id);
        Audit::record('media.deleted', $asset, ['source_type' => $asset->source_type]);
        $asset->delete();
    }
}
