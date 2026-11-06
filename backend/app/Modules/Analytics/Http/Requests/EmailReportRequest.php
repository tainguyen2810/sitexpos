<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class EmailReportRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['recipient_ids' => ['required', 'array', 'min:1', 'max:20'], 'recipient_ids.*' => ['string', 'size:26', 'alpha_num', 'distinct']];
    }
}
