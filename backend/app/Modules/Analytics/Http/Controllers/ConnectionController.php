<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Analytics\Actions\StartSync;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSetting;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Domain\SeoHealthScore;
use SiteXPost\Modules\Analytics\Http\AnalyticsPresenter;
use SiteXPost\Modules\Analytics\Http\Requests\SelectPropertyRequest;
use SiteXPost\Modules\Analytics\Http\Requests\SettingsRequest;
use SiteXPost\Modules\Analytics\Http\Requests\StartSyncRequest;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;
use SiteXPost\Modules\Analytics\Support\DashboardQuery;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Contracts\SearchConsoleAccess;
use SiteXPost\Modules\Research\SearchConsole\SearchConsoleException;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Search Console connection per site (reuses the Phase 4 OAuth connection),
 * sync control and analytics settings. Google tokens never leave the
 * Research module and are never returned.
 */
final class ConnectionController
{
    public function __construct(private readonly SearchConsoleAccess $gsc, private readonly DashboardQuery $dash, private readonly SiteDirectory $sites) {}

    public function sites(Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $props = AnalyticsProperty::query()->where('provider', 'gsc')->get()->keyBy('site_id');
        $health = SeoHealthScore::query()->orderByDesc('date')->get(['site_id', 'score', 'date'])->unique('site_id')->keyBy('site_id');

        return new JsonResponse(['data' => array_map(function (array $s) use ($props, $health) {
            $p = $props->get($s['id']);
            $h = $health->get($s['id']);

            return $s + [
                'search_console' => $this->gsc->status($s['id']),
                'analytics' => $p === null ? null : ['status' => $p->status, 'first_date' => $p->first_date, 'last_complete_date' => $p->last_complete_date,
                    'last_sync_at' => $p->last_sync_at?->toIso8601ZuluString(), 'last_sync_status' => $p->last_sync_status, 'last_error_code' => $p->last_error_code],
                'health' => $h === null ? null : ['score' => $h->score, 'date' => $h->date],
            ];
        }, $this->sites->all()), 'meta' => ['oauth_configured' => $this->gsc->configured(), 'ga4_available' => false]]);
    }

    public function connection(Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $siteId = $this->dash->site($site);
        $p = AnalyticsProperty::query()->where('site_id', $siteId)->where('provider', 'gsc')->first();

        return new JsonResponse(['data' => [
            'site_id' => $siteId, 'oauth_configured' => $this->gsc->configured(), 'search_console' => $this->gsc->status($siteId),
            'analytics' => $p === null ? null : ['status' => $p->status, 'first_date' => $p->first_date, 'last_complete_date' => $p->last_complete_date,
                'last_sync_at' => $p->last_sync_at?->toIso8601ZuluString(), 'last_sync_status' => $p->last_sync_status, 'last_error_code' => $p->last_error_code,
                'consecutive_failures' => $p->consecutive_failures],
            'ga4' => ['available' => false],
        ]]);
    }

    public function connect(Request $request, Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('configure', AnalyticsProperty::class);
        $siteId = $this->dash->site($site);
        try {
            $url = $this->gsc->authorizationUrl($siteId, (string) $request->user()?->getAuthIdentifier());
        } catch (SearchConsoleException $e) {
            throw ApiException::conflict('gsc_'.$e->errorCode, __('analytics.errors.gsc_not_configured'));
        }

        return new JsonResponse(['data' => ['authorization_url' => $url]]);
    }

    public function properties(Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('configure', AnalyticsProperty::class);
        $siteId = $this->dash->site($site);

        return new JsonResponse(['data' => $this->call(fn () => $this->gsc->properties($siteId))]);
    }

    public function selectProperty(SelectPropertyRequest $request, Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('configure', AnalyticsProperty::class);
        $siteId = $this->dash->site($site);
        $url = (string) $request->validated('property_url');
        $this->call(fn () => $this->gsc->selectProperty($siteId, $url, (string) $request->user()?->getAuthIdentifier()));
        $p = (AnalyticsProperty::query()->where(['site_id' => $siteId, 'provider' => 'gsc'])->first() ?? (new AnalyticsProperty)->forceFill(['site_id' => $siteId, 'provider' => 'gsc']));
        $changed = $p->exists && $p->property_ref !== $url;
        $p->forceFill(['site_id' => $siteId, 'provider' => 'gsc', 'property_ref' => $url, 'status' => 'active', 'last_error_code' => null]
            + ($changed ? ['last_complete_date' => null, 'first_date' => null] : []))->save();
        Audit::record('analytics.property_selected', $p, ['property' => $url, 'changed' => $changed]);

        return $this->connection($workspace, $siteId);
    }

    public function disconnect(Request $request, Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('configure', AnalyticsProperty::class);
        $siteId = $this->dash->site($site);
        $revoked = $this->gsc->disconnect($siteId, (string) $request->user()?->getAuthIdentifier());
        AnalyticsProperty::query()->where('site_id', $siteId)->where('provider', 'gsc')->update(['status' => 'disconnected']);

        // Synced metrics are kept (they belong to the tenant) until retention prunes them.
        return new JsonResponse(['data' => ['disconnected' => true, 'revoked_at_google' => $revoked]]);
    }

    public function sync(StartSyncRequest $request, Workspace $workspace, string $site, StartSync $action): JsonResponse
    {
        Gate::authorize('configure', AnalyticsProperty::class);
        $siteId = $this->dash->site($site);
        $res = $action->handle($siteId, (string) ($request->validated('kind') ?? 'incremental'), (string) $request->user()?->getAuthIdentifier(), $request->validated('idempotency_key'));
        if ($res['created']) {
            Audit::record('analytics.sync_started', $res['run'], ['kind' => $res['run']->kind]);
        }

        return new JsonResponse(['data' => AnalyticsPresenter::syncRun($res['run']),
            'job_run' => $res['job_run'] === null ? null : ['id' => $res['job_run']->id, 'status' => $res['job_run']->status]], 202);
    }

    public function syncRuns(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $site = $request->query('site_id');
        $q = AnalyticsSyncRun::query()->orderByDesc('created_at')->orderByDesc('id')->limit(50);
        if (is_string($site) && $site !== '') {
            $q->where('site_id', $this->dash->site($site));
        }

        return new JsonResponse(['data' => $q->get()->map(fn (AnalyticsSyncRun $r) => AnalyticsPresenter::syncRun($r))->all()]);
    }

    public function settings(Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);

        return new JsonResponse(['data' => $this->settingsPayload($workspace->id)]);
    }

    public function updateSettings(SettingsRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('configure', AnalyticsProperty::class);
        $data = $request->validated();
        $s = AnalyticsSetting::query()->first() ?? new AnalyticsSetting;
        if ((int) $s->lock_version !== (int) $data['lock_version']) {
            throw ApiException::conflict('version_conflict', __('analytics.errors.settings_conflict'));
        }
        $fill = array_intersect_key($data, array_flip(['thresholds', 'health_weights', 'backfill_days', 'raw_retention_days']));
        if (isset($fill['health_weights']) && array_sum($fill['health_weights']) <= 0) {
            throw ApiException::conflict('invalid_weights', __('analytics.errors.invalid_weights'));
        }
        $s->forceFill($fill + ['lock_version' => (int) $s->lock_version + 1, 'updated_by' => (string) $request->user()?->getAuthIdentifier()])->save();
        Audit::record('analytics.settings_updated', $s, ['fields' => array_keys($fill)]);

        return new JsonResponse(['data' => $this->settingsPayload($workspace->id)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(string $workspaceId): array
    {
        $s = AnalyticsSetting::query()->first();

        return [
            'thresholds' => AnalyticsConfig::thresholds($workspaceId), 'default_thresholds' => AnalyticsConfig::THRESHOLDS,
            'health_weights' => AnalyticsConfig::weights($workspaceId), 'default_health_weights' => AnalyticsConfig::HEALTH_WEIGHTS,
            'backfill_days' => AnalyticsConfig::backfillDays($workspaceId), 'raw_retention_days' => (int) ($s->raw_retention_days ?? 490),
            'lock_version' => (int) ($s->lock_version ?? 0),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (SearchConsoleException $e) {
            $status = in_array($e->errorCode, ['not_connected', 'needs_property', 'property_unknown', 'property_revoked', 'reauth_required'], true) ? 409 : 502;

            throw new ApiException($status, 'gsc_'.$e->errorCode, __('analytics.errors.gsc_'.$e->errorCode));
        }
    }
}
