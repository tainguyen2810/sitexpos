<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

/**
 * Configuration templates the operator downloads and installs THEMSELVES
 * (aaPanel → Supervisor / Cron / Website config). The installer never runs
 * root commands.
 */
final class DeploymentTemplates
{
    public const NAMES = ['supervisor-horizon', 'supervisor-queue-work', 'cron', 'nginx-api'];

    public function render(string $name, ?string $apiHost = null): ?string
    {
        $base = base_path();
        $php = $this->phpCli();
        $user = $this->webUser();
        $host = $apiHost ?: 'api.example.com';

        return match ($name) {
            'supervisor-horizon' => <<<CONF
; SiteX Post — Laravel Horizon (aaPanel → App Store → Supervisor → Add Daemon,
; or copy to /etc/supervisor/conf.d/sitexpost-horizon.conf, then: supervisorctl reread && supervisorctl update)
[program:sitexpost-horizon]
process_name=%(program_name)s
command={$php} {$base}/artisan horizon
directory={$base}
user={$user}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=360
redirect_stderr=true
stdout_logfile={$base}/storage/logs/horizon.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5

CONF,
            'supervisor-queue-work' => <<<CONF
; SiteX Post — fallback worker when Horizon cannot run (no pcntl / Redis).
[program:sitexpost-worker]
process_name=%(program_name)s_%(process_num)02d
command={$php} {$base}/artisan queue:work --queue=critical,default,notifications,billing,connector,publishing,research,writing,factcheck,media,analytics --sleep=3 --tries=1 --timeout=300 --max-time=3600 --memory=512
directory={$base}
user={$user}
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=330
redirect_stderr=true
stdout_logfile={$base}/storage/logs/worker.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5

CONF,
            'cron' => <<<CONF
# SiteX Post — Laravel scheduler (aaPanel → Cron → Shell Script, every minute, run as {$user})
* * * * * cd {$base} && {$php} artisan schedule:run >> /dev/null 2>&1

CONF,
            'nginx-api' => <<<CONF
# SiteX Post API ({$host}) — aaPanel → Website → {$host} → Config.
# Keep aaPanel's SSL / PHP include lines; replace root and the location blocks.
root {$base}/public;
index index.php;
client_max_body_size 20m;

add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/tmp/php-cgi-84.sock;   # aaPanel PHP 8.4 socket; adjust to your version
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_read_timeout 120;
}

location ~ /\.(?!well-known) { deny all; }
location ~* \.(env|log|sql|bak|zip)$ { deny all; }

CONF,
            default => null,
        };
    }

    private function phpCli(): string
    {
        $candidate = PHP_BINDIR.'/php';

        return is_file($candidate) ? $candidate : 'php';
    }

    private function webUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && $info['name'] !== '' && $info['name'] !== 'root') {
                return (string) $info['name'];
            }
        }

        return 'www';
    }
}
