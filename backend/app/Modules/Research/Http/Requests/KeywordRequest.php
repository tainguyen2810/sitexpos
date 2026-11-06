<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

/**
 * Users can add keywords and override intent/status. Metrics are NOT
 * accepted from clients — they only come from verified sources.
 */
final class KeywordRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'keyword' => [$creating ? 'required' : 'prohibited', 'string', 'min:2', 'max:190'],
            'intent' => ['sometimes', 'string', 'in:informational,commercial,transactional,navigational'],
            'status' => ['sometimes', 'string', 'in:suggested,approved,rejected'],
            'topic_category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'search_volume' => ['prohibited'],
            'difficulty' => ['prohibited'],
            'opportunity_score' => ['prohibited'],
        ];
    }
}
