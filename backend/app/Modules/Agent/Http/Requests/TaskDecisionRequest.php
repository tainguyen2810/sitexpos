<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class TaskDecisionRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }
}
