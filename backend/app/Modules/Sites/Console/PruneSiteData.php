<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Domain\SiteActivityLog;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Domain\SiteHealthCheck;
use SiteXPost\Modules\Sites\Enums\CredentialStatus;

/**
 * Retention: health checks and connector logs older than 30 days; expired
 * rotated keys are marked revoked.
 */
final class PruneSiteData extends Command
{
    protected $signature = 'sitex:sites:prune';

    protected $description = 'Delete old site health checks and connector logs';

    public function handle(TenantContext $context): int
    {
        $before = now()->subDays((int) config('sitex.connector.log_retention_days', 30));

        // Reviewed scope bypass: retention job across all tenants, delete-only by age.
        [$checks, $logs, $keys] = $context->bypass(fn () => [
            SiteHealthCheck::query()->where('checked_at', '<', $before)->delete(),
            SiteActivityLog::query()->where('created_at', '<', $before)->delete(),
            SiteCredential::query()->where('status', CredentialStatus::Rotated->value)->where('grace_until', '<', now())
                ->update(['status' => CredentialStatus::Revoked->value, 'revoked_at' => now()]),
        ]);

        $this->info("Deleted {$checks} health checks, {$logs} logs; expired {$keys} rotated keys.");

        return self::SUCCESS;
    }
}
