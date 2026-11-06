<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CompetitorRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'url' => [$creating ? 'required' : 'prohibited', 'string', 'max:255', 'url:http,https'],
            'name' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:150'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'string', 'in:active,paused'],
        ];
    }
}
