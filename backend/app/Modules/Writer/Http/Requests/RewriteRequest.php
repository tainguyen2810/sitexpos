<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Writer\Workflows\RewriteWorkflow;

final class RewriteRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'action' => ['required', 'in:'.implode(',', array_keys(RewriteWorkflow::ACTIONS))],
            'section_key' => ['required', 'string', 'max:40'],
            'selection' => ['nullable', 'string', 'max:4000'],
            'tone' => ['nullable', 'string', 'max:100'],
        ];
    }
}
