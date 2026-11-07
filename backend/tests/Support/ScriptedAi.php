<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Providers\ProviderAdapter;
use SiteXPost\Modules\AiGateway\Providers\ProviderException;
use SiteXPost\Modules\AiGateway\Providers\ProviderRequest;
use SiteXPost\Modules\AiGateway\Providers\ProviderResponse;
use SiteXPost\Modules\AiGateway\Providers\Usage;

/**
 * Test double for the "fake" provider: answers per JSON schema name with a
 * scripted closure, so the whole gateway path (routing, budgets, request
 * log, idempotency) still runs. Bound with
 * app()->instance(FakeProvider::class, $scripted).
 */
final class ScriptedAi implements ProviderAdapter
{
    /** @var array<string, Closure(ProviderRequest): (array<string, mixed>|ProviderException|ProviderResponse)> */
    public array $handlers = [];

    /** @var list<array{schema: string, prompt: string}> */
    public array $calls = [];

    /**
     * @param  Closure(ProviderRequest): (array<string, mixed>|ProviderException|ProviderResponse)  $fn
     */
    public function on(string $schemaName, Closure $fn): self
    {
        $this->handlers[$schemaName] = $fn;

        return $this;
    }

    public function count(string $schemaName): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['schema'] === $schemaName));
    }

    public function code(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function forProvider(AiProvider $provider): static
    {
        return $this;
    }

    public function listModels(int $timeoutSeconds = 15): array
    {
        return [];
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        $this->calls[] = ['schema' => $request->schemaName, 'prompt' => $request->prompt];
        $fn = $this->handlers[$request->schemaName] ?? null;
        $out = $fn !== null ? $fn($request) : self::empty($request->schemaName);
        if ($out instanceof ProviderException) {
            throw $out;
        }
        if ($out instanceof ProviderResponse) {
            return $out; // raw answer (e.g. JSON cut off at max_tokens)
        }
        $text = (string) json_encode($out, JSON_UNESCAPED_UNICODE);

        return new ProviderResponse($text, new Usage(inputTokens: (int) ceil(mb_strlen($request->prompt) / 4), outputTokens: max(1, (int) ceil(mb_strlen($text) / 4))), 'stop', 'scripted_'.count($this->calls));
    }

    /** The `<data ...>` block of a prompt. */
    public static function data(string $prompt): string
    {
        return preg_match('#<data[^>]*>\n?(.*)\n?</data>#s', $prompt, $m) === 1 ? $m[1] : '';
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $prompt): array
    {
        return (array) json_decode(self::data($prompt), true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(string $schema): array
    {
        return match ($schema) {
            'research_questions' => ['questions' => []],
            'source_candidates' => ['urls' => []],
            'evidence' => ['items' => []],
            'claims' => ['claims' => []],
            'fact_check' => ['results' => []],
            'review_notes' => ['notes' => []],
            default => [],
        };
    }
}
