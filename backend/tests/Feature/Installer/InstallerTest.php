<?php

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Installer\Actions\RunInstallation;
use SiteXPost\Modules\Installer\Support\DatabaseTarget;
use SiteXPost\Modules\Installer\Support\EnvWriter;
use SiteXPost\Modules\Installer\Support\InstallerStorage;
use SiteXPost\Modules\Installer\Support\SetupToken;
use SiteXPost\Modules\System\Support\SystemSettings;
use Tests\Support\FakeDatabaseTarget;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sitex-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);
    $this->envPath = $this->dir.'/.env';
    config([
        'sitex.installer.state_path' => $this->dir.'/state',
        'sitex.installer.env_path' => $this->envPath,
        'sitex.installer.cache_store' => 'array',
        'sitex.installer.installed' => false,
        'sitex.installer.enabled' => true,
    ]);
    $this->target = new FakeDatabaseTarget;
    app()->instance(DatabaseTarget::class, $this->target);
    $this->token = app(SetupToken::class)->issue(false);
    $this->h = ['X-Setup-Token' => $this->token];
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function fillInstallerSteps(array $headers): void
{
    test()->putJson('/install/api/database', ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'sitexpost', 'username' => 'sitex', 'password' => 'Db$ecret"1\\x'], $headers)->assertOk();
    test()->putJson('/install/api/application', [
        'app_name' => 'SiteX Post', 'app_url' => 'https://api.example.vn', 'frontend_url' => 'https://app.example.vn', 'admin_url' => 'https://admin.example.vn',
        'locale' => 'vi', 'timezone' => 'Asia/Ho_Chi_Minh', 'mail' => ['mailer' => 'log'],
    ], $headers)->assertOk();
    test()->putJson('/install/api/queue', ['driver' => 'database'], $headers)->assertOk();
    test()->putJson('/install/api/admin', ['name' => 'Chủ hệ thống', 'email' => 'Owner@Example.vn', 'password' => 'Very-Strong-Pass-2026!', 'password_confirmation' => 'Very-Strong-Pass-2026!'], $headers)->assertOk();
}

it('renders the wizard and drops a private setup token file when no token exists', function () {
    app(SetupToken::class)->revoke();

    $this->get('/install')->assertOk()->assertSee('Trình cài đặt', false)->assertHeader('Content-Security-Policy');

    $file = $this->dir.'/state/'.SetupToken::FILE_PLAIN;
    expect(is_file($file))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600');
    $plain = trim((string) file_get_contents($file));
    $this->getJson('/install/api/state', ['X-Setup-Token' => $plain])->assertOk()->assertJsonPath('data.installed', false);
});

it('rejects API calls without a valid setup token and rate-limits guessing', function () {
    $this->getJson('/install/api/state')->assertStatus(401)->assertJsonPath('error.code', 'invalid_setup_token');
    for ($i = 0; $i < 9; $i++) {
        $this->getJson('/install/api/state', ['X-Setup-Token' => 'wrong-'.$i])->assertStatus(401);
    }
    // 11th attempt — even with the right token — is throttled.
    $this->getJson('/install/api/state', ['X-Setup-Token' => 'wrong-x'])->assertStatus(429);
    $this->getJson('/install/api/state', $this->h)->assertStatus(429);
});

it('rejects an expired token', function () {
    $this->travel(25)->hours();
    $this->getJson('/install/api/state', $this->h)->assertStatus(401);
});

it('returns 404 when the installer is disabled by config', function () {
    config(['sitex.installer.enabled' => false]);
    $this->getJson('/install/api/state', $this->h)->assertNotFound();
    $this->get('/install')->assertNotFound();
});

it('runs environment checks with PASS/WARNING/FAIL and aaPanel fixes', function () {
    $r = $this->getJson('/install/api/environment', $this->h)->assertOk();
    $keys = collect($r->json('data.checks'))->pluck('key');
    expect($keys)->toContain('php_version', 'mysql_driver', 'writable_storage', 'env_writable', 'disk_space', 'https', 'web_root')
        ->and(collect($r->json('data.checks'))->pluck('status')->unique()->diff(['PASS', 'WARNING', 'FAIL'])->all())->toBe([]);
    expect(collect($r->json('data.checks'))->firstWhere('key', 'https')['fix'])->toContain('aaPanel');
});

it('refuses a database that already holds data and never stores it', function () {
    $this->target->result['empty'] = false;
    $this->target->result['table_count'] = 12;

    $this->putJson('/install/api/database', ['host' => 'localhost', 'port' => 3306, 'database' => 'old', 'username' => 'u', 'password' => 'p'], $this->h)
        ->assertStatus(409)->assertJsonPath('error.code', 'database_not_empty');
    expect(is_file($this->dir.'/state/pending.json'))->toBeFalse();
});

it('validates database input (no injection through host or name)', function () {
    $this->postJson('/install/api/database/test', ['host' => 'localhost;rm -rf /', 'port' => 99999, 'database' => 'a b', 'username' => ''], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors(['host', 'port', 'database', 'username'], 'error.details');
});

it('never echoes or stores secrets in plaintext', function () {
    fillInstallerSteps($this->h);

    $pending = (string) file_get_contents($this->dir.'/state/pending.json');
    expect($pending)->not->toContain('Db$ecret')->not->toContain('Very-Strong-Pass-2026!');

    $state = $this->getJson('/install/api/state', $this->h)->assertOk();
    expect(json_encode($state->json()))->not->toContain('Db$ecret')->not->toContain('$2y$')
        ->and($state->json('data.config.database.password_set'))->toBeTrue()
        ->and($state->json('data.config.admin.password_hash_set'))->toBeTrue();
});

it('enforces a strong Super Admin password', function () {
    $this->putJson('/install/api/admin', ['name' => 'A', 'email' => 'a@example.vn', 'password' => 'password', 'password_confirmation' => 'password'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors(['password'], 'error.details');
    $this->putJson('/install/api/admin', ['name' => 'A', 'email' => 'admin@example.vn', 'password' => 'Admin-Strong-99!x', 'password_confirmation' => 'Admin-Strong-99!x'], $this->h)
        ->assertStatus(422);
});

it('installs end to end, then locks itself', function () {
    fillInstallerSteps($this->h);

    $r = $this->postJson('/install/api/run', [], $this->h)->assertOk();
    expect($r->json('data.status'))->toBe('completed')
        ->and($r->json('data.redirect'))->toBe('https://admin.example.vn/setup')
        ->and($r->json('data.steps'))->each->toBe('done');

    $admin = User::query()->where('email', 'owner@example.vn')->firstOrFail();
    expect($admin->platform_role)->toBe(PlatformRole::SuperAdmin)
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and(password_verify('Very-Strong-Pass-2026!', $admin->password))->toBeTrue();

    // .env: generated key, production flags, secrets round-trip through Dotenv.
    $env = Dotenv::parse((string) file_get_contents($this->envPath));
    expect($env['APP_KEY'])->toStartWith('base64:')
        ->and($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['SITEX_INSTALLED'])->toBe('true')
        ->and($env['DB_PASSWORD'])->toBe('Db$ecret"1\\x')
        ->and($env['SESSION_DOMAIN'])->toBe('.example.vn')
        ->and($env['SANCTUM_STATEFUL_DOMAINS'])->toBe('app.example.vn,admin.example.vn');
    expect(substr(sprintf('%o', fileperms($this->envPath)), -4))->toBe('0640');

    // Lock signals, token revoked, pending secrets removed, audit trail.
    expect(is_file($this->dir.'/state/installed.json'))->toBeTrue()
        ->and(is_file($this->dir.'/state/pending.json'))->toBeFalse()
        ->and(is_file($this->dir.'/state/token.json'))->toBeFalse()
        ->and(app(SystemSettings::class)->get('system.installed_at'))->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'system.installed', 'subject_id' => $admin->id]);

    // Any further installer call is refused, even with a fresh token.
    $fresh = app(SetupToken::class)->issue(false);
    $this->postJson('/install/api/run', [], ['X-Setup-Token' => $fresh])->assertStatus(403)->assertJsonPath('error.code', 'installer_locked');
    $this->putJson('/install/api/admin', ['name' => 'X', 'email' => 'x@evil.test', 'password' => 'Very-Strong-Pass-2026!', 'password_confirmation' => 'Very-Strong-Pass-2026!'], ['X-Setup-Token' => $fresh])->assertStatus(403);
    $this->get('/install')->assertStatus(403)->assertSee('đã được cài đặt', false);
    expect(User::query()->where('platform_role', 'SUPER_ADMIN')->count())->toBe(1);
});

it('stays locked when the lock file is deleted (database signals)', function () {
    fillInstallerSteps($this->h);
    $this->postJson('/install/api/run', [], $this->h)->assertOk();
    unlink($this->dir.'/state/installed.json');

    $fresh = app(SetupToken::class)->issue(false);
    $this->getJson('/install/api/state', ['X-Setup-Token' => $fresh])->assertStatus(403)->assertJsonPath('error.code', 'installer_locked');
});

it('treats a server with an existing Super Admin (CLI setup) as installed', function () {
    $u = User::factory()->create();
    $u->forceFill(['platform_role' => PlatformRole::SuperAdmin])->save();

    $this->getJson('/install/api/state', $this->h)->assertStatus(403);
    $this->artisan('sitex:install:token')->assertFailed();
});

it('fails safely and can be retried when a Super Admin appears mid-way', function () {
    fillInstallerSteps($this->h);
    // A concurrent/legacy admin with the same email blocks admin creation.
    User::factory()->create(['email' => 'owner@example.vn']);

    $r = $this->postJson('/install/api/run', [], $this->h)->assertStatus(500);
    expect($r->json('data.status'))->toBe('failed')
        ->and($r->json('data.failed_step'))->toBe('create_admin')
        ->and($r->json('data.error_code'))->toBe('email_taken')
        ->and(is_file($this->dir.'/state/installed.json'))->toBeFalse();

    // Fix: change the admin email and retry — previous steps are idempotent.
    $this->putJson('/install/api/admin', ['name' => 'Chủ', 'email' => 'boss@example.vn', 'password' => 'Very-Strong-Pass-2026!', 'password_confirmation' => 'Very-Strong-Pass-2026!'], $this->h)->assertOk();
    $this->postJson('/install/api/run', [], $this->h)->assertOk()->assertJsonPath('data.status', 'completed');
    expect(User::query()->where('platform_role', 'SUPER_ADMIN')->count())->toBe(1);
});

it('never replaces an existing APP_KEY', function () {
    file_put_contents($this->envPath, "APP_KEY=base64:EXISTINGKEYEXISTINGKEYEXISTINGKEYEXISTINGKE=\nCUSTOM_VALUE=keep-me\n");
    fillInstallerSteps($this->h);
    $this->postJson('/install/api/run', [], $this->h)->assertOk();

    $env = Dotenv::parse((string) file_get_contents($this->envPath));
    expect($env['APP_KEY'])->toBe('base64:EXISTINGKEYEXISTINGKEYEXISTINGKEYEXISTINGKE=')
        ->and($env['CUSTOM_VALUE'])->toBe('keep-me');
    expect(glob($this->dir.'/state/env-backup-*.env'))->not->toBeEmpty();
});

it('serves filled-in Supervisor/Cron/Nginx templates without running anything', function () {
    $this->get('/install/api/templates/supervisor-horizon', $this->h)->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="sitexpost-supervisor-horizon.conf"')
        ->assertSee('artisan horizon', false)->assertSee(base_path(), false);
    $this->get('/install/api/templates/cron', $this->h)->assertOk()->assertSee('schedule:run', false);
    $this->get('/install/api/templates/evil', $this->h)->assertNotFound();
});

it('computes the shared session cookie domain', function () {
    expect(RunInstallation::sessionDomain(['https://api.example.vn', 'https://app.example.vn', 'https://admin.example.vn']))->toBe('.example.vn')
        ->and(RunInstallation::sessionDomain(['http://127.0.0.1:8000', 'http://127.0.0.1:5173']))->toBeNull()
        ->and(RunInstallation::sessionDomain(['https://a.com', 'https://b.net']))->toBeNull();
});

it('quotes .env values so Dotenv reads them back exactly', function () {
    $writer = app(EnvWriter::class);
    $values = ['A' => 'p@ss w$rd "x" \\ y', 'B' => 'simple', 'C' => null, 'D' => true, 'E' => '${APP_KEY}'];
    $parsed = Dotenv::parse($writer->merge("A=old\n#B=commented\n", $values));
    expect($parsed['A'])->toBe('p@ss w$rd "x" \\ y')->and($parsed['B'])->toBe('simple')->and($parsed['C'])->toBe('null')
        ->and($parsed['D'])->toBe('true')->and($parsed['E'])->toBe('${APP_KEY}');
});

it('keeps installer files private', function () {
    $storage = app(InstallerStorage::class);
    $storage->writeJson('x.json', ['a' => 1]);
    expect(substr(sprintf('%o', fileperms($storage->path('x.json'))), -4))->toBe('0600')
        ->and(is_file($storage->dir().'/.htaccess'))->toBeTrue();
});
