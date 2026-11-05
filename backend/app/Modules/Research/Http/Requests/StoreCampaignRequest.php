<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class StoreCampaignRequest extends ApiRequest
{
    public const GOALS = ['traffic', 'sales', 'leads', 'awareness', 'support', 'local'];

    protected function fieldRules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'site_id' => [$creating ? 'required' : 'prohibited', 'string', 'ulid'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'industry' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:190'],
            'market' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'language' => [$creating ? 'required' : 'sometimes', 'string', 'in:vi,en'],
            'products' => ['sometimes', 'array', 'max:50'],
            'products.*' => ['string', 'max:190'],
            'services' => ['sometimes', 'array', 'max:50'],
            'services.*' => ['string', 'max:190'],
            'audiences' => ['sometimes', 'array', 'max:20'],
            'audiences.*' => ['string', 'max:190'],
            'goals' => ['sometimes', 'array', 'max:6'],
            'goals.*' => ['string', 'in:'.implode(',', self::GOALS)],
            'brand_terms' => ['sometimes', 'array', 'max:10'],
            'brand_terms.*' => ['string', 'max:100'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'string', 'in:active,paused,archived'],
            'is_demo' => ['prohibited'],
        ];
    }
}
