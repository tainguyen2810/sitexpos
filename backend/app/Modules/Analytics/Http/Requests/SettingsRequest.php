<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;

final class SettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $rules = [
            'thresholds' => ['sometimes', 'array:'.implode(',', array_keys(AnalyticsConfig::THRESHOLDS))],
            'health_weights' => ['sometimes', 'array:'.implode(',', array_keys(AnalyticsConfig::HEALTH_WEIGHTS))],
            'backfill_days' => ['sometimes', 'integer', 'min:7', 'max:'.(int) config('sitex.analytics.max_backfill_days', 480)],
            'raw_retention_days' => ['sometimes', 'integer', 'min:90', 'max:1000'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
        foreach (array_keys(AnalyticsConfig::THRESHOLDS) as $k) {
            $rules['thresholds.'.$k] = ['numeric', 'between:-100,100000'];
        }
        foreach (array_keys(AnalyticsConfig::HEALTH_WEIGHTS) as $k) {
            $rules['health_weights.'.$k] = ['integer', 'min:0', 'max:100'];
        }

        return $rules;
    }
}
