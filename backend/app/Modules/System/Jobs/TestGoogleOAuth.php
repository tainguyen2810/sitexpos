<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use SiteXPost\Modules\System\Support\RuntimeConfig;
use SiteXPost\Modules\System\Support\SystemSettings;
use Throwable;

/**
 * Checks the Google OAuth client without a user: exchanging a deliberately
 * invalid authorization code returns "invalid_grant" when the client id,
 * secret and redirect URI are accepted, and "invalid_client" /
 * "redirect_uri_mismatch" otherwise. Nothing is authorised by this call.
 */
final class TestGoogleOAuth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $userId)
    {
        $this->onQueue('notifications');
    }

    public function handle(SystemSettings $settings, RuntimeConfig $runtime): void
    {
        $runtime->applyGoogle();
        $result = ['ok' => false, 'at' => now()->toIso8601ZuluString(), 'code' => null];
        try {
            $response = Http::asForm()->timeout(15)->connectTimeout(5)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'authorization_code',
                'code' => 'sitex-setup-wizard-invalid-code',
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
                'redirect_uri' => (string) config('services.google.redirect_uri'),
            ]);
            $code = (string) ($response->json('error') ?? ($response->successful() ? 'unexpected_success' : 'http_'.$response->status()));
            $result['code'] = $code;
            $result['ok'] = $code === 'invalid_grant';
        } catch (Throwable) {
            $result['code'] = 'network_error';
        }
        $settings->set('google.last_test', $result, $this->userId);
    }
}
