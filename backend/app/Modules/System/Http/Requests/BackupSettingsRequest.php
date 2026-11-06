<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class BackupSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['schedule_enabled' => ['required', 'boolean'], 'schedule_time' => ['required', 'date_format:H:i']];
    }
}
