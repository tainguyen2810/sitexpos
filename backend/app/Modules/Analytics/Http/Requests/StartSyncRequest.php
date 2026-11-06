<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class StartSyncRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['kind' => ['sometimes', 'in:incremental,backfill'], 'idempotency_key' => ['nullable', 'string', 'max:80']];
    }
}
