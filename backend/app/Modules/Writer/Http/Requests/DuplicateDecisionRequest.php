<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class DuplicateDecisionRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'decision' => ['required', 'in:create_new,update_existing,merge,reject'],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
