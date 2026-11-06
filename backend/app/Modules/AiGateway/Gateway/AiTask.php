<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

/**
 * One logical AI call. `callKey` is the idempotency key of the call (e.g.
 * "wf:<run>:<step>:c<cycle>:a<attempt>"); every provider attempt derives its
 * budget reservation key from it, so a duplicate job never double-charges.
 */
final readonly class AiTask
{
    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public string $taskKey,
        public string $prompt,
        public string $callKey,
        public ?string $system = null,
        public ?array $schema = null,
        public string $schemaName = 'result',
        public bool $webSearch = false,
        public ?int $maxOutputTokens = null,
        public ?string $workflowRunId = null,
        public string $kind = 'text',
        public int $imageCount = 1,
        public ?string $imageSize = null,
        // Super Admin "Test model": call exactly this model (even if disabled),
        // outside any workspace; only the global budget applies.
        public ?string $modelId = null,
        public bool $platform = false,
    ) {}
}
