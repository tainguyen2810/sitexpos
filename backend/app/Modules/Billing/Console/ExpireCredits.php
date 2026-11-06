<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\Billing\Credits\CreditLedger;

final class ExpireCredits extends Command
{
    protected $signature = 'sitex:billing:expire-credits';

    protected $description = 'Expire credit grants and settle stale credit reservations at their estimate';

    public function handle(CreditLedger $ledger): int
    {
        $this->line('grants_expired: '.$ledger->expireGrants());
        $this->line('reservations_expired: '.$ledger->expireStaleReservations());

        return self::SUCCESS;
    }
}
