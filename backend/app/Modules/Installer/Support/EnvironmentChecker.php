<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

use Illuminate\Http\Request;
use PDO;

/**
 * Server requirement checks for the installer (and the System Health page).
 * Each check: key, status PASS|WARNING|FAIL, value shown to the operator and an
 * aaPanel-oriented fix. Nothing here executes commands.
 */
final class EnvironmentChecker
{
    /** Functions aaPanel disables by default that Laravel/Horizon need. */
    private const NEEDED_FUNCTIONS = ['proc_open', 'putenv', 'symlink', 'pcntl_signal', 'pcntl_alarm'];

    /** Extensions without which a feature degrades but the app still runs. */
    private const SOFT_EXTENSIONS = ['intl'];

    public function __construct(private readonly InstallerStorage $storage) {}

    /**
     * @return array{checks: list<array{key: string, group: string, status: string, value: string, fix: ?string}>, summary: array{pass: int, warning: int, fail: int}, can_continue: bool}
     */
    public function run(?Request $request = null): array
    {
        $checks = [];
        $add = static function (string $group, string $key, string $status, string $value, ?string $fix = null) use (&$checks): void {
            $checks[] = ['key' => $key, 'group' => $group, 'status' => $status, 'value' => $value, 'fix' => $status === 'PASS' ? null : $fix];
        };

        $min = (string) config('sitex.installer.min_php', '8.3.0');
        $add('php', 'php_version', version_compare(PHP_VERSION, $min, '>=') ? 'PASS' : 'FAIL', PHP_VERSION.' (≥ '.$min.')', __('installer.fix.php_version', ['min' => $min]));

        foreach ((array) config('sitex.installer.required_extensions', []) as $ext) {
            $loaded = extension_loaded((string) $ext);
            $add('php', 'ext_'.$ext, $loaded ? 'PASS' : (in_array($ext, self::SOFT_EXTENSIONS, true) ? 'WARNING' : 'FAIL'), $loaded ? __('installer.value.loaded') : __('installer.value.missing'), __('installer.fix.extension', ['ext' => $ext]));
        }

        $redisExt = extension_loaded('redis');
        $add('php', 'ext_redis', $redisExt ? 'PASS' : 'WARNING', $redisExt ? __('installer.value.loaded') : __('installer.value.missing'), __('installer.fix.redis_extension'));
        $pcntl = extension_loaded('pcntl') && extension_loaded('posix');
        $add('php', 'ext_pcntl', $pcntl ? 'PASS' : 'WARNING', $pcntl ? __('installer.value.loaded') : __('installer.value.missing'), __('installer.fix.pcntl'));

        $mysql = in_array('mysql', PDO::getAvailableDrivers(), true);
        $add('php', 'mysql_driver', $mysql ? 'PASS' : 'FAIL', $mysql ? 'pdo_mysql' : __('installer.value.missing'), __('installer.fix.extension', ['ext' => 'pdo_mysql']));

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $blocked = array_values(array_intersect(self::NEEDED_FUNCTIONS, $disabled));
        $add('php', 'disabled_functions', $blocked === [] ? 'PASS' : 'WARNING', $blocked === [] ? __('installer.value.none') : implode(', ', $blocked), __('installer.fix.disabled_functions', ['functions' => implode(', ', $blocked)]));

        $memory = $this->bytes((string) ini_get('memory_limit'));
        $add('php', 'memory_limit', ($memory === -1 || $memory >= 256 * 1024 * 1024) ? 'PASS' : 'WARNING', (string) ini_get('memory_limit'), __('installer.fix.memory_limit'));

        $vendor = is_file(base_path('vendor/autoload.php')) && is_file(base_path('vendor/composer/installed.json'));
        $add('app', 'composer_dependencies', $vendor ? 'PASS' : 'FAIL', $vendor ? __('installer.value.installed') : __('installer.value.missing'), __('installer.fix.composer'));
        $add('app', 'laravel_bootstrap', 'PASS', 'Laravel '.app()->version().' · SiteX Post '.config('sitex.version'));

        foreach (['storage' => storage_path(), 'storage_framework' => storage_path('framework'), 'storage_logs' => storage_path('logs'), 'bootstrap_cache' => base_path('bootstrap/cache')] as $key => $dir) {
            $ok = is_dir($dir) && is_writable($dir);
            $add('files', 'writable_'.$key, $ok ? 'PASS' : 'FAIL', $this->relative($dir), __('installer.fix.writable', ['path' => $this->relative($dir)]));
        }
        $add('files', 'writable_installer', $this->storage->writable() ? 'PASS' : 'FAIL', $this->relative($this->storage->dir()), __('installer.fix.writable', ['path' => $this->relative($this->storage->dir())]));

        $env = app()->environmentFilePath();
        $envOk = is_file($env) ? is_writable($env) : is_writable(dirname($env));
        $add('files', 'env_writable', $envOk ? 'PASS' : 'FAIL', '.env', __('installer.fix.env_writable'));

        $cached = is_file(base_path('bootstrap/cache/config.php'));
        $add('files', 'config_cache', $cached ? 'WARNING' : 'PASS', $cached ? __('installer.value.cached') : __('installer.value.not_cached'), __('installer.fix.config_cache'));

        $free = @disk_free_space(base_path());
        $minMb = (int) config('sitex.installer.min_free_disk_mb', 1024);
        $add('server', 'disk_space', ($free !== false && $free >= $minMb * 1024 * 1024) ? 'PASS' : 'WARNING', $free === false ? '—' : round($free / 1024 / 1024 / 1024, 1).' GB', __('installer.fix.disk_space', ['mb' => $minMb]));

        if ($request !== null) {
            $secure = $request->isSecure();
            $add('server', 'https', $secure ? 'PASS' : 'WARNING', $secure ? 'HTTPS' : 'HTTP', __('installer.fix.https'));

            $viaIndex = str_contains($request->getRequestUri(), 'index.php');
            $add('server', 'web_routing', $viaIndex ? 'WARNING' : 'PASS', $viaIndex ? __('installer.value.index_php_in_url') : __('installer.value.pretty_urls'), __('installer.fix.web_routing'));

            $docRoot = realpath((string) $request->server('DOCUMENT_ROOT'));
            $public = realpath(public_path());
            $rootOk = $docRoot === false || $docRoot === $public;
            $add('server', 'web_root', $rootOk ? 'PASS' : 'WARNING', $docRoot === false ? '—' : $this->relative((string) $docRoot), __('installer.fix.web_root'));

            $add('server', 'app_url', 'PASS', $request->getSchemeAndHttpHost());
        }

        $summary = ['pass' => 0, 'warning' => 0, 'fail' => 0];
        foreach ($checks as $c) {
            $summary[strtolower($c['status'])]++;
        }

        return ['checks' => $checks, 'summary' => $summary, 'can_continue' => $summary['fail'] === 0];
    }

    private function relative(string $path): string
    {
        $base = rtrim(dirname(base_path()), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $num = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $num * 1024 ** 3,
            'm' => $num * 1024 ** 2,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
