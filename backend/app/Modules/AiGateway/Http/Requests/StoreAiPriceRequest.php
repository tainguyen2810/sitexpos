<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

/**
 * New price version. Amounts in micro-USD (1 USD = 1,000,000) per 1M tokens,
 * per image, or per 1,000 searches.
 */
final class StoreAiPriceRequest extends ApiRequest
{
    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function ($validator): void {
            foreach ((array) $this->input('peak_windows', []) as $i => $w) {
                if (is_array($w) && is_string($w['from'] ?? null) && is_string($w['to'] ?? null) && strcmp($w['to'], $w['from']) <= 0) {
                    $validator->errors()->add("peak_windows.{$i}.to", __('ai.errors.peak_window_order'));
                }
            }
        }];
    }

    protected function fieldRules(): array
    {
        $amount = ['sometimes', 'integer', 'min:0', 'max:10000000000'];
        $nullableAmount = ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000000'];

        return [
            'effective_from' => ['required', 'date', 'after_or_equal:'.now()->subMinutes(5)->toDateTimeString()],
            'input_per_mtok' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'output_per_mtok' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'cached_input_per_mtok' => $amount,
            'cache_write_per_mtok' => $amount,
            'long_context_threshold' => ['sometimes', 'nullable', 'integer', 'min:1000'],
            'long_input_per_mtok' => $nullableAmount,
            'long_cached_input_per_mtok' => $nullableAmount,
            'long_cache_write_per_mtok' => $nullableAmount,
            'long_output_per_mtok' => $nullableAmount,
            'per_image' => $amount,
            'per_1k_searches' => $amount,
            // Time-of-day pricing (e.g. DeepSeek): off-peak rates apply outside the peak windows.
            'offpeak_input_per_mtok' => [...$nullableAmount, 'required_with:peak_windows'],
            'offpeak_cached_input_per_mtok' => $nullableAmount,
            'offpeak_output_per_mtok' => [...$nullableAmount, 'required_with:peak_windows'],
            'peak_windows' => ['nullable', 'array', 'max:14', 'required_with:offpeak_input_per_mtok,offpeak_output_per_mtok'],
            'peak_windows.*.days' => ['required', 'array', 'min:1', 'max:7'],
            'peak_windows.*.days.*' => ['integer', 'between:1,7'],
            'peak_windows.*.from' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'peak_windows.*.to' => ['required', 'string', 'regex:/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/'],
            'source_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
