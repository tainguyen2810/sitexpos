<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class DecideRecommendationRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['status' => ['required', 'in:approved,rejected,dismissed'], 'note' => ['nullable', 'string', 'max:500']];
    }
}
