<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Queries;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Contracts\SearchConsoleAccess;
use SiteXPost\Modules\Research\Domain\SearchConsoleConnection;
use SiteXPost\Modules\Research\SearchConsole\GoogleSearchConsole;
use SiteXPost\Modules\Research\SearchConsole\SearchConsoleException;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

final class SearchConsoleAccessQuery implements SearchConsoleAccess
{
    public function __construct(
        private readonly GoogleSearchConsole $gsc,
        private readonly TenantContext $context,
        private readonly SiteDirectory $sites,
    ) {}

    public function configured(): bool
    {
        return $this->gsc->isConfigured();
    }

    public function status(string $siteId): array
    {
        $c = $this->connection($siteId);

        return [
            'connected' => $c !== null,
            'status' => $c?->status ?? 'not_connected',
            'property_url' => $c?->property_url,
            'google_email' => $c?->google_email,
            'last_synced_at' => $c?->last_synced_at?->toIso8601ZuluString(),
            'last_error_code' => $c?->last_error_code,
        ];
    }

    public function authorizationUrl(string $siteId, string $userId): string
    {
        if (! $this->gsc->isConfigured()) {
            throw new SearchConsoleException('not_configured', 'Google OAuth client is not configured.');
        }

        return $this->gsc->authorizationUrl(self::state($this->context->workspaceId(), $siteId, $userId, null, 'analytics'));
    }

    /**
     * Shared by the Phase 4 campaign flow and the Phase 8 site flow.
     */
    public static function state(string $workspaceId, string $siteId, string $userId, ?string $campaignId, string $return): string
    {
        $nonce = Str::random(40);
        Cache::put('gsc-state:'.$nonce, 1, 600);

        return Crypt::encryptString((string) json_encode([
            'n' => $nonce, 'u' => $userId, 'w' => $workspaceId, 's' => $siteId, 'c' => $campaignId, 'r' => $return,
            'e' => now()->addMinutes(10)->timestamp,
        ]));
    }

    public function properties(string $siteId): array
    {
        return $this->gsc->sites($this->required($siteId));
    }

    public function selectProperty(string $siteId, string $propertyUrl, ?string $userId): void
    {
        $conn = $this->required($siteId);
        $allowed = array_column(array_filter($this->gsc->sites($conn), fn ($s) => $s['permission'] !== 'siteUnverifiedUser'), 'site_url');
        if (! in_array($propertyUrl, $allowed, true)) {
            throw new SearchConsoleException('property_unknown', 'The property is not accessible with this Google account.');
        }
        $conn->forceFill(['property_url' => $propertyUrl, 'status' => 'active', 'last_error_code' => null])->save();
        Audit::record('analytics.gsc.property_set', $conn, ['property' => $propertyUrl], actorId: $userId);
    }

    public function verifyProperty(string $siteId): string
    {
        $conn = $this->required($siteId);
        if ($conn->property_url === null) {
            throw new SearchConsoleException('needs_property', 'No Search Console property is mapped to this site.');
        }
        $ok = collect($this->gsc->sites($conn))->contains(fn ($s) => $s['site_url'] === $conn->property_url && $s['permission'] !== 'siteUnverifiedUser');
        if (! $ok) {
            $conn->forceFill(['status' => 'needs_property', 'last_error_code' => 'property_revoked'])->save();
            throw new SearchConsoleException('property_revoked', 'The mapped property is no longer accessible.');
        }

        return (string) $conn->property_url;
    }

    public function query(string $siteId, array $body): array
    {
        $conn = $this->required($siteId);
        if ($conn->property_url === null || $conn->status !== 'active') {
            throw new SearchConsoleException('needs_property', 'No active Search Console property for this site.');
        }
        try {
            return $this->gsc->searchAnalytics($conn, (string) $conn->property_url, $body);
        } catch (SearchConsoleException $e) {
            if ($e->errorCode === 'reauth_required') {
                $conn->forceFill(['status' => 'reauth_required', 'last_error_code' => 'reauth_required'])->save();
            }
            throw $e;
        }
    }

    public function markSynced(string $siteId, ?string $errorCode): void
    {
        $conn = $this->connection($siteId);
        if ($conn === null) {
            return;
        }
        $conn->forceFill($errorCode === null ? ['last_synced_at' => now(), 'last_error_code' => null] : ['last_error_code' => mb_substr($errorCode, 0, 64)])->save();
    }

    public function disconnect(string $siteId, ?string $userId): bool
    {
        $conn = $this->connection($siteId);
        if ($conn === null) {
            return false;
        }
        $revoked = $this->gsc->revoke($conn);
        Audit::record('analytics.gsc.disconnected', $conn, ['revoked_at_google' => $revoked], actorId: $userId);
        $conn->delete();

        return $revoked;
    }

    private function connection(string $siteId): ?SearchConsoleConnection
    {
        return SearchConsoleConnection::query()->where('site_id', $siteId)->first();
    }

    private function required(string $siteId): SearchConsoleConnection
    {
        if ($this->sites->find($siteId) === null) {
            throw new SearchConsoleException('not_connected', 'Unknown site.');
        }

        return $this->connection($siteId) ?? throw new SearchConsoleException('not_connected', 'Search Console is not connected for this site.');
    }
}
