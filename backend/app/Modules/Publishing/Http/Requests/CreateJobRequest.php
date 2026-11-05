<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CreateJobRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'article_id' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'mode' => ['required', 'in:wp_draft,publish_now,scheduled,update'],
            'scheduled_at' => ['nullable', 'date', 'required_if:mode,scheduled'],
            'approve' => ['nullable', 'boolean'],
        ];
    }
}
