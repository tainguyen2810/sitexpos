<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Writer\Support\SourceFilter;

final class SourceRuleRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:1000', function (string $attr, mixed $value, \Closure $fail): void {
                if (! is_string($value) || SourceFilter::normalizeDomain($value) === null) {
                    $fail(__('writer.errors.invalid_domain'));
                }
            }],
            'mode' => ['required', 'in:prefer,block'],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
