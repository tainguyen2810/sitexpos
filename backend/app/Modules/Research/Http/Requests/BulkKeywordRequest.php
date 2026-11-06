<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class BulkKeywordRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string', 'ulid', 'distinct'],
            'status' => ['required', 'string', 'in:suggested,approved,rejected'],
        ];
    }
}
