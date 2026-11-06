<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CreateRefreshRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['article_id' => ['required', 'string', 'size:26', 'alpha_num'], 'recommendation_id' => ['nullable', 'string', 'size:26', 'alpha_num'], 'idempotency_key' => ['nullable', 'string', 'max:80']];
    }
}
