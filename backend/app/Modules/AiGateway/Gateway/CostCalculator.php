<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use SiteXPost\Modules\AiGateway\Domain\AiModelPrice;
use SiteXPost\Modules\AiGateway\Providers\Usage;

/**
 * Integer cost maths in micro-USD. Prices are per 1M tokens, so
 * cost = Σ tokens × rate / 1,000,000 (rounded half up once, at the end).
 */
final class CostCalculator
{
    public function cost(AiModelPrice $price, Usage $usage): int
    {
        $long = $price->long_context_threshold !== null && $usage->promptTokens() > $price->long_context_threshold;

        $in = $long ? ($price->long_input_per_mtok ?? $price->input_per_mtok) : $price->input_per_mtok;
        $cached = $long ? ($price->long_cached_input_per_mtok ?? $price->cached_input_per_mtok) : $price->cached_input_per_mtok;
        $write = $long ? ($price->long_cache_write_per_mtok ?? $price->cache_write_per_mtok) : $price->cache_write_per_mtok;
        $out = $long ? ($price->long_output_per_mtok ?? $price->output_per_mtok) : $price->output_per_mtok;

        $tokenMicro = $usage->inputTokens * $in
            + $usage->cachedInputTokens * $cached
            + $usage->cacheWriteTokens * $write
            + $usage->outputTokens * $out;

        $tokens = intdiv($tokenMicro + 500_000, 1_000_000);
        $images = $usage->images * $price->per_image;
        $searches = intdiv($usage->searches * $price->per_1k_searches + 500, 1000);

        return $tokens + $images + $searches;
    }

    /**
     * Upper-bound estimate used for the pre-call budget reservation: every
     * prompt token at the (long-context) input rate, the full output limit,
     * all allowed searches and images.
     */
    public function estimate(AiModelPrice $price, int $promptTokens, int $maxOutputTokens, int $searches = 0, int $images = 0): int
    {
        return $this->cost($price, new Usage(
            inputTokens: $promptTokens,
            outputTokens: $maxOutputTokens,
            images: $images,
            searches: $searches,
        ));
    }
}
