<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ClusterRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
        ];
    }
}
