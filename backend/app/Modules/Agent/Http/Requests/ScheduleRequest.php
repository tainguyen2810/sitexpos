<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Agent\Engine\ScheduleCalculator;
use SiteXPost\Modules\Agent\Support\SiteRef;

final class ScheduleRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('site') !== null) {
            SiteRef::resolve((string) $this->route('site'));
        }
    }

    protected function fieldRules(): array
    {
        $creating = $this->route('site') !== null;

        return [
            'kind' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', array_keys(ScheduleCalculator::KINDS))],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly'],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'local_time' => ['sometimes', 'date_format:H:i'],
            'timezone' => ['sometimes', 'timezone:all'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'in:active,paused,disabled'],
            'notify_on_failure' => ['sometimes', 'boolean'],
        ];
    }
}
