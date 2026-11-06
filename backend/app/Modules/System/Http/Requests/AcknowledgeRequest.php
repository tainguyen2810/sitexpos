<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class AcknowledgeRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['step' => ['required', 'in:wordpress']];
    }
}
