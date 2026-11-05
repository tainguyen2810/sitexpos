<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\AiGateway\Gateway\BudgetManager;

final class ExpireAiReservations extends Command
{
    protected $signature = 'sitex:ai:expire-reservations';

    protected $description = 'Settle AI budget reservations whose call outcome is unknown (charged at their estimate)';

    public function handle(BudgetManager $budgets): int
    {
        $this->info('Expired reservations: '.$budgets->expireStale());

        return self::SUCCESS;
    }
}
