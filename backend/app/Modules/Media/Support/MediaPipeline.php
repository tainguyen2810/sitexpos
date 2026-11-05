<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaVersion;

/**
 * Turns raw bytes into a ready asset: validate → re-encode the original
 * (metadata stripped) → optimized (≤ 1600 px) → WebP → AVIF (if supported)
 * → 400 px thumbnail, then exact (sha256) and near (dHash) duplicate
 * detection inside the workspace. Runs inside queue jobs.
 */
final class MediaPipeline
{
    public function __construct(private readonly ImageProcessor $images, private readonly MediaStorage $storage) {}

    /**
     * @throws MediaRejected
     */
    public function ingest(MediaAsset $asset, string $bytes, ?string $filename): MediaAsset
    {
        $info = $this->images->inspect($bytes, $filename);
        $img = $this->images->decode($bytes, $info['mime']);
        $format = ImageProcessor::formatFor($info['mime']);

        $original = $this->images->encode($img, $format, 90);
        $this->version($asset, 'original', $original, ['reencoded' => true]);

        $optimized = $this->images->encode($this->images->fit($img, 1600, 1600), $format === 'gif' ? 'png' : $format, 82);
        $this->version($asset, 'optimized', $optimized, ['max' => 1600, 'quality' => 82]);

        $fitted = $this->images->fit($img, 1600, 1600);
        $this->version($asset, 'webp', $this->images->encode($fitted, 'webp', 80), ['max' => 1600, 'quality' => 80]);
        if ($this->images->supportsAvif()) {
            $this->version($asset, 'avif', $this->images->encode($fitted, 'avif', 80), ['max' => 1600]);
        }
        $this->version($asset, 'thumbnail', $this->images->encode($this->images->fit($img, 400, 400), 'webp', 75), ['max' => 400]);

        $dhash = $this->images->dhash($img);
        $duplicate = MediaAsset::query()->where('sha256', $info['sha256'])->where('id', '!=', $asset->id)->orderBy('created_at')->value('id');
        $near = [];
        $candidates = MediaAsset::query()->whereNotNull('dhash')->where('id', '!=', $asset->id)
            ->when($asset->site_id !== null, fn ($q) => $q->where('site_id', $asset->site_id))
            ->orderByDesc('created_at')->limit(500)->get(['id', 'dhash']);
        foreach ($candidates as $c) {
            if (ImageProcessor::hamming((string) $c->dhash, $dhash) <= 5) {
                $near[] = $c->id;
            }
        }

        $meta = $asset->metadata ?? [];
        if ($near !== []) {
            $meta['near_duplicates'] = array_slice($near, 0, 10);
        }
        $asset->forceFill([
            'mime' => $original['mime'], 'width' => $original['width'], 'height' => $original['height'], 'bytes' => strlen($original['bytes']),
            'sha256' => $info['sha256'], 'dhash' => $dhash, 'duplicate_of_id' => $duplicate,
            'storage_path' => $this->storage->path($asset->workspace_id, $asset->id, 'original', $original['ext']),
            'original_filename' => $filename !== null ? mb_substr(basename($filename), 0, 190) : $asset->original_filename,
            'metadata' => $meta, 'status' => 'ready', 'error_code' => null,
        ])->save();

        return $asset;
    }

    /**
     * Bytes to upload to WordPress: optimized version first.
     *
     * @return array{bytes: string, mime: string, filename: string}|null
     */
    public function bytesFor(MediaAsset $asset): ?array
    {
        foreach (['optimized', 'original'] as $kind) {
            $v = MediaVersion::query()->where('media_asset_id', $asset->id)->where('kind', $kind)->first();
            $bytes = $v !== null ? $this->storage->get($v->storage_path) : null;
            if ($v !== null && $bytes !== null) {
                $ext = (string) pathinfo($v->storage_path, PATHINFO_EXTENSION);
                $base = pathinfo((string) ($asset->original_filename ?: 'sitex-image'), PATHINFO_FILENAME);
                $base = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower(\Illuminate\Support\Str::ascii($base))), '-') ?: 'sitex-image';

                return ['bytes' => $bytes, 'mime' => $v->mime, 'filename' => mb_substr($base, 0, 80).'.'.$ext];
            }
        }

        return null;
    }

    /**
     * @param  array{bytes: string, mime: string, ext: string, width: int, height: int}  $enc
     * @param  array<string, mixed>  $params
     */
    private function version(MediaAsset $asset, string $kind, array $enc, array $params): void
    {
        $path = $this->storage->path($asset->workspace_id, $asset->id, $kind, $enc['ext']);
        $this->storage->put($path, $enc['bytes']);
        $v = MediaVersion::query()->where('media_asset_id', $asset->id)->where('kind', $kind)->first() ?? new MediaVersion;
        $v->forceFill([
            'media_asset_id' => $asset->id, 'kind' => $kind, 'mime' => $enc['mime'], 'width' => $enc['width'], 'height' => $enc['height'],
            'bytes' => strlen($enc['bytes']), 'sha256' => hash('sha256', $enc['bytes']), 'storage_path' => $path, 'params' => $params,
        ])->save();
    }
}
