<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Research\Analysis\IntentClassifier;

final class UpdateIdeaRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:5', 'max:300'],
            'angle' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:300'],
            'content_type' => ['sometimes', 'string', 'in:'.implode(',', IntentClassifier::CONTENT_TYPES)],
            'search_intent' => ['sometimes', 'string', 'in:'.implode(',', IntentClassifier::INTENTS)],
            'priority' => ['sometimes', 'string', 'in:high,medium,low'],
            'status' => ['sometimes', 'string', 'in:suggested,approved,rejected'],
            'opportunity_score' => ['prohibited'],
        ];
    }
}
