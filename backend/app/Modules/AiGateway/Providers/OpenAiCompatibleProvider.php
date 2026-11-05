<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

/**
 * Generic OpenAI-compatible Chat Completions adapter (`POST {base}/chat/completions`,
 * `GET {base}/models`, `Authorization: Bearer`). Used by custom providers a
 * Super Admin adds; `base_url` is everything before `/chat/completions`
 * (usually ends with `/v1`).
 *
 * Structured output follows the provider row's `settings.response_format`:
 * - json_schema: `response_format: {type: json_schema, json_schema: {...}}`
 * - json_object: `response_format: {type: json_object}` + schema in the system prompt
 * - none: schema in the system prompt only
 * The gateway validates the result against the schema in every mode.
 */
class OpenAiCompatibleProvider extends HttpProvider
{
    public function code(): string
    {
        return 'openai_compatible';
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        if ($request->kind !== 'text') {
            throw new ProviderException('unsupported_feature', 'This provider only supports text generation.');
        }

        $mode = $this->structuredMode();
        $system = (string) $request->system;
        if ($request->schema !== null && $mode !== 'json_schema') {
            $system = trim($system."\n\n".self::schemaInstruction($request->schema));
        }

        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $request->prompt];

        $body = [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxOutputTokens,
            'stream' => false,
        ];
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->schema !== null && $mode === 'json_schema') {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => [
                'name' => $request->schemaName, 'schema' => $request->schema, 'strict' => true,
            ]];
        } elseif ($request->schema !== null && $mode === 'json_object') {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $json = $this->postJson($this->baseUrl().'/chat/completions', [
            'Authorization' => 'Bearer '.$this->apiKey(),
        ], $this->decorate($body, $request), $request->timeoutSeconds);

        $choice = (array) (((array) ($json['choices'] ?? []))[0] ?? []);
        $message = (array) ($choice['message'] ?? []);
        $usage = $this->usage((array) ($json['usage'] ?? []));
        $finish = (string) ($choice['finish_reason'] ?? 'stop');

        if ($finish === 'content_filter' || (is_string($message['refusal'] ?? null) && $message['refusal'] !== '')) {
            throw new ProviderException('refused', 'The model declined the request.', usage: $usage);
        }
        if ($finish === 'insufficient_system_resource') {
            // The provider aborted mid-generation: tokens may already be billed.
            throw new ProviderException('provider_overloaded', 'The provider ran out of capacity while generating.', retryable: true, billingUncertain: true, countsAsFailure: true, usage: $usage);
        }

        return new ProviderResponse(
            is_string($message['content'] ?? null) ? $message['content'] : '',
            $usage,
            match ($finish) {
                'length' => 'max_tokens',
                'stop', 'tool_calls' => 'stop',
                default => 'incomplete',
            },
            is_string($json['id'] ?? null) ? $json['id'] : null,
        );
    }

    public function listModels(int $timeoutSeconds = 15): array
    {
        return self::idsFrom($this->getJson($this->baseUrl().'/models', ['Authorization' => 'Bearer '.$this->apiKey()], $timeoutSeconds));
    }

    /**
     * Provider-specific request fields (overridden by DeepSeek).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function decorate(array $body, ProviderRequest $request): array
    {
        return $body;
    }

    protected function structuredMode(): string
    {
        return $this->provider?->responseFormat() ?? 'json_object';
    }

    /**
     * OpenAI usage shape; cache hits are reported as
     * prompt_tokens_details.cached_tokens (DeepSeek also as prompt_cache_hit_tokens).
     *
     * @param  array<string, mixed>  $u
     */
    protected function usage(array $u): Usage
    {
        $details = (array) ($u['prompt_tokens_details'] ?? []);
        $prompt = self::int($u['prompt_tokens'] ?? 0);
        $cached = self::int($details['cached_tokens'] ?? $u['prompt_cache_hit_tokens'] ?? $details['prompt_cache_hit_tokens'] ?? 0);

        return new Usage(
            inputTokens: max(0, $prompt - $cached),
            cachedInputTokens: min($cached, $prompt),
            outputTokens: self::int($u['completion_tokens'] ?? 0),
            reasoningTokens: self::int(((array) ($u['completion_tokens_details'] ?? []))['reasoning_tokens'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected static function schemaInstruction(array $schema): string
    {
        return 'Respond with one JSON object only (no markdown, no prose) that matches this JSON Schema: '
            .json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
