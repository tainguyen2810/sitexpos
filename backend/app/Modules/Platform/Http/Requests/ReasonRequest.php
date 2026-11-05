<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ReasonRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
