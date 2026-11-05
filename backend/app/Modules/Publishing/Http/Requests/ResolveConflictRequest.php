<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ResolveConflictRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'resolution' => ['required', 'in:keep_wordpress,overwrite,merge_manually,new_draft,cancel_update'],
            'note' => ['nullable', 'string', 'max:1000'],
            'confirm' => ['required', 'accepted'],
        ];
    }
}
