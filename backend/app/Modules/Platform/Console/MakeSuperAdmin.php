<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

/**
 * There is no default admin account. The first SUPER_ADMIN is promoted from
 * an existing (registered, verified) user via the CLI.
 */
final class MakeSuperAdmin extends Command
{
    protected $signature = 'sitex:make-super-admin {email : Email of an existing, verified user}';

    protected $description = 'Grant SUPER_ADMIN to an existing verified user';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->error('User not found. Register the account first.');

            return self::FAILURE;
        }

        if (! $user->hasVerifiedEmail()) {
            $this->error('The user must verify their email first.');

            return self::FAILURE;
        }

        $from = $user->platform_role;
        $user->forceFill(['platform_role' => PlatformRole::SuperAdmin])->save();
        Audit::record('platform.staff.role_changed', $user, ['from' => $from?->value, 'to' => 'SUPER_ADMIN', 'via' => 'cli'], actorType: 'system');

        $this->info("{$user->email} is now SUPER_ADMIN. Two-factor authentication will be required on first admin login.");

        return self::SUCCESS;
    }
}
