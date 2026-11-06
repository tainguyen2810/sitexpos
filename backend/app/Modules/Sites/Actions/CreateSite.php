<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Security\SsrfViolation;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Domain\Site;

final class CreateSite
{
    public function __construct(
        private readonly IssuePairingCode $issuePairingCode,
        private readonly SsrfGuard $guard,
    ) {}

    /**
     * @param  array{name: string, url: string, content_language?: string|null}  $data
     * @return array{site: Site, pairing_code: string, expires_at: Carbon}
     */
    public function handle(User $user, array $data): array
    {
        $url = Site::normalizeUrl($data['url']);
        self::assertPublicUrl($this->guard, $url);

        $hash = Site::hashUrl($url);
        if (Site::query()->where('url_hash', $hash)->exists()) {
            throw ApiException::conflict('site_exists', __('sites.errors.site_exists'));
        }

        return DB::transaction(function () use ($user, $data, $url, $hash): array {
            $site = new Site;
            $site->fill([
                'name' => $data['name'],
                'content_language' => $data['content_language'] ?? 'vi',
            ]);
            $site->forceFill(['url' => $url, 'url_hash' => $hash, 'created_by' => $user->id])->save();

            Audit::record('site.created', $site, ['url' => $url]);

            [$code, $expiresAt] = $this->issuePairingCode->create($site, $user);

            return ['site' => $site, 'pairing_code' => $code, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Rejects private/loopback/metadata targets up front (they are checked
     * again, with IP pinning, before every request).
     */
    public static function assertPublicUrl(SsrfGuard $guard, string $url): void
    {
        try {
            $guard->assertSafe($url);
        } catch (SsrfViolation $e) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), [
                'url' => [__('sites.errors.blocked_url', ['reason' => $e->getMessage()])],
            ]);
        }
    }
}
