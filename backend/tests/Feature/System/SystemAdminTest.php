<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Billing\Contracts\BillingReadiness;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\System\Domain\SystemAlert;
use SiteXPost\Modules\System\Domain\SystemSetting;
use SiteXPost\Modules\System\Domain\UpgradeRun;
use SiteXPost\Modules\System\Health\HealthChecker;
use SiteXPost\Modules\System\Jobs\RunUpgrade;
use SiteXPost\Modules\System\Jobs\SendTestEmail;
use SiteXPost\Modules\System\Support\RuntimeConfig;
use SiteXPost\Modules\System\Support\SystemSettings;

function sysStaff(PlatformRole $role = PlatformRole::SuperAdmin, bool $twoFactor = true): User
{
    return User::factory()->platform($role, $twoFactor)->create(['password' => sysHash()]);
}

/** One shared hash: the session guard logs out when the password hash changes between users in one test. */
function sysHash(): string
{
    static $hash = null;

    return $hash ??= Hash::make('Admin-Pass-2026!');
}

/** The test machine may lack production extensions (e.g. bcmath); requirements are tested in InstallerTest. */
function onlyLoadedExtensions(): void
{
    config(['sitex.installer.required_extensions' => array_values(array_filter((array) config('sitex.installer.required_extensions'), 'extension_loaded'))]);
}

const SYS = '/api/admin/v1/system';

it('hides system endpoints from customers and enforces platform permissions', function () {
    $customer = User::factory()->create(['password' => sysHash()]);
    $this->actingAs($customer)->getJson(SYS.'/health')->assertNotFound();
    $this->actingAs($customer)->getJson(SYS.'/setup')->assertNotFound();

    $support = sysStaff(PlatformRole::Support);
    $this->actingAs($support)->getJson(SYS.'/health')->assertOk();
    $this->actingAs($support)->getJson(SYS.'/setup')->assertForbidden();
    $this->actingAs($support)->putJson(SYS.'/settings/general', ['brand_name' => 'X', 'support_email' => 'a@b.vn', 'default_locale' => 'vi', 'default_timezone' => 'UTC'])->assertForbidden();
    $this->actingAs($support)->getJson(SYS.'/upgrade')->assertForbidden();
    $this->actingAs($support)->getJson(SYS.'/backups')->assertForbidden();

    $billing = sysStaff(PlatformRole::BillingAdmin);
    $this->actingAs($billing)->getJson(SYS.'/health')->assertForbidden();

    $noTwoFa = sysStaff(PlatformRole::SuperAdmin, false);
    $this->actingAs($noTwoFa)->getJson(SYS.'/health')->assertForbidden()->assertJsonPath('error.code', 'two_factor_required');
});

it('reports system health without secrets', function () {
    config(['sitex.ai.providers.openai.api_key' => 'sk-proj-SECRETSECRETSECRET123']);
    $r = $this->actingAs(sysStaff())->getJson(SYS.'/health')->assertOk();

    $keys = collect($r->json('data.checks'))->pluck('key')->all();
    expect($keys)->toContain('application', 'database', 'redis', 'horizon', 'scheduler', 'queue', 'storage', 'ai_providers', 'ai_spending', 'payments', 'wordpress', 'gsc', 'backup', 'ssl', 'security')
        ->and($r->json('data.status'))->toBeIn(['ok', 'warning', 'critical'])
        ->and(collect($r->json('data.checks'))->firstWhere('key', 'scheduler')['status'])->toBe('critical');
    expect(json_encode($r->json()))->not->toContain('SECRETSECRET');

    $this->artisan('sitex:system:heartbeat')->assertSuccessful();
    $r = $this->actingAs(sysStaff())->getJson(SYS.'/health')->assertOk();
    expect(collect($r->json('data.checks'))->firstWhere('key', 'scheduler')['status'])->toBe('ok');
});

it('lists the 12 setup steps with statuses computed from real configuration', function () {
    $r = $this->actingAs(sysStaff())->getJson(SYS.'/setup')->assertOk();
    $steps = collect($r->json('data.steps'))->keyBy('key');

    expect($steps->keys()->all())->toBe(['general', 'ai_providers', 'ai_models', 'email', 'plans', 'payments', 'credits', 'google', 'wordpress', 'queue', 'security', 'final'])
        ->and($steps['general']['status'])->toBe('NOT_STARTED')
        ->and($steps['ai_models']['status'])->toBe('BLOCKED') // no provider ready
        ->and($steps['plans']['status'])->toBe('NOT_STARTED')
        ->and($r->json('data.readiness'))->toBe('NOT_READY');
    foreach ($steps as $s) {
        expect($s['status'])->toBeIn(['NOT_STARTED', 'IN_PROGRESS', 'COMPLETE', 'WARNING', 'BLOCKED']);
    }
});

it('saves general settings and audits the change', function () {
    $admin = sysStaff();
    $this->actingAs($admin)->putJson(SYS.'/settings/general', [
        'brand_name' => 'SiteX Post', 'support_email' => 'hotro@example.vn', 'default_locale' => 'vi', 'default_timezone' => 'Asia/Ho_Chi_Minh',
        'company_name' => 'Công ty ABC', 'contact_phone' => '+84 912 345 678',
    ])->assertOk()->assertJsonPath('data.support_email', 'hotro@example.vn');

    $step = collect($this->actingAs($admin)->getJson(SYS.'/setup')->json('data.steps'))->firstWhere('key', 'general');
    expect($step['status'])->toBe('COMPLETE');
    $this->assertDatabaseHas('audit_logs', ['action' => 'system.settings_updated', 'actor_id' => $admin->id]);
});

it('accepts only raster logos (no SVG)', function () {
    Storage::fake('public');
    $admin = sysStaff();
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg onload="alert(1)"></svg>');
    $this->actingAs($admin)->postJson(SYS.'/settings/logo', ['logo' => $svg])->assertStatus(422);
    $this->actingAs($admin)->postJson(SYS.'/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)])->assertOk();
    expect(app(SystemSettings::class)->string('general.logo_path'))->toStartWith('branding/logo-');
});

it('stores the SMTP password encrypted and never returns it', function () {
    $admin = sysStaff();
    $this->actingAs($admin)->putJson(SYS.'/settings/mail', [
        'mailer' => 'smtp', 'host' => 'smtp.example.vn', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => 'smtp-Pass-123', 'from_address' => 'no-reply@example.vn',
    ])->assertOk()->assertJsonPath('data.password_configured', true)->assertJsonMissingPath('data.password');

    $raw = (string) SystemSetting::query()->find('mail.password')->value;
    expect($raw)->not->toContain('smtp-Pass-123')
        ->and(app(SystemSettings::class)->secret('mail.password'))->toBe('smtp-Pass-123');

    // Saving without a password keeps the stored one.
    $this->actingAs($admin)->putJson(SYS.'/settings/mail', ['mailer' => 'smtp', 'host' => 'smtp2.example.vn', 'port' => 465, 'encryption' => 'ssl', 'from_address' => 'no-reply@example.vn'])->assertOk();
    app(SystemSettings::class)->flush();
    expect(app(SystemSettings::class)->secret('mail.password'))->toBe('smtp-Pass-123');
    $this->assertDatabaseMissing('audit_logs', ['changes' => '%smtp-Pass-123%']);
    expect(json_encode(AuditLog::query()->pluck('changes')))->not->toContain('smtp-Pass-123');
});

it('queues the test email (202) and records the result', function () {
    Mail::fake();
    $admin = sysStaff();
    $this->actingAs($admin)->postJson(SYS.'/settings/mail/test', ['to' => 'me@example.vn'])->assertStatus(202);
    $last = app(SystemSettings::class)->get('mail.last_test');
    expect($last['ok'])->toBeTrue()->and($last['to'])->toBe('me@example.vn');
});

it('tests Google OAuth credentials via the token endpoint without authorising anything', function () {
    $google = ['error' => 'invalid_grant', 'status' => 400];
    Http::fake(['oauth2.googleapis.com/*' => function () use (&$google) {
        return Http::response(['error' => $google['error']], $google['status']);
    }]);
    $admin = sysStaff();
    $this->actingAs($admin)->putJson(SYS.'/settings/google', ['client_id' => '123-abc.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-secret'])->assertOk();
    app(RuntimeConfig::class)->applyGoogle();
    $this->actingAs($admin)->postJson(SYS.'/settings/google/test')->assertStatus(202);
    expect(app(SystemSettings::class)->get('google.last_test')['ok'])->toBeTrue();

    $step = collect($this->actingAs($admin)->getJson(SYS.'/setup')->json('data.steps'))->firstWhere('key', 'google');
    expect($step['status'])->toBe('COMPLETE')->and(json_encode($step))->not->toContain('GOCSPX-secret');

    $google = ['error' => 'invalid_client', 'status' => 401];
    $this->actingAs($admin)->postJson(SYS.'/settings/google/test')->assertStatus(202);
    expect(app(SystemSettings::class)->get('google.last_test'))->toMatchArray(['ok' => false, 'code' => 'invalid_client']);
});

it('creates draft Free/Starter/Pro/Business plans once, without prices', function () {
    $admin = sysStaff();
    billingPlan('pro', 500000); // already exists → must not be duplicated

    $r = $this->actingAs($admin)->postJson(SYS.'/setup/plans/draft')->assertStatus(201);
    expect($r->json('data.created'))->toBe(['free', 'starter', 'business']);
    $this->actingAs($admin)->postJson(SYS.'/setup/plans/draft')->assertOk()->assertJsonPath('data.created', []);
    expect(Plan::query()->where('code', 'pro')->count())->toBe(1)
        ->and(Plan::query()->where('code', 'starter')->first()->status)->toBe('draft');
});

it('refuses to complete setup while required steps are missing', function () {
    $r = $this->actingAs(sysStaff())->postJson(SYS.'/setup/complete')
        ->assertStatus(422)->assertJsonPath('error.code', 'setup_not_ready');
    // `security` depends on the host (.env permissions, HTTPS, APP_DEBUG), so it is not asserted here.
    expect($r->json('error.details.blocking'))->toContain('general', 'ai_providers', 'ai_models', 'email', 'plans', 'payments', 'credits', 'queue');
    expect(app(SystemSettings::class)->get('setup.completed_at'))->toBeNull();
});

it('keeps Telegram token write-only and sends test alerts through both channels', function () {
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $admin = sysStaff();
    $this->actingAs($admin)->putJson(SYS.'/settings/alerts', ['emails' => ['ops@example.vn'], 'telegram_bot_token' => '123456:ABC-def_ghi', 'telegram_chat_id' => '-100123'])
        ->assertOk()->assertJsonPath('data.telegram_bot_token_configured', true)->assertJsonMissingPath('data.telegram_bot_token');

    $this->actingAs($admin)->postJson(SYS.'/settings/alerts/test')->assertStatus(202);
    $alert = SystemAlert::query()->latest()->first();
    expect($alert->channels)->toBe(['mail' => 'sent', 'telegram' => 'sent']);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org/bot123456:ABC-def_ghi/sendMessage') && $req['chat_id'] === '-100123');
});

it('monitor alerts on degradation, respects the cooldown and announces recovery', function () {
    Mail::fake();
    $admin = sysStaff();
    // Scheduler never ran → critical.
    $this->artisan('sitex:system:monitor --no-ssl')->assertSuccessful();
    expect(SystemAlert::query()->where('check_key', 'scheduler')->where('severity', 'critical')->count())->toBe(1);

    // Still critical 5 minutes later → within cooldown, no new alert.
    $this->travel(5)->minutes();
    $this->artisan('sitex:system:monitor --no-ssl')->assertSuccessful();
    expect(SystemAlert::query()->where('check_key', 'scheduler')->where('severity', 'critical')->count())->toBe(1);

    // Heartbeat → recovered.
    $this->artisan('sitex:system:heartbeat')->assertSuccessful();
    $this->artisan('sitex:system:monitor --no-ssl')->assertSuccessful();
    expect(SystemAlert::query()->where('check_key', 'scheduler')->where('severity', 'recovered')->count())->toBe(1);
});

it('requires password re-authentication and a recent backup to start an upgrade', function () {
    onlyLoadedExtensions();
    Bus::fake([RunUpgrade::class]);
    $admin = sysStaff();

    $status = $this->actingAs($admin)->getJson(SYS.'/upgrade')->assertOk();
    expect($status->json('data.target_version'))->toBe(config('sitex.version'))
        ->and($status->json('data.current_version'))->toBeNull()
        ->and($status->json('data.changelog'))->not->toBeEmpty();

    $this->actingAs($admin)->postJson(SYS.'/upgrade', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors(['password'], 'error.details');
    $this->actingAs($admin)->postJson(SYS.'/upgrade', ['password' => 'Admin-Pass-2026!'])->assertStatus(409)->assertJsonPath('error.code', 'backup_required');
    Bus::assertNotDispatched(RunUpgrade::class);

    config(['sitex.system.backup.encryption_key' => base64_encode(random_bytes(32))]);
    $this->actingAs($admin)->postJson(SYS.'/upgrade', ['password' => 'Admin-Pass-2026!', 'backup_first' => true])->assertStatus(202);
    Bus::assertDispatched(RunUpgrade::class, fn ($job) => $job->backupFirst === true);
    $this->assertDatabaseHas('audit_logs', ['action' => 'system.upgrade_started', 'actor_id' => $admin->id]);
    // A second run while one is queued is refused.
    $this->actingAs($admin)->postJson(SYS.'/upgrade', ['password' => 'Admin-Pass-2026!', 'backup_first' => true])->assertStatus(409);
});

it('runs the upgrade job: backup first, migrate, record version', function () {
    onlyLoadedExtensions();
    config(['sitex.system.backup.encryption_key' => base64_encode(random_bytes(32)), 'sitex.system.backup.path' => sys_get_temp_dir().'/sitex-bk-'.bin2hex(random_bytes(3))]);
    $admin = sysStaff();
    $this->actingAs($admin)->postJson(SYS.'/upgrade', ['password' => 'Admin-Pass-2026!', 'backup_first' => true])->assertStatus(202);

    $run = UpgradeRun::query()->latest()->first();
    expect($run->status)->toBe('succeeded')
        ->and(collect($run->steps)->pluck('step')->all())->toBe(['backup', 'migrate', 'system_defaults', 'health_check'])
        ->and(app(SystemSettings::class)->string('system.version'))->toBe(config('sitex.version'));
    File::deleteDirectory((string) config('sitex.system.backup.path'));
});

it('refuses a manual backup without an encryption key and needs the password', function () {
    $admin = sysStaff();
    $this->actingAs($admin)->postJson(SYS.'/backups', ['password' => 'Admin-Pass-2026!'])->assertStatus(409)->assertJsonPath('error.code', 'backup_not_configured');
    config(['sitex.system.backup.encryption_key' => base64_encode(random_bytes(32))]);
    $this->actingAs($admin)->postJson(SYS.'/backups', [])->assertStatus(422);
});

it('serves the plugin zip only from the configured directory', function () {
    $dir = sys_get_temp_dir().'/sitex-plugin-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($dir);
    config(['sitex.system.plugin_zip_dir' => $dir]);
    $admin = sysStaff();
    $this->actingAs($admin)->get(SYS.'/plugin/download')->assertNotFound();

    file_put_contents($dir.'/sitex-post-1.1.0.zip', 'PK-fake');
    file_put_contents($dir.'/sitex-post-1.0.0.zip', 'PK-old');
    file_put_contents($dir.'/sitex-post-evil.zip', 'x');
    $this->actingAs($admin)->get(SYS.'/plugin/download')->assertOk()->assertDownload('sitex-post-1.1.0.zip');
    $step = collect($this->actingAs($admin)->getJson(SYS.'/setup')->json('data.steps'))->firstWhere('key', 'wordpress');
    expect($step['data']['version'])->toBe('1.1.0')->and($step['status'])->toBe('IN_PROGRESS');
    $this->actingAs($admin)->postJson(SYS.'/setup/acknowledge', ['step' => 'wordpress'])->assertOk();
    $step = collect($this->actingAs($admin)->getJson(SYS.'/setup')->json('data.steps'))->firstWhere('key', 'wordpress');
    expect($step['status'])->toBe('COMPLETE');
    File::deleteDirectory($dir);
});

it('queues test email as a job (never sends during the request)', function () {
    Bus::fake([SendTestEmail::class]);
    $this->actingAs(sysStaff())->postJson(SYS.'/settings/mail/test', ['to' => 'me@example.vn'])->assertStatus(202);
    Bus::assertDispatched(SendTestEmail::class, fn ($j) => $j->to === 'me@example.vn' && $j->queue === 'notifications');
});

it('counts payment webhooks by received_at (regression: SQLite hid a wrong column name)', function () {
    DB::table('payment_webhook_events')->insert([
        ['provider' => 'sepay', 'event_id' => 'e1', 'signature_valid' => true, 'payload_hash' => str_repeat('a', 64), 'status' => 'processed', 'received_at' => now()->subHour()],
        ['provider' => 'sepay', 'event_id' => 'e2', 'signature_valid' => false, 'payload_hash' => str_repeat('b', 64), 'status' => 'rejected', 'received_at' => now()->subDays(3)],
    ]);
    $w = app(BillingReadiness::class)->webhookHealth(now()->subDay());
    expect($w)->toMatchArray(['received' => 1, 'processed' => 1, 'rejected' => 0])->and($w['last_received_at'])->not->toBeNull();

    $check = collect(app(HealthChecker::class)->run(['payments'])['checks'])->first();
    expect($check['status'])->not->toBe('unknown');
});
