<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

/**
 * Usage normalised across providers:
 * - inputTokens: input billed at the normal input rate (cache hits excluded)
 * - cachedInputTokens: cache reads; cacheWriteTokens: cache writes (Anthropic)
 * - outputTokens: everything billed at the output rate (includes reasoning/thinking)
 * - reasoningTokens: informational subset of outputTokens
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
        public int $images = 0,
        public int $searches = 0,
    ) {}

    public function promptTokens(): int
    {
        return $this->inputTokens + $this->cachedInputTokens + $this->cacheWriteTokens;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'images' => $this->images,
            'searches' => $this->searches,
        ];
    }
}
