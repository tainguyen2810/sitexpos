<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use SiteXPost\Modules\Media\Contracts\MediaLibrary;
use SiteXPost\Modules\Media\Domain\ImagePlacement;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaVersion;
use SiteXPost\Modules\Media\Support\MediaPipeline;

final class MediaLibraryQuery implements MediaLibrary
{
    private const VARIANT_KINDS = ['webp', 'optimized', 'original'];

    public function __construct(private readonly MediaPipeline $pipeline) {}

    public function placements(string $articleId, bool $acceptedOnly = true): array
    {
        $q = ImagePlacement::query()->where('article_id', $articleId)->orderBy('position')->orderBy('id');
        if ($acceptedOnly) {
            $q->where('status', 'accepted');
        }
        $rows = $q->get();
        $assets = MediaAsset::query()->whereIn('id', $rows->pluck('media_asset_id')->filter()->all())->get()->keyBy('id');

        return $rows->map(fn (ImagePlacement $p): array => [
            'id' => $p->id, 'slot' => $p->slot, 'section_key' => $p->section_key, 'section_heading' => $p->section_heading,
            'position' => (int) $p->position, 'status' => $p->status, 'alt_text' => $p->alt_text, 'caption' => $p->caption,
            'asset' => $p->media_asset_id !== null && isset($assets[$p->media_asset_id]) ? self::present($assets[$p->media_asset_id]) : null,
        ])->all();
    }

    public function asset(string $assetId): ?array
    {
        $a = MediaAsset::query()->find($assetId);

        return $a === null ? null : self::present($a);
    }

    public function bytes(string $assetId): ?array
    {
        $a = MediaAsset::query()->find($assetId);

        return $a === null || $a->storage_path === null ? null : $this->pipeline->bytesFor($a);
    }

    public function markUploaded(string $assetId, string $siteId, int $wpMediaId, string $url): void
    {
        $a = MediaAsset::query()->find($assetId);
        if ($a === null) {
            return;
        }
        $meta = $a->metadata ?? [];
        $meta['wp_uploads'][$siteId] = ['id' => $wpMediaId, 'url' => mb_substr($url, 0, 1000)];
        $a->forceFill(['metadata' => $meta, 'wp_media_id' => $a->site_id === $siteId ? $wpMediaId : $a->wp_media_id])->save();
    }

    /**
     * Adds a `has_variant` column (null when the asset has no processed file) so
     * that present() needs no extra query per row.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public static function withVariantFlag(Builder $query): Builder
    {
        return $query->select('media_assets.*')->addSelect(['has_variant' => MediaVersion::query()->select('media_versions.id')
            ->whereColumn('media_versions.media_asset_id', 'media_assets.id')->whereIn('kind', self::VARIANT_KINDS)->limit(1)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(MediaAsset $a): array
    {
        // Lists preload `has_variant` (withVariantFlag) to avoid one query per asset.
        $variant = array_key_exists('has_variant', $a->getAttributes())
            ? $a->getAttribute('has_variant') !== null
            : MediaVersion::query()->where('media_asset_id', $a->id)->whereIn('kind', self::VARIANT_KINDS)->exists();

        return [
            'id' => $a->id, 'site_id' => $a->site_id, 'source_type' => $a->source_type, 'status' => $a->status, 'mime' => $a->mime,
            'width' => $a->width, 'height' => $a->height, 'bytes' => $a->bytes, 'license' => $a->license, 'attribution' => $a->attribution,
            'is_ai_generated' => (bool) $a->is_ai_generated, 'title' => $a->title, 'alt_text' => $a->alt_text, 'caption' => $a->caption,
            'wp_media_id' => $a->wp_media_id, 'source_url' => $a->source_url,
            'wp_uploads' => is_array($a->metadata['wp_uploads'] ?? null) ? $a->metadata['wp_uploads'] : [],
            'preview_url' => $variant ? self::signedUrl($a, 'webp') : $a->source_url,
            'thumbnail_url' => $variant ? self::signedUrl($a, 'thumbnail') : $a->source_url,
        ];
    }

    public static function signedUrl(MediaAsset $a, string $variant): string
    {
        return URL::temporarySignedRoute('media.file', now()->addMinutes(30), ['workspace' => $a->workspace_id, 'asset' => $a->id, 'variant' => $variant]);
    }
}
