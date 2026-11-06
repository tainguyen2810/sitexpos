<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\Billing\Payments\PaymentOrders;
use SiteXPost\Modules\Billing\Subscriptions\SubscriptionManager;

final class RunLifecycle extends Command
{
    protected $signature = 'sitex:billing:lifecycle';

    protected $description = 'Subscription lifecycle (trial end, period end, grace, scheduled changes, reminders) and order expiry';

    public function handle(SubscriptionManager $subscriptions, PaymentOrders $orders): int
    {
        foreach ($subscriptions->tick() as $k => $v) {
            $this->line($k.': '.$v);
        }
        $this->line('orders_expired: '.$orders->expireDue());

        return self::SUCCESS;
    }
}
