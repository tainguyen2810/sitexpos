<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Research\Contracts\SearchConsoleAccess;

/**
 * Super Admin: Search Console connection health, sync failures and API
 * usage across tenants. Read-only aggregates — no query text, no URLs of
 * customer pages beyond the failing site id.
 */
final class AdminAnalyticsController
{
    public function __construct(private readonly TenantContext $context, private readonly SearchConsoleAccess $gsc) {}

    public function overview(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->integer('days', 7)));
        $since = now()->subDays($days);

        return $this->context->bypass(fn (): JsonResponse => new JsonResponse(['data' => [
            'days' => $days,
            'oauth_configured' => $this->gsc->configured(),
            'properties' => DB::table('analytics_properties')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'sync_runs' => DB::table('analytics_sync_runs')->where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'api_requests' => (int) DB::table('analytics_sync_runs')->where('created_at', '>=', $since)->sum('api_requests'),
            'rows_upserted' => (int) DB::table('analytics_sync_runs')->where('created_at', '>=', $since)->sum('rows_upserted'),
            'errors' => DB::table('analytics_sync_runs')->where('created_at', '>=', $since)->whereNotNull('error_code')
                ->selectRaw('error_code, count(*) as n')->groupBy('error_code')->orderByDesc('n')->limit(20)->get(),
            'failing' => DB::table('analytics_properties')->where('consecutive_failures', '>', 0)->orderByDesc('consecutive_failures')->limit(50)
                ->get(['workspace_id', 'site_id', 'status', 'last_error_code', 'consecutive_failures', 'last_sync_at']),
            'stale' => DB::table('analytics_properties')->where('status', 'active')->where(fn ($q) => $q->whereNull('last_sync_at')->orWhere('last_sync_at', '<', now()->subDays(3)))->count(),
            'refresh_jobs' => DB::table('content_refresh_jobs')->where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'copilot' => ['queries' => DB::table('copilot_queries')->where('created_at', '>=', $since)->count(),
                'cost_micro_usd' => (int) DB::table('copilot_queries')->where('created_at', '>=', $since)->sum('cost_micro_usd')],
        ]]));
    }
}
