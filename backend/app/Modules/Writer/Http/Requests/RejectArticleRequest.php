<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class RejectArticleRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['note' => ['required', 'string', 'min:5', 'max:2000']];
    }
}
