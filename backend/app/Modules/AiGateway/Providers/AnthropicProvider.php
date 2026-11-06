<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

/**
 * Claude Messages API `POST /v1/messages` (headers `x-api-key`,
 * `anthropic-version`). Verified 05/11/2026: API version 2026-10-21 rejects
 * temperature/top_p/top_k (never sent); structured output via
 * `output_config.format` {type: json_schema, schema}; web search server tool
 * `web_search_20260209`; usage input_tokens (uncached), cache_read_input_tokens,
 * cache_creation_input_tokens, output_tokens (includes thinking),
 * server_tool_use.web_search_requests; `stop_reason: refusal` must be handled.
 *
 * Raw HTTP instead of the PHP SDK on purpose: the SDK retries 408/409/429/5xx
 * twice by default, which would bypass the gateway's paid-retry policy, and
 * all three providers share one audited transport (HttpProvider).
 */
final class AnthropicProvider extends HttpProvider
{
    public function code(): string
    {
        return 'anthropic';
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        if ($request->kind === 'image') {
            throw new ProviderException('capability_unsupported', 'Claude does not generate images.');
        }

        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxOutputTokens,
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
        ];
        if ($request->system !== null && $request->system !== '') {
            $body['system'] = $request->system;
        }
        if ($request->schema !== null) {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $request->schema]];
        }
        if ($request->webSearch) {
            $body['tools'] = [['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => max(1, $request->maxSearches)]];
        }

        $json = $this->postJson($this->baseUrl().'/v1/messages', [
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => (string) $this->config('version'),
        ], $body, $request->timeoutSeconds);

        $text = '';
        foreach ((array) ($json['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        $u = (array) ($json['usage'] ?? []);
        $usage = new Usage(
            inputTokens: self::int($u['input_tokens'] ?? 0),
            cachedInputTokens: self::int($u['cache_read_input_tokens'] ?? 0),
            cacheWriteTokens: self::int($u['cache_creation_input_tokens'] ?? 0),
            outputTokens: self::int($u['output_tokens'] ?? 0),
            searches: self::int(((array) ($u['server_tool_use'] ?? []))['web_search_requests'] ?? 0),
        );

        $stop = (string) ($json['stop_reason'] ?? 'end_turn');
        if ($stop === 'refusal') {
            $category = ((array) ($json['stop_details'] ?? []))['category'] ?? null;
            throw new ProviderException('refused', 'The model declined the request'.(is_string($category) ? " ({$category})." : '.'), usage: $usage);
        }

        return new ProviderResponse(
            $text,
            $usage,
            match ($stop) {
                'max_tokens' => 'max_tokens',
                'pause_turn' => 'incomplete',
                default => 'stop',
            },
            is_string($json['id'] ?? null) ? $json['id'] : null,
        );
    }

    public function listModels(int $timeoutSeconds = 15): array
    {
        return self::idsFrom($this->getJson($this->baseUrl().'/v1/models?limit=1000', [
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => (string) $this->config('version'),
        ], $timeoutSeconds));
    }
}
