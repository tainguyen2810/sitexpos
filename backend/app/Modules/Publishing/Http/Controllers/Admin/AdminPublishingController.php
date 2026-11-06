<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Publishing\Domain\PublishingLimit;
use SiteXPost\Modules\Publishing\Http\Requests\PublishingLimitsRequest;

/**
 * Super Admin: publishing job monitor, failed jobs, connector
 * compatibility (plugin / WordPress versions, SEO adapters), site
 * connection errors, global publishing limits + kill switch.
 * Cross-tenant reads are reviewed, read-only aggregates (no content).
 */
final class AdminPublishingController
{
    public function __construct(private readonly TenantContext $context) {}

    public function overview(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->integer('days', 7)));
        $since = now()->subDays($days);

        return $this->context->bypass(fn (): JsonResponse => new JsonResponse(['data' => [
            'days' => $days,
            'jobs' => [
                'by_status' => DB::table('publishing_jobs')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
                'by_mode' => DB::table('publishing_jobs')->where('created_at', '>=', $since)->selectRaw('mode, count(*) as n')->groupBy('mode')->pluck('n', 'mode'),
                'published' => DB::table('publishing_jobs')->where('status', 'PUBLISHED')->where('published_at', '>=', $since)->count(),
                'stuck' => DB::table('publishing_jobs')->where('status', 'PUBLISHING')->where('next_attempt_at', '<', now()->subMinutes(30))->count(),
                'open_conflicts' => DB::table('publishing_conflicts')->where('status', 'open')->count(),
                'missed_schedules' => DB::table('publishing_schedules')->where('status', 'missed')->where('updated_at', '>=', $since)->count(),
            ],
            'errors' => DB::table('publishing_attempts')->where('created_at', '>=', $since)->whereNotNull('error_code')
                ->selectRaw('error_code, count(*) as n')->groupBy('error_code')->orderByDesc('n')->limit(20)->get(),
            'failed_jobs' => DB::table('publishing_jobs')->where('status', 'FAILED')->orderByDesc('updated_at')->limit(50)
                ->get(['id', 'workspace_id', 'site_id', 'mode', 'attempts', 'last_error_code', 'last_error_message', 'updated_at']),
            'connectors' => [
                'plugin_versions' => DB::table('sites')->selectRaw('plugin_version, count(*) as n')->groupBy('plugin_version')->orderByDesc('n')->get(),
                'wp_versions' => DB::table('sites')->selectRaw('wp_version, count(*) as n')->groupBy('wp_version')->orderByDesc('n')->limit(20)->get(),
                'connection_errors' => DB::table('sites')->whereNotNull('last_error_code')
                    ->orderByDesc('updated_at')->limit(50)->get(['id', 'workspace_id', 'url', 'status', 'last_error_code', 'plugin_version', 'wp_version', 'last_health_at']),
            ],
        ]]));
    }

    public function limits(): JsonResponse
    {
        return new JsonResponse(['data' => PublishingLimit::query()->orderByRaw("CASE WHEN scope_key = 'global' THEN 0 ELSE 1 END")->orderBy('scope_key')->get(),
            'defaults' => ['max_publishes_per_day' => (int) config('sitex.publishing.max_publishes_per_day'), 'max_bulk_items' => (int) config('sitex.publishing.max_bulk_items'),
                'max_schedule_days_ahead' => (int) config('sitex.publishing.max_schedule_days_ahead')]]);
    }

    public function updateLimits(PublishingLimitsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $row = PublishingLimit::query()->where('scope_key', $data['scope_key'])->first() ?? new PublishingLimit;
        $row->forceFill($data)->save();
        Audit::record('platform.publishing_limits_updated', $row, $data);

        return new JsonResponse(['data' => $row]);
    }
}
