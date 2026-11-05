<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

/**
 * Gemini API `POST /v1beta/models/{model}:generateContent` with header
 * `x-goog-api-key` (verified on aistudio.google.com API reference 05/11/2026).
 * Google now calls generateContent "legacy but supported" and recommends the
 * Interactions API for new work — migration is tracked in PHASE3-REPORT.md.
 * usageMetadata: promptTokenCount (incl. cachedContentTokenCount),
 * candidatesTokenCount, thoughtsTokenCount, toolUsePromptTokenCount.
 */
final class GeminiProvider extends HttpProvider
{
    public function code(): string
    {
        return 'gemini';
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        $generation = ['maxOutputTokens' => $request->maxOutputTokens];
        if ($request->temperature !== null) {
            $generation['temperature'] = $request->temperature;
        }
        if ($request->schema !== null) {
            $generation['responseMimeType'] = 'application/json';
            $generation['responseJsonSchema'] = $request->schema;
        }
        if ($request->kind === 'image') {
            $generation['responseModalities'] = ['TEXT', 'IMAGE'];
        }

        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->prompt]]]],
            'generationConfig' => $generation,
        ];
        if ($request->system !== null && $request->system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $request->system]]];
        }
        if ($request->webSearch) {
            $body['tools'] = [['google_search' => (object) []]];
        }

        $url = $this->baseUrl().'/v1beta/models/'.rawurlencode($request->model).':generateContent';
        $json = $this->postJson($url, ['x-goog-api-key' => $this->apiKey()], $body, $request->timeoutSeconds);

        $candidate = (array) (((array) ($json['candidates'] ?? []))[0] ?? []);
        $text = '';
        $images = [];
        foreach ((array) (((array) ($candidate['content'] ?? []))['parts'] ?? []) as $part) {
            if (! is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
            $inline = $part['inlineData'] ?? null;
            if (is_array($inline) && is_string($inline['data'] ?? null)) {
                $images[] = $inline['data'];
            }
        }

        $grounding = (array) ($candidate['groundingMetadata'] ?? []);
        $searched = ((array) ($grounding['webSearchQueries'] ?? [])) !== [];

        $u = (array) ($json['usageMetadata'] ?? []);
        $cached = self::int($u['cachedContentTokenCount'] ?? 0);
        $thoughts = self::int($u['thoughtsTokenCount'] ?? 0);
        $usage = new Usage(
            inputTokens: max(0, self::int($u['promptTokenCount'] ?? 0) - $cached) + self::int($u['toolUsePromptTokenCount'] ?? 0),
            cachedInputTokens: $cached,
            outputTokens: self::int($u['candidatesTokenCount'] ?? 0) + $thoughts,
            reasoningTokens: $thoughts,
            images: count($images),
            // Billing is per customer request that triggered Google Search.
            searches: $searched ? 1 : 0,
        );

        $finish = (string) ($candidate['finishReason'] ?? 'STOP');
        $blocked = ((array) ($json['promptFeedback'] ?? []))['blockReason'] ?? null;
        if ($blocked !== null || in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION'], true)) {
            throw new ProviderException('refused', 'The model declined to answer ('.($blocked ?? $finish).').', usage: $usage);
        }

        return new ProviderResponse(
            $text,
            $usage,
            $finish === 'MAX_TOKENS' ? 'max_tokens' : 'stop',
            is_string($json['responseId'] ?? null) ? $json['responseId'] : null,
            200,
            $images,
        );
    }

    public function listModels(int $timeoutSeconds = 15): array
    {
        $json = $this->getJson($this->baseUrl().'/v1beta/models?pageSize=1000', ['x-goog-api-key' => $this->apiKey()], $timeoutSeconds);

        return array_map(static fn (string $name): string => str_starts_with($name, 'models/') ? substr($name, 7) : $name, self::idsFrom($json, 'models', 'name'));
    }
}
