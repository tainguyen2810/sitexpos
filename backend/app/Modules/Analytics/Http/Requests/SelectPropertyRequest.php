<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Analytics\Support\DashboardQuery;

final class SelectPropertyRequest extends ApiRequest
{
    /** Another tenant's site id must answer 404 before any validation detail leaks. */
    protected function prepareForValidation(): void
    {
        app(DashboardQuery::class)->site((string) $this->route('site'));
    }

    protected function fieldRules(): array
    {
        return ['property_url' => ['required', 'string', 'max:300']];
    }
}
