<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Subscriptions;

use SiteXPost\Kernel\Http\ApiException;

final class InvalidTransition extends ApiException
{
    public function __construct(SubscriptionStatus $from, SubscriptionStatus $to)
    {
        parent::__construct(409, 'invalid_subscription_transition', __('billing.errors.invalid_transition', ['from' => $from->value, 'to' => $to->value]), ['from' => $from->value, 'to' => $to->value]);
    }
}
