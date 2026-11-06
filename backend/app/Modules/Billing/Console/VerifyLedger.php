<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\Billing\Credits\CreditLedger;

final class VerifyLedger extends Command
{
    protected $signature = 'sitex:billing:verify-ledger';

    protected $description = 'Check credit wallets against the ledger (exit 1 on mismatch)';

    public function handle(CreditLedger $ledger): int
    {
        $bad = $ledger->verify();
        foreach ($bad as $row) {
            $this->error((string) json_encode($row));
        }
        $this->line(count($bad) === 0 ? 'ledger ok' : count($bad).' wallet(s) out of balance');

        return count($bad) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
