<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class TransferOwnershipRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'member_id' => ['required', 'string', 'ulid'],
            'password' => ['required', 'string', 'current_password:web'],
        ];
    }
}
