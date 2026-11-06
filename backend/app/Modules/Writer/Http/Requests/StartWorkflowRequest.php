<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class StartWorkflowRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'kind' => ['required', 'in:research,outline,draft,recheck'],
            'full' => ['nullable', 'boolean'],
            'auto_revise' => ['nullable', 'boolean'],
        ];
    }
}
