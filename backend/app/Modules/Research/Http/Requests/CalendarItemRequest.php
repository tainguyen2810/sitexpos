<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CalendarItemRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'content_idea_id' => [$creating ? 'required' : 'prohibited', 'string', 'ulid'],
            'scheduled_for' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d', 'after_or_equal:'.now()->subYear()->toDateString(), 'before:'.now()->addYears(2)->toDateString()],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'string', 'in:proposed,approved,paused,canceled'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
