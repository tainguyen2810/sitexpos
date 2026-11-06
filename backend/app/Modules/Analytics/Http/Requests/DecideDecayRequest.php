<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class DecideDecayRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['status' => ['required', 'in:DECAY_SUSPECTED,NEEDS_INVESTIGATION,CONFIRMED_ISSUE,NO_ACTION'], 'note' => ['nullable', 'string', 'max:500']];
    }
}
