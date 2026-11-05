<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\ImagePlacement;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaVersion;
use SiteXPost\Modules\Media\Queries\MediaLibraryQuery;

final class MediaPresenter
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function page(Builder $query, callable $map, Request $request, int $default = 25): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->integer('per_page', $default)));
        $page = $query->cursorPaginate($perPage);

        return new JsonResponse([
            'data' => array_map($map, $page->items()),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'per_page' => $perPage],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function asset(MediaAsset $a, bool $detail = false): array
    {
        $out = MediaLibraryQuery::present($a) + [
            'original_filename' => $a->original_filename, 'sha256' => $a->sha256, 'duplicate_of_id' => $a->duplicate_of_id,
            'near_duplicates' => $a->metadata['near_duplicates'] ?? [], 'error_code' => $a->error_code, 'usage_count' => (int) $a->usage_count,
            'ai_provider' => $a->ai_provider, 'ai_model' => $a->ai_model, 'generation_job_id' => $a->generation_job_id, 'is_demo' => (bool) $a->is_demo,
            'product' => $a->metadata['product'] ?? null, 'infographic' => $a->metadata['infographic'] ?? null,
            'created_at' => self::ts($a->created_at), 'updated_at' => self::ts($a->updated_at),
        ];
        if ($detail) {
            $out['ai_prompt'] = $a->ai_prompt;
            $out['versions'] = MediaVersion::query()->where('media_asset_id', $a->id)->orderBy('kind')->get()
                ->map(fn (MediaVersion $v) => ['kind' => $v->kind, 'mime' => $v->mime, 'width' => $v->width, 'height' => $v->height, 'bytes' => $v->bytes,
                    'url' => MediaLibraryQuery::signedUrl($a, $v->kind)])->all();
            $out['placements'] = ImagePlacement::query()->where('media_asset_id', $a->id)->limit(50)->get(['id', 'article_id', 'slot', 'status'])->toArray();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function job(ImageGenerationJob $j): array
    {
        $assets = MediaAsset::query()->whereIn('id', $j->result_asset_ids ?? [])->get();

        return [
            'id' => $j->id, 'site_id' => $j->site_id, 'article_id' => $j->article_id, 'parent_job_id' => $j->parent_job_id, 'version' => $j->version,
            'image_type' => $j->image_type, 'section_key' => $j->section_key, 'aspect_ratio' => $j->aspect_ratio, 'style' => $j->style,
            'quality' => $j->quality, 'image_count' => $j->image_count, 'brand_colors' => $j->brand_colors ?? [], 'prompt' => $j->prompt,
            'prompt_edited' => (bool) $j->prompt_edited, 'status' => $j->status, 'budget_cap_micro_usd' => $j->budget_cap_micro_usd,
            'estimated_cost_micro_usd' => $j->estimated_cost_micro_usd, 'cost_micro_usd' => $j->cost_micro_usd, 'provider' => $j->provider,
            'model' => $j->model, 'error_code' => $j->error_code, 'error_message' => $j->error_message, 'job_run_id' => $j->job_run_id,
            'assets' => $assets->map(fn (MediaAsset $a) => MediaLibraryQuery::present($a))->values()->all(), 'is_demo' => (bool) $j->is_demo,
            'created_at' => self::ts($j->created_at), 'finished_at' => self::ts($j->finished_at),
        ];
    }

    private static function ts(?Carbon $t): ?string
    {
        return $t?->toIso8601ZuluString();
    }
}
