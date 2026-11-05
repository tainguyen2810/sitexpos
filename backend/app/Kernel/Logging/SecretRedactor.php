<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Logging;

/**
 * Removes credentials from any text that may be persisted or logged
 * (provider error bodies sometimes echo the key or the full request URL).
 */
final class SecretRedactor
{
    // Order matters: "Authorization: Bearer x" must lose the token before the
    // generic key=value rule (index 4) consumes the word "Bearer".
    private const PATTERNS = [
        '/Bearer\s+[A-Za-z0-9._\-]+/i',
        '/sk-ant-[A-Za-z0-9_\-]{8,}/',          // Anthropic
        '/sk-(proj-)?[A-Za-z0-9_\-]{16,}/',      // OpenAI
        '/AIza[0-9A-Za-z_\-]{20,}/',             // Google API keys
        '/(api[_-]?key|key|token|authorization|x-api-key|x-goog-api-key)(["\']?\s*[:=]\s*["\']?)([^"\'\s&,}]+)/i',
    ];

    public static function redact(string $text, int $limit = 500): string
    {
        foreach ((array) config('sitex.ai.providers', []) as $provider) {
            $key = is_array($provider) ? (string) ($provider['api_key'] ?? '') : '';
            if (strlen($key) >= 6) {
                $text = str_replace($key, '[REDACTED]', $text);
            }
        }

        foreach (self::PATTERNS as $i => $pattern) {
            $text = $i === 4
                ? (string) preg_replace($pattern, '$1$2[REDACTED]', $text)
                : (string) preg_replace($pattern, '[REDACTED]', $text);
        }

        return mb_substr(trim($text), 0, $limit);
    }
}
