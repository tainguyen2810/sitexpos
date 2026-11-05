<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Media\Domain\ImagePlacement;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Support\MediaRejected;
use SiteXPost\Modules\Media\Support\PlacementEngine;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Article Media Editor: engine suggestions + the user's choices (choose,
 * replace, remove, move, alt, caption, featured). Re-running suggestions
 * only replaces rows still `suggested` by the engine; accepted rows and
 * rows the user touched are kept.
 */
final class Placements
{
    public function __construct(private readonly PlacementEngine $engine, private readonly ArticleCatalog $articles) {}

    /**
     * @throws MediaRejected
     */
    public function suggest(string $articleId, ?string $userId): int
    {
        $article = $this->articles->find($articleId) ?? throw new MediaRejected('article_not_found');
        $content = $this->articles->currentContent($articleId);
        if ($content === null) {
            throw new MediaRejected('article_has_no_content');
        }
        $assets = MediaAsset::query()->where('status', 'ready')
            ->where(fn ($q) => $q->where('site_id', $article['site_id'])->orWhereNull('site_id'))
            ->orderByDesc('created_at')->limit(500)->get()
            ->sortByDesc(fn (MediaAsset $a) => ($a->metadata['article_id'] ?? null) === $articleId ? 1 : 0)->values()->all();
        $kept = ImagePlacement::query()->where('article_id', $articleId)->where(fn ($q) => $q->where('status', '!=', 'suggested')->orWhere('origin', 'user'))->get();
        $takenSlots = $kept->map(fn (ImagePlacement $p) => $p->slot.'|'.($p->section_key ?? ''))->all();
        $suggestions = $this->engine->suggest(['title' => $article['title'], 'primary_keyword' => $article['primary_keyword']], $content['html'], $assets);

        return DB::transaction(function () use ($articleId, $suggestions, $takenSlots, $userId): int {
            ImagePlacement::query()->where('article_id', $articleId)->where('status', 'suggested')->where('origin', 'engine')->delete();
            $n = 0;
            foreach ($suggestions as $s) {
                if (in_array($s['slot'].'|'.($s['section_key'] ?? ''), $takenSlots, true)) {
                    continue;
                }
                $p = new ImagePlacement;
                $p->forceFill($s + ['article_id' => $articleId, 'status' => 'suggested', 'origin' => 'engine', 'updated_by' => $userId])->save();
                $n++;
            }
            Audit::record('media.placements_suggested', null, ['article_id' => $articleId, 'count' => $n]);

            return $n;
        });
    }

    /**
     * Apply the editor's list (create / update / delete) atomically.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws MediaRejected
     */
    public function save(string $articleId, array $items, ?string $userId): void
    {
        $this->articles->find($articleId) ?? throw new MediaRejected('article_not_found');
        $featured = array_filter($items, fn ($i) => ($i['slot'] ?? '') === 'featured' && ($i['status'] ?? '') === 'accepted' && empty($i['delete']));
        if (count($featured) > 1) {
            throw new MediaRejected('one_featured_only');
        }
        $accepted = array_filter($items, fn ($i) => ($i['status'] ?? '') === 'accepted' && empty($i['delete']) && ($i['slot'] ?? '') !== 'featured');
        if (count($accepted) > (int) config('sitex.media.max_inline_images', 8)) {
            throw new MediaRejected('too_many_images', '', ['max' => (int) config('sitex.media.max_inline_images', 8)]);
        }

        DB::transaction(function () use ($articleId, $items, $userId): void {
            foreach ($items as $i) {
                $row = ! empty($i['id']) ? ImagePlacement::query()->where('article_id', $articleId)->find($i['id']) : new ImagePlacement;
                if ($row === null) {
                    throw new MediaRejected('placement_not_found');
                }
                if (! empty($i['delete'])) {
                    $row->exists && $row->delete();

                    continue;
                }
                $asset = ! empty($i['media_asset_id']) ? MediaAsset::query()->find($i['media_asset_id']) : null;
                if (! empty($i['media_asset_id']) && $asset === null) {
                    throw new MediaRejected('asset_not_found');
                }
                PlacementEngine::assertCompatible((string) $i['slot'], $asset);
                if (($i['status'] ?? '') === 'accepted' && $asset === null) {
                    throw new MediaRejected('placement_needs_asset');
                }
                if (($i['status'] ?? '') === 'accepted' && trim((string) ($i['alt_text'] ?? '')) === '') {
                    throw new MediaRejected('alt_required');
                }
                $row->forceFill([
                    'article_id' => $articleId, 'media_asset_id' => $asset?->id, 'slot' => $i['slot'], 'section_key' => $i['section_key'] ?? null,
                    'section_heading' => isset($i['section_heading']) ? mb_substr((string) $i['section_heading'], 0, 300) : $row->section_heading,
                    'position' => (int) ($i['position'] ?? 0), 'alt_text' => isset($i['alt_text']) ? mb_substr(trim((string) $i['alt_text']), 0, 250) : null,
                    'caption' => isset($i['caption']) ? mb_substr(trim((string) $i['caption']), 0, 500) : null, 'status' => $i['status'] ?? 'suggested',
                    'origin' => $row->exists ? $row->origin : 'user', 'updated_by' => $userId,
                ])->save();
                if ($row->origin === 'engine' && $row->wasChanged(['media_asset_id', 'alt_text', 'caption', 'position', 'status'])) {
                    $row->forceFill(['origin' => 'user'])->save();
                }
            }
            $this->recount($articleId);
        });
        Audit::record('media.placements_saved', null, ['article_id' => $articleId, 'items' => count($items)]);
    }

    private function recount(string $articleId): void
    {
        $ids = ImagePlacement::query()->where('article_id', $articleId)->whereNotNull('media_asset_id')->pluck('media_asset_id')->unique()->all();
        foreach ($ids as $id) {
            MediaAsset::query()->whereKey($id)->update(['usage_count' => ImagePlacement::query()->where('media_asset_id', $id)->where('status', 'accepted')->count()]);
        }
    }
}
