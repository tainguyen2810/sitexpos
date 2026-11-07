<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\ImagePlacement;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaAutofill;
use SiteXPost\Modules\Media\Support\AltText;
use SiteXPost\Modules\Media\Support\MediaRejected;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use Throwable;

/**
 * Auto images after a draft (STYLE-AND-AUTO-IMAGES.md §2). The customer's
 * choice: library first, AI only for what is missing.
 *
 * 1. Run the placement engine (featured + sections that need an image).
 * 2. Make sure there are at least `target` slots (extra section slots on the
 *    longest sections; never FAQ / conclusion / sources).
 * 3. Accept the engine's library matches: assets of this site that are
 *    ready, licensed, and — when AI-made — made for THIS article (an AI
 *    image of another article is never reused automatically).
 * 4. Queue one AI image per empty slot until the target is met; the
 *    featured slot is always filled. Every AI call goes through
 *    GenerateImages (image model check, daily limit, per-job budget cap,
 *    workspace/global AI budgets, AI label + caption) with a stable
 *    idempotency key per placement and version, so a replay never pays twice.
 * 5. When a job finishes, its image is placed in its slot (if the slot is
 *    still untouched by the user).
 *
 * Rows the user created or edited are never changed. If the target cannot
 * be met (no image model, limits, budget), the article is marked
 * `incomplete` with the reason; the draft itself is never blocked.
 */
final class AutoIllustrate
{
    private const AI_SLOTS = ['featured', 'intro', 'section'];

    public function __construct(
        private readonly Placements $placements,
        private readonly GenerateImages $generator,
        private readonly ArticleCatalog $articles,
    ) {}

    public function run(string $workspaceId, string $articleId, ?string $versionId, int $target): ?MediaAutofill
    {
        if ($target <= 0 || $this->articles->find($articleId) === null) {
            return null;
        }
        $row = MediaAutofill::query()->where('article_id', $articleId)->first() ?? new MediaAutofill;
        if ($row->exists && $row->version_id === $versionId && in_array($row->status, ['running', 'completed'], true)) {
            return $row; // replayed event for the same draft
        }
        $row->forceFill(['article_id' => $articleId, 'version_id' => $versionId, 'target' => $target, 'status' => 'running', 'filled' => 0,
            'library_count' => 0, 'ai_count' => 0, 'pending' => [], 'error_code' => null, 'error_message' => null])->save();

        try {
            $this->placements->suggest($articleId, null);
        } catch (MediaRejected $e) {
            $row->forceFill(['status' => 'failed', 'error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 500)])->save();

            return $row;
        }
        $this->ensureSlots($articleId, $target);

        $library = 0;
        foreach ($this->rows($articleId) as $p) {
            if (! $this->untouched($p) || $p->media_asset_id === null) {
                continue;
            }
            $asset = MediaAsset::query()->find($p->media_asset_id);
            if ($asset !== null && $this->reusable($asset, $articleId)) {
                $this->accept($p, $asset);
                $library++;
            } else {
                $p->forceFill(['media_asset_id' => null, 'alt_text' => null, 'caption' => null])->save();
            }
        }

        $filled = $this->filledCount($articleId);
        $empty = $this->rows($articleId)->filter(fn (ImagePlacement $p) => $this->untouched($p) && $p->media_asset_id === null && in_array($p->slot, self::AI_SLOTS, true))
            ->sortBy(fn (ImagePlacement $p) => $p->slot === 'featured' ? -1 : $p->position)->values();
        $featuredEmpty = $empty->contains(fn (ImagePlacement $p) => $p->slot === 'featured');
        $need = max($target - $filled, $featuredEmpty ? 1 : 0);
        $pending = [];
        $error = null;
        foreach ($empty as $p) {
            if ($need <= 0) {
                break;
            }
            try {
                $res = $this->generator->create($workspaceId, [
                    'article_id' => $articleId, 'image_type' => $p->slot === 'featured' ? 'featured' : 'section',
                    'section_key' => $p->slot === 'featured' ? null : $p->section_key, 'image_count' => 1, 'aspect_ratio' => '16:9',
                    'style' => (string) config('sitex.media.auto_image_style', 'editorial_concept'), 'quality' => 'standard',
                ], null, 'auto:'.$articleId.':'.$p->id.':'.($versionId ?? '0'));
                $pending[] = ['job_id' => $res['job']->id, 'placement_id' => $p->id];
                $need--;
            } catch (MediaRejected $e) {
                $error = $e;
                break;
            }
        }

        $row->forceFill(['library_count' => $library, 'pending' => $pending, 'error_code' => $error?->errorCode,
            'error_message' => $error !== null ? mb_substr($error->getMessage(), 0, 500) : null])->save();
        // A replayed idempotent job may already be finished: place it now.
        foreach ($pending as $item) {
            $job = ImageGenerationJob::query()->find($item['job_id']);
            if ($job !== null && in_array($job->status, ['succeeded', 'failed', 'blocked'], true)) {
                $this->finished($job);
            }
        }
        $row = $this->settle($row->refresh());
        Audit::record('media.auto_images_started', null, ['article_id' => $articleId, 'target' => $target, 'library' => $library,
            'ai_jobs' => count($pending), 'error' => $error?->errorCode], actorType: 'system');

        return $row;
    }

    /**
     * Called when an AI image job ends: put the image in its slot.
     */
    public function finished(ImageGenerationJob $job): void
    {
        if ($job->article_id === null) {
            return;
        }
        DB::transaction(function () use ($job): void {
            $row = MediaAutofill::query()->where('article_id', $job->article_id)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $pending = $row->pending ?? [];
            $item = collect($pending)->firstWhere('job_id', $job->id);
            if ($item === null) {
                return;
            }
            $fields = ['pending' => array_values(array_filter($pending, fn (array $i) => $i['job_id'] !== $job->id))];
            $assetId = $job->status === 'succeeded' ? ($job->result_asset_ids[0] ?? null) : null;
            $asset = $assetId !== null ? MediaAsset::query()->find($assetId) : null;
            $p = ImagePlacement::query()->where('article_id', $job->article_id)->find($item['placement_id']);
            if ($asset !== null && $asset->status === 'ready' && $p !== null && $this->untouched($p) && $p->media_asset_id === null) {
                $this->accept($p, $asset);
                $fields['ai_count'] = $row->ai_count + 1;
            } elseif ($job->status !== 'succeeded') {
                $fields['error_code'] = $job->error_code ?? 'generation_failed';
                $fields['error_message'] = $job->error_message !== null ? mb_substr($job->error_message, 0, 500) : null;
            }
            $row->forceFill($fields)->save();
        });
        $row = MediaAutofill::query()->where('article_id', $job->article_id)->first();
        if ($row !== null) {
            $this->settle($row);
        }
    }

    /**
     * Running while AI jobs are pending; then completed / incomplete.
     */
    private function settle(MediaAutofill $row): MediaAutofill
    {
        $filled = $this->filledCount($row->article_id);
        $status = ($row->pending ?? []) !== [] ? 'running' : ($filled >= $row->target ? 'completed' : 'incomplete');
        if ($status === 'incomplete' && $row->error_code === null) {
            $row->error_code = 'not_enough_slots';
            $row->error_message = (string) __('media.errors.not_enough_slots');
        }
        $row->forceFill(['filled' => min(255, $filled), 'status' => $status])->save();

        return $row;
    }

    /**
     * Add section slots until there are `target` slots in total.
     */
    private function ensureSlots(string $articleId, int $target): void
    {
        $rows = $this->rows($articleId)->filter(fn (ImagePlacement $p) => $p->status !== 'rejected');
        $missing = $target - $rows->count();
        if ($missing <= 0) {
            return;
        }
        $html = (string) ($this->articles->currentContent($articleId)['html'] ?? '');
        $taken = $rows->pluck('section_key')->filter()->all();
        $sections = collect(ArticleDocument::split($html))
            ->filter(fn (array $s) => ! in_array($s['key'], $taken, true) && ! self::skip($s['heading']))
            ->sortByDesc(fn (array $s) => ($s['key'] === 'intro' ? 0 : 1_000_000) + mb_strlen($s['text']))->values();
        $position = (int) $rows->max('position') + 1;
        foreach ($sections as $s) {
            if ($missing <= 0) {
                break;
            }
            $p = new ImagePlacement;
            $p->forceFill(['article_id' => $articleId, 'slot' => $s['key'] === 'intro' ? 'intro' : 'section', 'section_key' => $s['key'],
                'section_heading' => $s['heading'] !== '' ? mb_substr($s['heading'], 0, 300) : null, 'position' => $position++,
                'status' => 'suggested', 'origin' => 'engine', 'reason' => mb_substr((string) __('media.placement.auto_min_images'), 0, 300)])->save();
            $missing--;
        }
    }

    private function reusable(MediaAsset $a, string $articleId): bool
    {
        if ($a->status !== 'ready' || $a->license === 'unknown') {
            return false;
        }

        return ! $a->is_ai_generated || ($a->metadata['article_id'] ?? null) === $articleId;
    }

    private function accept(ImagePlacement $p, MediaAsset $asset): void
    {
        $alt = trim((string) $p->alt_text) !== '' ? (string) $p->alt_text : AltText::suggest($asset, (string) ($p->section_heading ?? $asset->title ?? ''));
        $p->forceFill(['media_asset_id' => $asset->id, 'alt_text' => mb_substr($alt, 0, 250), 'caption' => $p->caption ?? AltText::caption($asset),
            'status' => 'accepted'])->save();
        try {
            MediaAsset::query()->whereKey($asset->id)->update(['usage_count' => ImagePlacement::query()->where('media_asset_id', $asset->id)->where('status', 'accepted')->count()]);
        } catch (Throwable) {
            // usage counter is informational only
        }
    }

    /**
     * Only engine suggestions the user has not touched are changed.
     */
    private function untouched(ImagePlacement $p): bool
    {
        return $p->origin === 'engine' && $p->status === 'suggested';
    }

    private function filledCount(string $articleId): int
    {
        return ImagePlacement::query()->where('article_id', $articleId)->where('status', 'accepted')->whereNotNull('media_asset_id')->count();
    }

    /**
     * @return Collection<int, ImagePlacement>
     */
    private function rows(string $articleId): Collection
    {
        return ImagePlacement::query()->where('article_id', $articleId)->orderBy('position')->get()->toBase();
    }

    private static function skip(string $heading): bool
    {
        $h = Text::fold($heading);

        return str_contains($h, 'cau hoi') || str_contains($h, 'faq') || str_contains($h, 'ket luan') || str_contains($h, 'tong ket') || str_contains($h, 'nguon');
    }
}
