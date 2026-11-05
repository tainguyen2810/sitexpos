<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class BatchRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'site_id' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'article_ids' => ['required', 'array', 'min:1', 'max:100'],
            'article_ids.*' => ['string', 'regex:/^[0-9a-z]{26}$/i'],
            'mode' => ['required', 'in:wp_draft,publish_now,scheduled'],
            'start_at' => ['nullable', 'date'],
            'interval_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
        ];
    }
}
