<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class BillingSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'enforcement' => ['sometimes', 'in:off,enforce'],
            'default_plan_code' => ['nullable', 'string', 'max:40', 'exists:plans,code'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'trial_enabled' => ['sometimes', 'boolean'],
            'trial_requires_verified_email' => ['sometimes', 'boolean'],
            'grace_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'grace_allows_ai' => ['sometimes', 'boolean'],
            'proration_enabled' => ['sometimes', 'boolean'],
            'order_expiry_minutes' => ['sometimes', 'integer', 'min:5', 'max:43200'],
            'renewal_notice_days' => ['sometimes', 'array', 'max:5'],
            'renewal_notice_days.*' => ['integer', 'min:1', 'max:60'],
            'low_credit_threshold_mc' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'],
            'overpayment_policy' => ['sometimes', 'in:manual_review,accept_and_credit_difference'],
            'included_credits_expire' => ['sometimes', 'boolean'],
            'allow_non_expiring_purchased_credits' => ['sometimes', 'boolean'],
            'reservation_ttl_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'usd_to_currency_rate' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'loss_alert_margin_bps' => ['sometimes', 'integer', 'min:-100000', 'max:10000'],
            'heavy_user_cost_share_bps' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'seller_name' => ['nullable', 'string', 'max:191'],
            'seller_tax_code' => ['nullable', 'string', 'max:32'],
            'seller_address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
