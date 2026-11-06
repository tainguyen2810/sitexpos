<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CopilotRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['question' => ['required', 'string', 'min:3', 'max:1000'], 'site_id' => ['nullable', 'string', 'size:26', 'alpha_num'], 'article_id' => ['nullable', 'string', 'size:26', 'alpha_num']];
    }
}
