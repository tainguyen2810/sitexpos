<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Retrieval;

/**
 * Flags text that tries to talk to the model ("ignore previous
 * instructions", fake system prompts, tool calls...). Flagged sources are
 * still treated purely as data: the flag lowers their reliability and is
 * shown to the editor. The real defence is structural: source text is only
 * ever placed inside <data> blocks, never in the system prompt, and model
 * output is validated against the stored snapshot.
 */
final class InjectionScanner
{
    private const PATTERNS = [
        '/ignore (all |any )?(the )?(previous|prior|above) (instructions|prompts?)/i',
        '/disregard (all |the )?(previous|prior|above)/i',
        '/(you are now|act as) (a |an )?(different|new)? ?(ai|assistant|system)/i',
        '/\b(system prompt|developer message|<\/?system>|\[system\]|<\|im_start\|>)/i',
        '/(reveal|print|show|leak) (your|the) (system prompt|instructions|api key|secrets?)/i',
        '/(bỏ qua|phớt lờ) (mọi|tất cả|các)? ?(hướng dẫn|chỉ dẫn|yêu cầu) (trước|ở trên)/iu',
        '/(mark|set|đánh dấu) (this|all|every|mọi|tất cả).{0,30}(verified|đã xác minh)/iu',
        '/(call|invoke|use) the (tool|function)/i',
    ];

    /**
     * @return list<string> matched snippets (max 5)
     */
    public static function scan(string $text): array
    {
        $hits = [];
        foreach (self::PATTERNS as $p) {
            if (preg_match($p, $text, $m) === 1) {
                $hits[] = mb_substr($m[0], 0, 120);
            }
            if (count($hits) >= 5) {
                break;
            }
        }

        return $hits;
    }

    /**
     * Neutralise markup that could close our data wrapper.
     */
    public static function fence(string $text): string
    {
        return str_ireplace(['<data>', '</data>', '<instructions>', '</instructions>'], ['[data]', '[/data]', '[instructions]', '[/instructions]'], $text);
    }
}
