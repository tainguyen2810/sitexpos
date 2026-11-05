<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Workspace\Domain\Workspace;

final class DeleteWorkspaceRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return [
            'confirm_name' => ['required', 'string', Rule::in([$workspace->name])],
            'password' => ['required', 'string', 'current_password:web'],
        ];
    }
}
