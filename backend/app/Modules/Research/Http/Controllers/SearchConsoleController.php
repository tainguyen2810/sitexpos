<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\SearchConsoleConnection;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Http\Requests\SearchConsolePropertyRequest;
use SiteXPost\Modules\Research\Queries\SearchConsoleAccessQuery;
use SiteXPost\Modules\Research\SearchConsole\GoogleSearchConsole;
use SiteXPost\Modules\Research\SearchConsole\SearchConsoleException;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Google Search Console OAuth per site. The OAuth `state` is encrypted,
 * bound to user + workspace + site + campaign and single-use (10 min).
 * Tokens never leave the server.
 */
final class SearchConsoleController
{
    public function show(Workspace $workspace, SeoCampaign $campaign, GoogleSearchConsole $gsc): JsonResponse
    {
        Gate::authorize('view', $campaign);

        return new JsonResponse(['data' => self::data(SearchConsoleConnection::query()->where('site_id', $campaign->site_id)->first(), $gsc)]);
    }

    public function connect(Request $request, Workspace $workspace, SeoCampaign $campaign, GoogleSearchConsole $gsc): JsonResponse
    {
        Gate::authorize('manage', $campaign);
        if (! $gsc->isConfigured()) {
            throw ApiException::conflict('gsc_not_configured', __('research.errors.gsc_not_configured'));
        }
        $state = SearchConsoleAccessQuery::state($workspace->id, $campaign->site_id, (string) $request->user()?->getAuthIdentifier(), $campaign->id, 'research');

        return new JsonResponse(['data' => ['authorization_url' => $gsc->authorizationUrl($state)]]);
    }

    /**
     * Browser redirect from Google (no SPA session needed: the encrypted,
     * single-use state carries and authenticates the context).
     */
    public function callback(Request $request, GoogleSearchConsole $gsc, TenantContext $context): RedirectResponse
    {
        $front = rtrim((string) config('sitex.frontend_url'), '/');
        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return redirect()->away($front.'/?gsc=invalid_state');
        }
        if (! is_array($state) || ($state['e'] ?? 0) < now()->timestamp || ! Cache::pull('gsc-state:'.($state['n'] ?? ''))) {
            return redirect()->away($front.'/?gsc=invalid_state');
        }
        // Phase 8: the site-level flow (Analytics settings) has no campaign.
        $back = ($state['r'] ?? 'research') === 'analytics' || ($state['c'] ?? null) === null
            ? $front.'/w/'.$state['w'].'/analytics/settings'
            : $front.'/w/'.$state['w'].'/research/'.$state['c'].'/settings';
        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->away($back.'?gsc=denied');
        }
        $workspace = Workspace::query()->find($state['w']);
        if ($workspace === null || $workspace->status !== WorkspaceStatus::Active) {
            return redirect()->away($front.'/?gsc=invalid_state');
        }

        $result = $context->runAs($workspace, null, function () use ($gsc, $code, $state): string {
            $site = app(SiteDirectory::class)->find((string) $state['s']);
            if ($site === null) {
                return 'invalid_state';
            }
            try {
                $tokens = $gsc->exchange($code);
            } catch (SearchConsoleException $e) {
                return $e->errorCode;
            }
            $conn = SearchConsoleConnection::query()->where('site_id', $site['id'])->first() ?? new SearchConsoleConnection;
            $conn->forceFill([
                'site_id' => $site['id'], 'google_email' => $tokens['email'], 'access_token_encrypted' => $tokens['access_token'],
                'refresh_token_encrypted' => $tokens['refresh_token'] ?? $conn->refresh_token_encrypted,
                'token_expires_at' => now()->addSeconds(max(60, $tokens['expires_in'])), 'status' => 'needs_property',
                'connected_by' => (string) $state['u'], 'last_error_code' => null,
            ])->save();
            try {
                $property = GoogleSearchConsole::matchProperty($site['url'], $gsc->sites($conn));
            } catch (SearchConsoleException) {
                $property = null;
            }
            $conn->forceFill(['property_url' => $property, 'status' => $property !== null ? 'active' : 'needs_property'])->save();
            Audit::record('research.search_console.connected', $conn, ['property' => $property], actorId: (string) $state['u']);

            return $property !== null ? 'connected' : 'needs_property';
        });

        return redirect()->away($back.'?gsc='.urlencode($result));
    }

    public function properties(Workspace $workspace, SeoCampaign $campaign, GoogleSearchConsole $gsc): JsonResponse
    {
        Gate::authorize('manage', $campaign);
        $conn = SearchConsoleConnection::query()->where('site_id', $campaign->site_id)->first()
            ?? throw ApiException::conflict('gsc_not_connected', __('research.errors.gsc_not_connected'));
        try {
            return new JsonResponse(['data' => $gsc->sites($conn)]);
        } catch (SearchConsoleException $e) {
            throw ApiException::conflict('gsc_'.$e->errorCode, $e->getMessage());
        }
    }

    public function setProperty(SearchConsolePropertyRequest $request, Workspace $workspace, SeoCampaign $campaign, GoogleSearchConsole $gsc): JsonResponse
    {
        Gate::authorize('manage', $campaign);
        $conn = SearchConsoleConnection::query()->where('site_id', $campaign->site_id)->first()
            ?? throw ApiException::conflict('gsc_not_connected', __('research.errors.gsc_not_connected'));
        $property = $request->string('property_url')->toString();
        try {
            $allowed = array_column($gsc->sites($conn), 'site_url');
        } catch (SearchConsoleException $e) {
            throw ApiException::conflict('gsc_'.$e->errorCode, $e->getMessage());
        }
        if (! in_array($property, $allowed, true)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['property_url' => [__('research.errors.gsc_property_unknown')]]);
        }
        $conn->forceFill(['property_url' => $property, 'status' => 'active'])->save();
        Audit::record('research.search_console.property_set', $conn, ['property' => $property]);

        return new JsonResponse(['data' => self::data($conn, $gsc)]);
    }

    public function disconnect(Workspace $workspace, SeoCampaign $campaign, GoogleSearchConsole $gsc): JsonResponse
    {
        Gate::authorize('manage', $campaign);
        $conn = SearchConsoleConnection::query()->where('site_id', $campaign->site_id)->first();
        if ($conn !== null) {
            // Phase 8: also revoke the grant at Google (best effort).
            $revoked = $gsc->revoke($conn);
            Audit::record('research.search_console.disconnected', $conn, ['revoked_at_google' => $revoked]);
            $conn->delete();
        }

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(?SearchConsoleConnection $c, GoogleSearchConsole $gsc): array
    {
        return [
            'configured' => $gsc->isConfigured(),
            'connected' => $c !== null,
            'status' => $c->status ?? 'not_connected',
            'property_url' => $c?->property_url,
            'google_email' => $c?->google_email,
            'last_synced_at' => $c?->last_synced_at?->toIso8601ZuluString(),
            'last_error_code' => $c?->last_error_code,
        ];
    }
}
