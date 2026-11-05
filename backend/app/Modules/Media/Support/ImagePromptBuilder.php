<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

/**
 * Builds the image prompt from article data the customer already owns
 * (title, brief, section, audience, brand colours) — never from secrets.
 * The result is shown to the user for editing before any paid call, and is
 * scrubbed again after editing (emails, phone numbers, URLs, long tokens).
 */
final class ImagePromptBuilder
{
    public const TYPES = ['featured', 'section', 'concept', 'infographic_background'];

    public const STYLES = ['flat_illustration', 'isometric', 'line_art', 'watercolor', '3d_render', 'editorial_concept'];

    public const RATIOS = ['1:1' => '1024x1024', '16:9' => '1536x1024', '3:2' => '1536x1024', '4:3' => '1536x1024', '2:3' => '1024x1536', '9:16' => '1024x1536'];

    public const QUALITIES = ['standard', 'high'];

    public const TEMPLATES = [
        'editorial' => 'An editorial illustration for a blog article titled "{title}". Visual concept: {subject}.',
        'section' => 'An illustration for the article section "{section}" of a blog post about "{title}". Show: {subject}.',
        'concept' => 'A conceptual illustration explaining "{subject}" for readers of an article about "{title}".',
        'background' => 'A clean, minimal abstract background for an infographic about "{title}", with empty space for text, no text.',
    ];

    /**
     * @param  array{title: string, primary_keyword?: string|null, audience?: string|null, section_heading?: string|null, section_text?: string|null,
     *     brand_name?: string|null, image_type: string, style: string, brand_colors?: list<string>, template?: string|null, context?: string|null}  $in
     */
    public function build(array $in): string
    {
        $type = $in['image_type'];
        $template = $in['template'] ?? match ($type) {
            'section' => 'section',
            'concept' => 'concept',
            'infographic_background' => 'background',
            default => 'editorial',
        };
        $template = isset(self::TEMPLATES[$template]) ? $template : 'editorial';
        $subject = trim((string) ($in['context'] ?? '')) !== '' ? (string) $in['context']
            : trim(($in['section_heading'] ?? '').' '.mb_substr(trim((string) ($in['section_text'] ?? '')), 0, 240));
        if ($subject === '') {
            $subject = (string) ($in['primary_keyword'] ?? $in['title']);
        }

        $parts = [strtr(self::TEMPLATES[$template], [
            '{title}' => $this->clip($in['title'], 160),
            '{section}' => $this->clip((string) ($in['section_heading'] ?? ''), 160),
            '{subject}' => $this->clip($subject, 300),
        ])];
        $parts[] = 'Style: '.str_replace('_', ' ', $in['style']).'.';
        if (! empty($in['audience'])) {
            $parts[] = 'Audience: '.$this->clip((string) $in['audience'], 120).'.';
        }
        $colors = array_values(array_filter($in['brand_colors'] ?? [], fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1));
        if ($colors !== []) {
            $parts[] = 'Use a palette based on '.implode(', ', array_slice($colors, 0, 5)).'.';
        }
        // Safety rules are part of every prompt and are re-appended after edits.
        $parts[] = self::RULES;

        return $this->scrub(implode(' ', $parts));
    }

    public const RULES = 'Rules: illustrative image only — not a photograph of a real product, person or place; no logos, trademarks, watermarks or readable text; no real people.';

    /**
     * Applied to user-edited prompts too.
     */
    public function scrub(string $prompt): string
    {
        $prompt = strip_tags($prompt);
        $prompt = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $prompt);
        $prompt = (string) preg_replace('#\bhttps?://\S+#i', '[url]', $prompt);
        $prompt = (string) preg_replace('/(?<!\d)(?:\+?84|0)(?:[\s.-]?\d){8,10}(?!\d)/', '[phone]', $prompt);
        $prompt = (string) preg_replace('/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{32,}\b/', '[redacted]', $prompt);
        $prompt = trim((string) preg_replace('/\s+/u', ' ', $prompt));
        if (! str_contains($prompt, self::RULES)) {
            $prompt .= ' '.self::RULES;
        }

        return mb_substr($prompt, 0, 3000);
    }

    private function clip(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', strip_tags($s)));

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }
}
