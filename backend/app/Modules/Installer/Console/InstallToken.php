<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\Installer\Support\InstallationState;
use SiteXPost\Modules\Installer\Support\SetupToken;

/**
 * Prints a fresh one-time setup token for /install (invalidates older ones).
 */
final class InstallToken extends Command
{
    protected $signature = 'sitex:install:token {--file : Also write it to storage/app/installer/setup-token.txt}';

    protected $description = 'Create the one-time setup token required by the web installer';

    public function handle(SetupToken $token, InstallationState $state): int
    {
        if ($state->isInstalled()) {
            $this->error('SiteX Post is already installed ('.implode(', ', $state->signals()).'). The installer is locked.');

            return self::FAILURE;
        }

        $plain = $token->issue((bool) $this->option('file'));
        $this->info('Setup token (valid '.config('sitex.installer.token_ttl_hours').' h, shown once):');
        $this->line($plain);
        $this->line('Open https://<api-domain>/install and paste it.');

        return self::SUCCESS;
    }
}
