<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class RegenerateImageRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
