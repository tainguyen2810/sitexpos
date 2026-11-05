<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class GapRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['status' => ['required', 'string', 'in:open,planned,dismissed']];
    }
}
