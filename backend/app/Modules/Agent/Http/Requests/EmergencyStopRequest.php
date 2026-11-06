<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Agent\Support\SiteRef;

final class EmergencyStopRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        SiteRef::resolve((string) $this->route('site'));
    }

    protected function fieldRules(): array
    {
        return ['stop' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
