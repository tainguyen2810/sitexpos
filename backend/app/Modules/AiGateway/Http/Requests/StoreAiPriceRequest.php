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
            'source_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
