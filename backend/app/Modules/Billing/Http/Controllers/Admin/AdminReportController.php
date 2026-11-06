<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Accounting\Reports;
use SiteXPost\Modules\Billing\Credits\CreditConverter;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Http\BillingPresenter;
use SiteXPost\Modules\Billing\Http\Requests\Admin\BillingSettingsRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\ProviderRequest;
use SiteXPost\Modules\Billing\Payments\Adapters\AdapterRegistry;
use SiteXPost\Modules\Billing\Support\BillingSettings;

/**
 * Revenue dashboard, usage & costs, customer profitability, billing
 * settings and payment providers.
 */
final class AdminReportController
{
    public function revenue(Request $request, Reports $reports): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return new JsonResponse(['data' => $reports->revenue($from, $to)]);
    }

    public function costs(Request $request, Reports $reports): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return new JsonResponse(['data' => $reports->costs($from, $to)]);
    }

    public function profitability(Request $request, Reports $reports): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return new JsonResponse(['data' => $reports->profitability($from, $to)]);
    }

    public function settings(BillingSettings $settings, CreditConverter $converter): JsonResponse
    {
        return new JsonResponse(['data' => [
            'settings' => $settings->all(),
            'credit_policy_configured' => $converter->current() !== null,
            'adapters' => array_keys(AdapterRegistry::ADAPTERS),
        ]]);
    }

    public function updateSettings(BillingSettingsRequest $request, BillingSettings $settings): JsonResponse
    {
        $settings->update($request->validated(), (string) $request->user()?->getAuthIdentifier());
        Audit::record('billing.settings_updated', null, $request->validated(), null, (string) $request->user()?->getAuthIdentifier(), 'staff');

        return new JsonResponse(['data' => ['settings' => $settings->all()]]);
    }

    public function providers(): JsonResponse
    {
        return new JsonResponse(['data' => PaymentProviderConfig::query()->orderBy('display_order')->get()->map(fn ($c) => BillingPresenter::provider($c))]);
    }

    public function upsertProvider(ProviderRequest $request, string $code, AdapterRegistry $registry): JsonResponse
    {
        if (! preg_match('/^[a-z0-9_-]{2,40}$/', $code) || $code === 'manual') {
            throw new ApiException(422, 'invalid_provider_code', __('billing.errors.invalid_provider_code'));
        }
        $d = $request->validated();
        $c = PaymentProviderConfig::query()->where('code', $code)->first() ?? new PaymentProviderConfig;
        $adapter = $registry->get((string) ($d['adapter'] ?? $c->adapter ?? ''));
        $c->forceFill([
            'code' => $code, 'adapter' => $adapter->key(), 'name' => $d['name'] ?? $c->name ?? $code,
            'settings' => array_merge(is_array($c->settings) ? $c->settings : [], $d['settings'] ?? []),
            'display_order' => $d['display_order'] ?? $c->display_order ?? 0, 'updated_by' => (string) $request->user()?->getAuthIdentifier(),
        ]);
        if (isset($d['credentials']) && is_array($d['credentials'])) {
            $c->setCredentials(array_map('strval', $d['credentials']));
        }
        $enable = (bool) ($d['is_enabled'] ?? $c->is_enabled ?? false);
        if ($enable) {
            $req = $adapter->requiredConfig();
            $missing = array_values(array_merge(
                array_filter($req['settings'], fn ($k) => (string) $c->setting($k, '') === ''),
                array_filter($req['credentials'], fn ($k) => $c->secret($k) === null),
            ));
            if ($missing !== []) {
                throw new ApiException(422, 'provider_config_incomplete', __('billing.errors.provider_config_incomplete'), ['missing' => $missing]);
            }
        }
        $c->forceFill(['is_enabled' => $enable])->save();
        // Credentials are never logged: only which keys changed.
        Audit::record('billing.provider_updated', $c, ['code' => $code, 'enabled' => $enable, 'credentials_changed' => array_keys($d['credentials'] ?? [])], null, (string) $request->user()?->getAuthIdentifier(), 'staff');

        return new JsonResponse(['data' => BillingPresenter::provider($c)]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        try {
            $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->query('from'))->startOfDay() : CarbonImmutable::now()->startOfMonth();
            $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->query('to'))->endOfDay() : CarbonImmutable::now();
        } catch (\Throwable) {
            throw new ApiException(422, 'invalid_range', __('billing.errors.invalid_range'));
        }
        if ($to->lessThan($from) || $from->diffInDays($to) > 400) {
            throw new ApiException(422, 'invalid_range', __('billing.errors.invalid_range'));
        }

        return [$from->utc(), $to->utc()];
    }
}
