<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

final class UpdateMemberRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'role' => ['required', Rule::in(WorkspaceRole::assignableValues())],
        ];
    }
}
