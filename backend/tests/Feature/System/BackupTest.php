<?php

use Illuminate\Support\Facades\File;
use SiteXPost\Modules\System\Backup\BackupCrypto;
use SiteXPost\Modules\System\Backup\BackupManager;
use SiteXPost\Modules\System\Domain\SystemBackup;
use SiteXPost\Modules\System\Health\HealthChecker;
use SiteXPost\Modules\System\Support\MigrationStatus;

beforeEach(function () {
    $this->bk = sys_get_temp_dir().'/sitex-backup-'.bin2hex(random_bytes(4));
    config(['sitex.system.backup.path' => $this->bk, 'sitex.system.backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32)), 'sitex.system.backup.disk' => null]);
});

afterEach(fn () => File::deleteDirectory($this->bk));

it('encrypts with authenticated chunks and detects tampering and truncation', function () {
    $plain = $this->bk.'/p.bin';
    File::ensureDirectoryExists($this->bk);
    file_put_contents($plain, random_bytes(2_500_000)); // > 2 chunks
    BackupCrypto::encryptFile($plain, $this->bk.'/c.sxpbk');
    expect(file_get_contents($this->bk.'/c.sxpbk'))->toStartWith('SXPBK1');

    BackupCrypto::decryptFile($this->bk.'/c.sxpbk', $this->bk.'/d.bin');
    expect(hash_file('sha256', $this->bk.'/d.bin'))->toBe(hash_file('sha256', $plain));

    $bytes = file_get_contents($this->bk.'/c.sxpbk');
    $bytes[100] = $bytes[100] === 'a' ? 'b' : 'a';
    file_put_contents($this->bk.'/t.sxpbk', $bytes);
    expect(fn () => BackupCrypto::decryptFile($this->bk.'/t.sxpbk', $this->bk.'/x.bin'))->toThrow(RuntimeException::class, 'backup_auth_failed');

    file_put_contents($this->bk.'/tr.sxpbk', substr(file_get_contents($this->bk.'/c.sxpbk'), 0, 1_200_000));
    expect(fn () => BackupCrypto::decryptFile($this->bk.'/tr.sxpbk', $this->bk.'/y.bin'))->toThrow(RuntimeException::class);

    expect(fn () => BackupCrypto::decryptFile($this->bk.'/c.sxpbk', $this->bk.'/z.bin', random_bytes(32)))->toThrow(RuntimeException::class, 'backup_auth_failed');
});

it('creates an encrypted backup and verifies it', function () {
    $m = app(BackupManager::class);
    $b = $m->run($m->start('manual', null));

    expect($b->status)->toBe('succeeded')
        ->and($b->contents)->toContain('database', 'storage')
        ->and($b->offsite_status)->toBe('not_configured')
        ->and(is_file($this->bk.'/'.$b->file_name))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($this->bk.'/'.$b->file_name)), -4))->toBe('0600');
    // The archive is not readable without the backup key (no plaintext tar header / SQL).
    expect(file_get_contents($this->bk.'/'.$b->file_name))->not->toContain('manifest.json');

    $r = $m->verify($b);
    expect($r['ok'])->toBeTrue()->and($r['checks'])->toHaveKeys(['checksum', 'decrypt', 'manifest', 'sha256:database.sql', 'database_dump'])
        ->and($b->refresh()->verify_status)->toBe('verified');

    $this->artisan('sitex:backup:decrypt', ['file' => $this->bk.'/'.$b->file_name, 'out' => $this->bk.'/restore.tar'])->assertSuccessful();
    expect((new PharData($this->bk.'/restore.tar'))->offsetExists('manifest.json'))->toBeTrue();
});

it('fails verification when the file was altered', function () {
    $m = app(BackupManager::class);
    $b = $m->run($m->start('manual', null));
    file_put_contents($this->bk.'/'.$b->file_name, 'garbage', FILE_APPEND);
    expect($m->verify($b)['ok'])->toBeFalse()->and($b->refresh()->verify_status)->toBe('failed');
});

it('records a failed backup without a key and the health check turns critical', function () {
    config(['sitex.system.backup.encryption_key' => null]);
    $m = app(BackupManager::class);
    $b = $m->run($m->start('manual', null));
    expect($b->status)->toBe('failed')->and($b->error)->toContain('backup_key_not_configured');
    $check = collect(app(HealthChecker::class)->run(['backup'])['checks'])->first();
    expect($check['status'])->toBe('critical');
});

it('refuses a restore test into the live database or a non-scratch name', function () {
    $m = app(BackupManager::class);
    $b = $m->run($m->start('manual', null));
    $r = $m->verify($b, 'production');
    expect($r['ok'])->toBeFalse()->and($r['error'])->toContain('restore_target_not_allowed');
});

it('prunes old files but keeps the newest successful backup', function () {
    $m = app(BackupManager::class);
    $old = $m->run($m->start('schedule', null));
    $this->travel(20)->days();
    expect($m->prune())->toBe(0); // only one successful backup: kept
    $new = $m->run($m->start('schedule', null));
    expect($m->prune())->toBe(1)
        ->and(is_file($this->bk.'/'.$old->file_name))->toBeFalse()
        ->and(is_file($this->bk.'/'.$new->file_name))->toBeTrue();
});

it('runs on schedule only when enabled and due', function () {
    $this->artisan('sitex:backup:run --scheduled')->assertSuccessful();
    expect(SystemBackup::query()->count())->toBe(0);
});

it('flags destructive pending migrations but not drops inside down()', function () {
    $dir = $this->bk.'/m';
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/a.php', "<?php return new class { public function up(): void { Schema::create('x', fn(\$t) => \$t->id()); } public function down(): void { Schema::dropIfExists('x'); } };");
    file_put_contents($dir.'/b.php', "<?php return new class { public function up(): void { Schema::table('x', fn(\$t) => \$t->dropColumn('y')); } };");
    file_put_contents($dir.'/c.php', "<?php return new class { public function up(): void { Schema::table('x', fn(\$t) => \$t->string('y', 10)->change()); } };");
    $s = app(MigrationStatus::class);
    expect($s->isDestructive($dir.'/a.php'))->toBeFalse()
        ->and($s->isDestructive($dir.'/b.php'))->toBeTrue()
        ->and($s->isDestructive($dir.'/c.php'))->toBeTrue();
    // Every migration shipped so far is additive in up().
    expect(collect($s->files())->filter(fn ($p) => $s->isDestructive($p))->keys()->all())->toBe([]);
});

it('prints a new backup key without writing .env', function () {
    $env = base_path('.env');
    $before = is_file($env) ? hash_file('sha256', $env) : null;
    $this->artisan('sitex:backup:key')->expectsOutputToContain('SITEX_BACKUP_ENCRYPTION_KEY=base64:')->assertSuccessful();
    expect(is_file($env) ? hash_file('sha256', $env) : null)->toBe($before);
});
