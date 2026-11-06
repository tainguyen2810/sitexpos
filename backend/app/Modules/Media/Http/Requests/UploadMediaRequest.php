<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UploadMediaRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) ceil((int) config('sitex.media.max_upload_bytes', 10485760) / 1024)],
            'site_id' => ['nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'title' => ['nullable', 'string', 'max:200'],
            'alt_text' => ['nullable', 'string', 'max:250'],
            'caption' => ['nullable', 'string', 'max:500'],
            'license' => ['required', 'in:owned,customer_provided,licensed_stock'],
            'attribution' => ['nullable', 'string', 'max:500'],
        ];
    }
}
