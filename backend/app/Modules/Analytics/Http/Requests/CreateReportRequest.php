<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CreateReportRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['site_id' => ['nullable', 'string', 'size:26', 'alpha_num'], 'period_type' => ['required', 'in:weekly,monthly,custom'], 'period_start' => ['required_if:period_type,custom', 'nullable', 'date_format:Y-m-d'], 'period_end' => ['required_if:period_type,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:period_start']];
    }
}
