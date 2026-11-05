<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class StartResearchRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['refresh_profile' => ['sometimes', 'boolean']];
    }
}
