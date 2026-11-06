<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

/**
 * DeepSeek Chat Completions (`POST https://api.deepseek.com/chat/completions`,
 * `Authorization: Bearer`). Verified on api-docs.deepseek.com 05/11/2026:
 * - model IDs `deepseek-flash`, `deepseek-v4-pro` (legacy names are not used);
 * - `response_format` accepts only `text` / `json_object` (no json_schema), so
 *   the schema goes in the system prompt and the gateway validates the output;
 * - thinking is ON by default: `thinking: {type: enabled|disabled}` plus
 *   `reasoning_effort` low|high|max; temperature has no effect while thinking;
 * - usage: prompt_tokens = prompt_cache_hit_tokens + prompt_cache_miss_tokens,
 *   completion_tokens_details.reasoning_tokens;
 * - finish_reason may be content_filter or insufficient_system_resource;
 * - web search is a separate Search & Fetch API (not used here).
 */
final class DeepSeekProvider extends OpenAiCompatibleProvider
{
    public const EFFORTS = ['none', 'low', 'high', 'max'];

    public function code(): string
    {
        return 'deepseek';
    }

    protected function structuredMode(): string
    {
        return 'json_object';
    }

    protected function decorate(array $body, ProviderRequest $request): array
    {
        $effort = in_array($request->reasoningEffort, self::EFFORTS, true) ? $request->reasoningEffort : 'none';
        if ($effort === 'none') {
            // Explicitly off: cheaper and faster for short structured tasks.
            $body['thinking'] = ['type' => 'disabled'];

            return $body;
        }

        $body['thinking'] = ['type' => 'enabled'];
        $body['reasoning_effort'] = $effort;
        unset($body['temperature']); // ignored in thinking mode

        return $body;
    }
}
