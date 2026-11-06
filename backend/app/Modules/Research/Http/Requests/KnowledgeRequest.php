<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class KnowledgeRequest extends ApiRequest
{
    public const KINDS = ['industry', 'brand', 'positioning', 'product_line', 'service', 'audience', 'region', 'usp', 'covered_topic', 'uncertainty'];

    protected function fieldRules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'kind' => [$creating ? 'required' : 'prohibited', 'string', 'in:'.implode(',', self::KINDS)],
            'value' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:1000'],
            'status' => ['sometimes', 'string', 'in:suggested,confirmed,rejected'],
        ];
    }
}
