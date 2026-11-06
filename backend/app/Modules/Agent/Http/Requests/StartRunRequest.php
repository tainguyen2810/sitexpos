<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Agent\Engine\ScheduleCalculator;
use SiteXPost\Modules\Agent\Support\SiteRef;

final class StartRunRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        SiteRef::resolve((string) $this->route('site'));
    }

    protected function fieldRules(): array
    {
        return ['kind' => ['sometimes', 'in:'.implode(',', array_keys(ScheduleCalculator::KINDS))], 'idempotency_key' => ['nullable', 'string', 'max:80']];
    }
}
