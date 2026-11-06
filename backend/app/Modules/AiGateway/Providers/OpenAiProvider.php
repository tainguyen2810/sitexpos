<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

/**
 * OpenAI Responses API (`POST /v1/responses`), verified against
 * developers.openai.com on 05/11/2026: `instructions`, `input`,
 * `max_output_tokens`, `text.format` {type: json_schema, name, schema, strict},
 * tools `{type: web_search}`, output items `message` → `output_text`,
 * `web_search_call`; usage input_tokens(+details.cached_tokens) /
 * output_tokens(+details.reasoning_tokens). `store: false` keeps no state.
 */
final class OpenAiProvider extends HttpProvider
{
    public function code(): string
    {
        return 'openai';
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        if ($request->kind === 'image') {
            return $this->image($request);
        }

        $body = [
            'model' => $request->model,
            'input' => $request->prompt,
            'max_output_tokens' => $request->maxOutputTokens,
            'store' => false,
        ];
        if ($request->system !== null && $request->system !== '') {
            $body['instructions'] = $request->system;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->schema !== null) {
            $body['text'] = ['format' => [
                'type' => 'json_schema',
                'name' => $request->schemaName,
                'schema' => $request->schema,
                'strict' => true,
            ]];
        }
        if ($request->webSearch) {
            $body['tools'] = [['type' => 'web_search']];
        }

        $json = $this->postJson($this->baseUrl().'/v1/responses', [
            'Authorization' => 'Bearer '.$this->apiKey(),
        ], $body, $request->timeoutSeconds);

        $text = '';
        $searches = 0;
        $refusal = null;
        foreach ((array) ($json['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) === 'web_search_call') {
                $searches++;
            }
            if (($item['type'] ?? null) === 'message') {
                foreach ((array) ($item['content'] ?? []) as $part) {
                    if (is_array($part) && ($part['type'] ?? null) === 'output_text') {
                        $text .= (string) ($part['text'] ?? '');
                    }
                    if (is_array($part) && ($part['type'] ?? null) === 'refusal') {
                        $refusal = (string) ($part['refusal'] ?? 'refused');
                    }
                }
            }
        }

        $u = (array) ($json['usage'] ?? []);
        $input = self::int($u['input_tokens'] ?? 0);
        $cached = self::int(((array) ($u['input_tokens_details'] ?? []))['cached_tokens'] ?? 0);
        $usage = new Usage(
            inputTokens: max(0, $input - $cached),
            cachedInputTokens: $cached,
            outputTokens: self::int($u['output_tokens'] ?? 0),
            reasoningTokens: self::int(((array) ($u['output_tokens_details'] ?? []))['reasoning_tokens'] ?? 0),
            searches: $searches,
        );

        if ($refusal !== null) {
            throw new ProviderException('refused', 'The model refused the request.', usage: $usage);
        }

        $status = (string) ($json['status'] ?? 'completed');
        $finish = match ($status) {
            'incomplete' => ((array) ($json['incomplete_details'] ?? []))['reason'] === 'max_output_tokens' ? 'max_tokens' : 'incomplete',
            default => 'stop',
        };

        return new ProviderResponse($text, $usage, $finish, is_string($json['id'] ?? null) ? $json['id'] : null);
    }

    /**
     * Image generation (`POST /v1/images/generations`, base64 output).
     * NOT verified against a live key — see PHASE3-REPORT.md.
     */
    private function image(ProviderRequest $request): ProviderResponse
    {
        $body = ['model' => $request->model, 'prompt' => $request->prompt, 'n' => max(1, $request->imageCount)];
        if ($request->imageSize !== null) {
            $body['size'] = $request->imageSize;
        }

        $json = $this->postJson($this->baseUrl().'/v1/images/generations', [
            'Authorization' => 'Bearer '.$this->apiKey(),
        ], $body, $request->timeoutSeconds);

        $images = [];
        foreach ((array) ($json['data'] ?? []) as $item) {
            if (is_array($item) && is_string($item['b64_json'] ?? null)) {
                $images[] = $item['b64_json'];
            }
        }
        $u = (array) ($json['usage'] ?? []);

        return new ProviderResponse('', new Usage(
            inputTokens: self::int($u['input_tokens'] ?? 0),
            outputTokens: self::int($u['output_tokens'] ?? 0),
            images: count($images),
        ), 'stop', null, 200, $images);
    }

    public function listModels(int $timeoutSeconds = 15): array
    {
        return self::idsFrom($this->getJson($this->baseUrl().'/v1/models', ['Authorization' => 'Bearer '.$this->apiKey()], $timeoutSeconds));
    }
}
