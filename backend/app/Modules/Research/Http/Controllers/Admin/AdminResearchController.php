<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\ResearchLimit;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\ResearchSource;
use SiteXPost\Modules\Research\Domain\ResearchSourceSetting;
use SiteXPost\Modules\Research\Domain\ResearchUsageCounter;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Http\Requests\Admin\UpdateGlobalSettingsRequest;
use SiteXPost\Modules\Research\Http\Requests\Admin\UpdateSourceRequest;
use SiteXPost\Modules\Research\Http\Requests\Admin\UpsertLimitRequest;
use SiteXPost\Modules\Research\Http\Resources\ResearchRunResource;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Super Admin research console: sources & keyword providers (secrets are
 * write-only), global crawl settings, tenant limits, run monitor, cost per
 * research, source failures and per-tenant usage. Cross-tenant reads use
 * reviewed, read-only scope bypasses.
 */
final class AdminResearchController
{
    public function __construct(private readonly TenantContext $context, private readonly ResearchSettings $settings) {}

    public function sources(): JsonResponse
    {
        $out = [];
        foreach (ResearchSettings::SOURCES as $key => $def) {
            $out[] = $this->presentSource($key, ResearchSourceSetting::query()->where('source', $key)->first());
        }

        return new JsonResponse(['data' => $out]);
    }

    public function updateSource(UpdateSourceRequest $request, string $source): JsonResponse
    {
        if (! array_key_exists($source, ResearchSettings::SOURCES)) {
            throw ApiException::notFound();
        }
        $row = ResearchSourceSetting::query()->where('source', $source)->first() ?? new ResearchSourceSetting;
        $data = ['source' => $source, 'updated_by' => (string) $request->user()?->getAuthIdentifier()];
        if (! $row->exists) {
            $data['is_enabled'] = (bool) ResearchSettings::SOURCES[$source]['default_enabled'];
        }
        foreach (['is_enabled', 'rate_limit_per_minute', 'timeout_seconds'] as $f) {
            if ($request->has($f)) {
                $data[$f] = $f === 'is_enabled' ? $request->boolean($f) : $request->integer($f);
            }
        }
        if ($request->has('config')) {
            $data['config'] = array_merge($row->config ?? [], (array) $request->validated('config'));
        }
        $secretChanged = false;
        if ($request->exists('secret')) {
            $secret = trim((string) $request->input('secret'));
            $data['secret_encrypted'] = $secret === '' ? null : $secret;
            $secretChanged = true;
        }
        if ($request->boolean('reset_failures')) {
            $data += ['failure_count' => 0, 'last_failure_at' => null, 'last_error_code' => null];
        }
        $row->forceFill($data)->save();
        $this->settings->flush();
        $changed = array_values(array_diff(array_keys($data), ['source', 'updated_by', 'secret_encrypted']));
        Audit::record('platform.research.source_updated', $row, ['source' => $source, 'fields' => $changed, 'secret_changed' => $secretChanged], actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->presentSource($source, $row)]);
    }

    public function globalSettings(): JsonResponse
    {
        return new JsonResponse(['data' => $this->presentGlobal()]);
    }

    public function updateGlobalSettings(UpdateGlobalSettingsRequest $request): JsonResponse
    {
        $row = ResearchSourceSetting::query()->where('source', 'global')->first() ?? new ResearchSourceSetting;
        $row->forceFill([
            'source' => 'global', 'is_enabled' => true,
            'config' => array_merge($row->config ?? [], $request->validated()),
            'updated_by' => (string) $request->user()?->getAuthIdentifier(),
        ])->save();
        $this->settings->flush();
        Audit::record('platform.research.settings_updated', $row, ['fields' => array_keys($request->validated())], actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->presentGlobal()]);
    }

    public function limits(): JsonResponse
    {
        $rows = ResearchLimit::query()->orderByRaw("CASE WHEN scope_key = 'global' THEN 0 ELSE 1 END")->orderBy('scope_key')->get();
        $names = Workspace::query()->whereIn('id', $rows->pluck('workspace_id')->filter()->all())->pluck('name', 'id');

        return new JsonResponse(['data' => [
            'defaults' => ResearchSettings::LIMIT_DEFAULTS,
            'rows' => $rows->map(fn (ResearchLimit $l): array => $this->presentLimit($l, $names->get((string) $l->workspace_id)))->all(),
        ]]);
    }

    public function upsertLimit(UpsertLimitRequest $request): JsonResponse
    {
        $workspaceId = $request->input('scope') === 'workspace' ? $request->string('workspace_id')->toString() : null;
        $scopeKey = $workspaceId === null ? 'global' : 'ws:'.$workspaceId;
        $row = ResearchLimit::query()->where('scope_key', $scopeKey)->first() ?? new ResearchLimit;
        $created = ! $row->exists;
        $data = ['scope_key' => $scopeKey, 'workspace_id' => $workspaceId, 'updated_by' => (string) $request->user()?->getAuthIdentifier()];
        foreach (array_keys(ResearchSettings::LIMIT_DEFAULTS) as $k) {
            $data[$k] = $request->integer($k);
        }
        $row->forceFill($data)->save();
        Audit::record($created ? 'platform.research.limit_created' : 'platform.research.limit_updated', $row, ['scope_key' => $scopeKey], workspaceId: $workspaceId, actorType: 'platform_staff');
        $name = $workspaceId !== null ? Workspace::query()->whereKey($workspaceId)->value('name') : null;

        return new JsonResponse(['data' => $this->presentLimit($row, is_string($name) ? $name : null)], $created ? 201 : 200);
    }

    public function destroyLimit(string $limit): JsonResponse
    {
        $row = Str::isUlid($limit) ? ResearchLimit::query()->find($limit) : null;
        if ($row === null) {
            throw ApiException::notFound();
        }
        $row->delete();
        Audit::record('platform.research.limit_deleted', $row, ['scope_key' => $row->scope_key], workspaceId: $row->workspace_id, actorType: 'platform_staff');

        return new JsonResponse(null, 204);
    }

    /**
     * Run monitor across tenants (read-only). Retry/cancel go through the
     * generic job monitor, which acts inside the tenant's context.
     */
    public function runs(Request $request): JsonResponse
    {
        return $this->context->bypass(function () use ($request): JsonResponse {
            $q = ResearchRun::query();
            $status = $request->query('status');
            if (is_string($status) && in_array($status, ['queued', 'running', 'succeeded', 'partial', 'failed', 'canceled'], true)) {
                $q->where('status', $status);
            }
            $ws = $request->query('workspace_id');
            if (is_string($ws) && Str::isUlid($ws)) {
                $q->where('workspace_id', $ws);
            }
            $response = Paginated::response($q->orderByDesc('created_at')->orderByDesc('id'), ResearchRunResource::class, $request, 25);
            $payload = $response->getData(true);
            $ids = array_column($payload['data'], 'id');
            $meta = ResearchRun::query()->whereIn('id', $ids)->get(['id', 'workspace_id', 'campaign_id'])->keyBy('id');
            $workspaces = Workspace::query()->whereIn('id', $meta->pluck('workspace_id')->unique()->all())->pluck('name', 'id');
            $campaigns = SeoCampaign::query()->whereIn('id', $meta->pluck('campaign_id')->unique()->all())->pluck('name', 'id');
            foreach ($payload['data'] as &$row) {
                $m = $meta->get($row['id']);
                $row['workspace'] = $m === null ? null : ['id' => $m->workspace_id, 'name' => $workspaces->get($m->workspace_id)];
                $row['campaign_name'] = $m === null ? null : $campaigns->get($m->campaign_id);
            }
            unset($row);

            return $response->setData($payload);
        });
    }

    /**
     * Cost per research run (AI spend via the gateway), last 30 days.
     */
    public function costs(): JsonResponse
    {
        return $this->context->bypass(function (): JsonResponse {
            $since = now()->subDays(30);
            $base = ResearchRun::query()->where('created_at', '>=', $since);
            $totals = (clone $base)->selectRaw('COUNT(*) as runs, COALESCE(SUM(cost_micro_usd),0) as total, COALESCE(MAX(cost_micro_usd),0) as max_cost')->first();
            $byWs = (clone $base)->selectRaw('workspace_id, COUNT(*) as runs, COALESCE(SUM(cost_micro_usd),0) as total')
                ->groupBy('workspace_id')->orderByDesc('total')->limit(50)->get();
            $names = Workspace::query()->whereIn('id', $byWs->pluck('workspace_id')->all())->pluck('name', 'id');
            $runs = (int) ($totals->runs ?? 0);
            $total = (int) ($totals->total ?? 0);

            return new JsonResponse(['data' => [
                'period_days' => 30,
                'runs' => $runs,
                'total_micro_usd' => $total,
                'avg_micro_usd_per_run' => $runs > 0 ? intdiv($total, $runs) : 0,
                'max_micro_usd_per_run' => (int) ($totals->max_cost ?? 0),
                'by_workspace' => $byWs->map(fn ($r): array => [
                    'workspace' => ['id' => $r->workspace_id, 'name' => $names->get($r->workspace_id)],
                    'runs' => (int) $r->runs, 'total_micro_usd' => (int) $r->total,
                    'avg_micro_usd_per_run' => (int) $r->runs > 0 ? intdiv((int) $r->total, (int) $r->runs) : 0,
                ])->all(),
            ]]);
        });
    }

    /**
     * Source failures: counters per source plus the latest failed fetches.
     */
    public function failures(): JsonResponse
    {
        $settings = ResearchSourceSetting::query()->where('source', '!=', 'global')->where('failure_count', '>', 0)->orderByDesc('last_failure_at')->get();
        $recent = $this->context->bypass(fn () => ResearchSource::query()->where('status', 'failed')->orderByDesc('created_at')->limit(50)
            ->get(['id', 'workspace_id', 'research_run_id', 'type', 'label', 'url', 'http_status', 'error_code', 'error_message', 'created_at']));

        return new JsonResponse(['data' => [
            'sources' => $settings->map(fn (ResearchSourceSetting $s): array => [
                'source' => $s->source, 'failure_count' => $s->failure_count, 'last_error_code' => $s->last_error_code,
                'last_failure_at' => $s->last_failure_at?->toIso8601ZuluString(),
            ])->all(),
            'recent' => $recent->map(fn ($r): array => [
                'id' => $r->id, 'workspace_id' => $r->workspace_id, 'research_run_id' => $r->research_run_id, 'type' => $r->type,
                'label' => $r->label, 'url' => $r->url, 'http_status' => $r->http_status, 'error_code' => $r->error_code,
                'error_message' => $r->error_message, 'created_at' => $r->created_at?->toIso8601ZuluString(),
            ])->all(),
        ]]);
    }

    /**
     * This month's usage per tenant against its effective limits.
     */
    public function usage(): JsonResponse
    {
        $period = now()->format('Y-m');
        $rows = $this->context->bypass(fn () => ResearchUsageCounter::query()->where('period', $period)->get());
        $names = Workspace::query()->whereIn('id', $rows->pluck('workspace_id')->unique()->all())->pluck('name', 'id');
        $out = [];
        foreach ($rows->groupBy('workspace_id') as $ws => $metrics) {
            $out[] = [
                'workspace' => ['id' => $ws, 'name' => $names->get($ws)],
                'limits' => $this->settings->limitsFor((string) $ws),
                'metrics' => $metrics->mapWithKeys(fn ($m) => [$m->metric => ['used' => $m->used, 'reserved' => $m->reserved]])->all(),
            ];
        }

        return new JsonResponse(['data' => ['period' => $period, 'workspaces' => $out]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSource(string $key, ?ResearchSourceSetting $row): array
    {
        $def = ResearchSettings::SOURCES[$key];

        return [
            'source' => $key,
            'label' => $def['label'],
            'requires_credentials' => $def['requires_credentials'],
            'is_enabled' => $row !== null ? $row->is_enabled : (bool) $def['default_enabled'],
            // Only whether a secret exists — never the value or a fragment of it.
            'has_secret' => $key === 'search_console' ? (string) config('services.google.client_id') !== '' && (string) config('services.google.client_secret') !== '' : ($row?->hasSecret() ?? false),
            'secret_managed_by' => $key === 'search_console' ? 'env' : 'admin',
            'config' => $row?->config ?? (object) [],
            'rate_limit_per_minute' => $row->rate_limit_per_minute ?? 30,
            'timeout_seconds' => $row->timeout_seconds ?? 15,
            'failure_count' => $row->failure_count ?? 0,
            'last_error_code' => $row?->last_error_code,
            'last_failure_at' => $row?->last_failure_at?->toIso8601ZuluString(),
            'updated_at' => $row?->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGlobal(): array
    {
        $out = [];
        foreach (array_keys(ResearchSettings::GLOBAL_DEFAULTS) as $k) {
            $out[$k] = $this->settings->global($k);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLimit(ResearchLimit $l, ?string $workspaceName): array
    {
        $out = ['id' => $l->id, 'scope' => $l->scope_key === 'global' ? 'global' : 'workspace',
            'workspace' => $l->workspace_id !== null ? ['id' => $l->workspace_id, 'name' => $workspaceName] : null];
        foreach (array_keys(ResearchSettings::LIMIT_DEFAULTS) as $k) {
            $out[$k] = (int) $l->getAttribute($k);
        }

        return $out + ['updated_at' => $l->updated_at?->toIso8601ZuluString()];
    }
}
