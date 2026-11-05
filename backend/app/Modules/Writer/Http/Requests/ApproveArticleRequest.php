<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ApproveArticleRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'version_id' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'acknowledge_high_risk' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
