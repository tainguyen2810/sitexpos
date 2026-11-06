<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Analytics\Actions\TrackExperiments;

final class CreateExperimentRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'site_id' => ['required', 'string', 'size:26', 'alpha_num'],
            'article_id' => ['nullable', 'string', 'size:26', 'alpha_num'],
            'page_url' => ['required', 'url:http,https', 'max:1000'],
            'change_type' => ['required', 'in:'.implode(',', TrackExperiments::CHANGE_TYPES)],
            'change_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
