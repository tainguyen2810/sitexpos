<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class MediaLimitsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'scope_key' => ['required', 'string', 'regex:/^(global|ws:[0-9a-z]{26})$/i'],
            'max_storage_bytes' => ['nullable', 'integer', 'min:0'],
            'max_uploads_per_day' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_ai_images_per_day' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'max_ai_cost_per_job_micro_usd' => ['nullable', 'integer', 'min:0'],
            'ai_images_enabled' => ['required', 'boolean'],
        ];
    }
}
