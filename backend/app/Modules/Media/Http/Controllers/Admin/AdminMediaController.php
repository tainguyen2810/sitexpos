<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\ModelSelector;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Media\Domain\MediaLimit;
use SiteXPost\Modules\Media\Http\Requests\MediaLimitsRequest;

/**
 * Super Admin: image providers (read-only view of the AI Gateway route —
 * configured on the AI page), image generation usage and costs, storage,
 * abuse signals, limits. Cross-tenant reads are reviewed, read-only scope
 * bypasses of platform-wide aggregates.
 */
final class AdminMediaController
{
    public function __construct(private readonly TenantContext $context) {}

    public function overview(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->integer('days', 30)));
        $since = now()->subDays($days);

        return $this->context->bypass(function () use ($since, $days): JsonResponse {
            $route = AiTaskRoute::query()->where('task_key', 'media.image')->first();
            $ids = $route !== null ? app(ModelSelector::class)->orderedIds($route) : [];
            $models = AiModel::query()->with('provider')->whereIn('id', $ids)->get()->map(fn (AiModel $m) => [
                'model_key' => $m->model_key, 'provider' => $m->provider?->code, 'enabled' => (bool) $m->is_enabled,
                'image_generation' => $m->supports('image_generation'), 'provider_configured' => $m->provider !== null && (bool) ($m->provider->is_enabled ?? true),
            ])->values();
            $jobs = DB::table('image_generation_jobs')->where('created_at', '>=', $since);

            return new JsonResponse(['data' => [
                'days' => $days,
                'route' => ['task_key' => 'media.image', 'enabled' => (bool) $route?->is_enabled, 'models' => $models],
                'generation' => [
                    'jobs' => (clone $jobs)->count(),
                    'by_status' => (clone $jobs)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
                    'images' => (int) (clone $jobs)->where('status', 'succeeded')->sum('image_count'),
                    'cost_micro_usd' => (int) (clone $jobs)->sum('cost_micro_usd'),
                    'by_model' => (clone $jobs)->whereNotNull('model')->selectRaw('model, count(*) as jobs, sum(cost_micro_usd) as cost')->groupBy('model')->get(),
                ],
                'storage' => [
                    'bytes' => (int) DB::table('media_versions')->sum('bytes'),
                    'assets' => DB::table('media_assets')->whereNull('deleted_at')->count(),
                    'top_workspaces' => DB::table('media_versions')->selectRaw('workspace_id, sum(bytes) as bytes')->groupBy('workspace_id')->orderByDesc('bytes')->limit(10)->get(),
                ],
                'abuse' => [
                    'rejected_uploads' => DB::table('media_assets')->where('status', 'rejected')->where('created_at', '>=', $since)
                        ->selectRaw('workspace_id, error_code, count(*) as n')->groupBy('workspace_id', 'error_code')->orderByDesc('n')->limit(20)->get(),
                    'heavy_generators' => DB::table('image_generation_jobs')->where('created_at', '>=', now()->subDay())
                        ->selectRaw('workspace_id, sum(image_count) as images, sum(cost_micro_usd) as cost')->groupBy('workspace_id')->orderByDesc('images')->limit(10)->get(),
                ],
            ]]);
        });
    }

    public function limits(): JsonResponse
    {
        return new JsonResponse(['data' => MediaLimit::query()->orderByRaw("CASE WHEN scope_key = 'global' THEN 0 ELSE 1 END")->orderBy('scope_key')->get()]);
    }

    public function updateLimits(MediaLimitsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $row = MediaLimit::query()->where('scope_key', $data['scope_key'])->first() ?? new MediaLimit;
        $row->forceFill($data)->save();
        Audit::record('platform.media_limits_updated', $row, $data);

        return new JsonResponse(['data' => $row]);
    }
}
