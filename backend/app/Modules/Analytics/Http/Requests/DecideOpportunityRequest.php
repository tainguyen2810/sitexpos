<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class DecideOpportunityRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['status' => ['required', 'in:open,accepted,dismissed,resolved'], 'note' => ['nullable', 'string', 'max:500']];
    }
}
