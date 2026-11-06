<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class VerifyClaimRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'verdict' => ['required', 'in:VERIFIED,CONTRADICTED,INSUFFICIENT_EVIDENCE,OUTDATED,NEEDS_HUMAN_REVIEW'],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence_ids' => ['nullable', 'array', 'max:10'],
            'evidence_ids.*' => ['string', 'regex:/^[0-9a-z]{26}$/i'],
            'reference_url' => ['nullable', 'string', 'max:1000', 'url:http,https'],
        ];
    }
}
