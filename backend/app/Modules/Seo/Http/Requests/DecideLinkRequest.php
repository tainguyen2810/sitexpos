<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class DecideLinkRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['status' => ['required', 'in:accepted,rejected']];
    }
}
