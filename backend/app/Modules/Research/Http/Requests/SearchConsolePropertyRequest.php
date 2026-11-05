<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class SearchConsolePropertyRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['property_url' => ['required', 'string', 'max:300']];
    }
}
