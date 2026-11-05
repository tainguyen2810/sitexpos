<?php

use SiteXPost\Modules\AiGateway\Domain\AiModelPrice;
use SiteXPost\Modules\AiGateway\Gateway\CostCalculator;
use SiteXPost\Modules\AiGateway\Providers\Usage;

function price(array $attrs): AiModelPrice
{
    $p = new AiModelPrice;
    $p->forceFill([
        'input_per_mtok' => 0, 'cached_input_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'output_per_mtok' => 0,
        'long_context_threshold' => null, 'long_input_per_mtok' => null, 'long_cached_input_per_mtok' => null,
        'long_cache_write_per_mtok' => null, 'long_output_per_mtok' => null, 'per_image' => 0, 'per_1k_searches' => 0,
        ...$attrs,
    ]);

    return $p;
}

it('computes integer micro-USD cost per 1M tokens', function () {
    // Claude Sonnet 5.5: $2 in, $0.10 cache read, $2.50 cache write, $10 out
    $p = price(['input_per_mtok' => 2_000_000, 'cached_input_per_mtok' => 100_000, 'cache_write_per_mtok' => 2_500_000, 'output_per_mtok' => 10_000_000]);
    $usage = new Usage(inputTokens: 1000, cachedInputTokens: 10_000, cacheWriteTokens: 2000, outputTokens: 500);

    // 1000*2 + 10000*0.10 + 2000*2.5 + 500*10 = 2000 + 1000 + 5000 + 5000 = 13000 micro-USD ($0.013)
    expect((new CostCalculator)->cost($p, $usage))->toBe(13_000);
});

it('rounds once, half up, and never returns floats', function () {
    $p = price(['input_per_mtok' => 100_000, 'output_per_mtok' => 500_000]); // $0.10 / $0.50
    expect((new CostCalculator)->cost($p, new Usage(inputTokens: 7, outputTokens: 3)))->toBe(2) // 0.7+1.5=2.2
        ->and((new CostCalculator)->cost($p, new Usage(inputTokens: 1)))->toBe(0);              // 0.1 → 0
});

it('applies long-context rates above the threshold (Claude Haiku 5.5)', function () {
    $p = price([
        'input_per_mtok' => 100_000, 'output_per_mtok' => 500_000,
        'long_context_threshold' => 100_000, 'long_input_per_mtok' => 500_000, 'long_output_per_mtok' => 2_500_000,
    ]);
    $calc = new CostCalculator;

    expect($calc->cost($p, new Usage(inputTokens: 100_000, outputTokens: 1000)))->toBe(10_000 + 500)
        ->and($calc->cost($p, new Usage(inputTokens: 100_001, outputTokens: 1000)))->toBe(50_001 + 2_500);
});

it('adds images and web searches', function () {
    $p = price(['per_image' => 20_000, 'per_1k_searches' => 10_000_000]); // $0.02 / image, $10 / 1K searches
    expect((new CostCalculator)->cost($p, new Usage(images: 2, searches: 3)))->toBe(40_000 + 30_000);
});

it('estimates an upper bound with the full output limit', function () {
    $p = price(['input_per_mtok' => 1_000_000, 'output_per_mtok' => 2_000_000]);
    expect((new CostCalculator)->estimate($p, 1000, 800))->toBe(1000 + 1600);
});
