<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

/**
 * Article HTML ⇄ sections. A section is the intro (content before the first
 * H2, key "intro") or an H2 with everything up to the next H2. Keys survive
 * edits: a section keeps its key when its heading still matches one of the
 * previous version's headings, so claims / approvals can be invalidated per
 * section instead of for the whole article.
 */
final class ArticleDocument
{
    /**
     * @param  list<array{key: string, heading: string}>  $previous  previous version sections (for key reuse)
     * @return list<array{key: string, heading: string, html: string, text: string, hash: string}>
     */
    public static function split(string $html, array $previous = []): array
    {
        $html = (string) preg_replace('#^\s*<h1\b[^>]*>.*?</h1>#is', '', $html);
        $parts = preg_split('#(?=<h2\b)#i', $html) ?: [];
        $byHeading = [];
        foreach ($previous as $p) {
            $byHeading[self::norm($p['heading'])] ??= $p['key'];
        }

        $out = [];
        $used = [];
        foreach ($parts as $i => $part) {
            if (trim(strip_tags($part)) === '' && ! str_contains($part, '<table')) {
                continue;
            }
            $heading = '';
            if (preg_match('#^<h2\b[^>]*>(.*?)</h2>#is', $part, $m) === 1) {
                $heading = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if ($heading === '' && $out === []) {
                $key = 'intro';
            } else {
                $key = $byHeading[self::norm($heading)] ?? 'x'.substr(sha1($heading.'|'.$i), 0, 7);
            }
            if (isset($used[$key])) {
                $key .= '-'.$i;
            }
            $used[$key] = true;
            $text = HtmlSanitizer::text($part);
            $out[] = ['key' => $key, 'heading' => $heading, 'html' => trim($part), 'text' => $text, 'hash' => hash('sha256', self::norm($text))];
        }

        return $out;
    }

    /**
     * @param  list<array{key: string, heading: string, level?: int, html: string}>  $sections
     */
    public static function assemble(string $title, array $sections): string
    {
        $html = '<h1>'.e($title).'</h1>';
        foreach ($sections as $s) {
            $body = trim($s['html']);
            if ($s['key'] !== 'intro' && $s['heading'] !== '' && preg_match('#^<h2\b#i', $body) !== 1) {
                $body = '<h2>'.e($s['heading']).'</h2>'.$body;
            }
            $html .= "\n".$body;
        }

        return $html;
    }

    /**
     * @param  list<array{key: string, heading: string}>  $previous
     * @return list<array{key: string, heading: string, hash: string}>
     */
    public static function hashes(string $html, array $previous = []): array
    {
        return array_map(static fn (array $s): array => ['key' => $s['key'], 'heading' => $s['heading'], 'hash' => $s['hash']], self::split($html, $previous));
    }

    /**
     * Keys whose content changed (or appeared / disappeared) between two versions.
     *
     * @param  list<array{key: string, hash: string}>  $before
     * @param  list<array{key: string, hash: string}>  $after
     * @return list<string>
     */
    public static function changedKeys(array $before, array $after): array
    {
        $a = array_column($before, 'hash', 'key');
        $b = array_column($after, 'hash', 'key');
        $changed = [];
        foreach ($a + $b as $key => $_) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    public static function title(string $html): ?string
    {
        if (preg_match('#<h1\b[^>]*>(.*?)</h1>#is', $html, $m) === 1) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $t === '' ? null : mb_substr($t, 0, 300);
        }

        return null;
    }

    public static function norm(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}
