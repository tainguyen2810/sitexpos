<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Read-only structural view of article HTML for the auditor and the link
 * engine: headings, paragraphs (with their H2 section), links, images,
 * tables, lists. Parsing only — never used to output HTML.
 */
final class ArticleHtml
{
    /** @var list<array{level: int, text: string}> */
    public array $headings = [];

    /** @var list<array{index: int, section: string, text: string, has_link: bool, words: int}> */
    public array $paragraphs = [];

    /** @var list<array{href: string, text: string, rel: string}> */
    public array $links = [];

    /** @var list<array{src: string, alt: string}> */
    public array $images = [];

    public int $tables = 0;

    /** @var list<array{ordered: bool, items: int}> */
    public array $lists = [];

    public string $text = '';

    public int $words = 0;

    public static function parse(string $html): self
    {
        $out = new self;
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="sx-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $section = 'intro';
        $p = 0;
        foreach ($xp->query('//*[@id="sx-root"]//*') ?: [] as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($el->tagName);
            if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
                $text = self::clean($el->textContent);
                $out->headings[] = ['level' => (int) $m[1], 'text' => $text];
                if ($tag === 'h2') {
                    $section = $text;
                }
            } elseif ($tag === 'p') {
                $text = self::clean($el->textContent);
                if ($text !== '') {
                    $out->paragraphs[] = ['index' => $p++, 'section' => $section, 'text' => $text, 'has_link' => $el->getElementsByTagName('a')->length > 0,
                        'words' => self::wordCount($text)];
                }
            } elseif ($tag === 'a') {
                $out->links[] = ['href' => trim($el->getAttribute('href')), 'text' => self::clean($el->textContent), 'rel' => strtolower($el->getAttribute('rel'))];
            } elseif ($tag === 'img') {
                $out->images[] = ['src' => trim($el->getAttribute('src')), 'alt' => trim($el->getAttribute('alt'))];
            } elseif ($tag === 'table') {
                $out->tables++;
            } elseif ($tag === 'ol' || $tag === 'ul') {
                $out->lists[] = ['ordered' => $tag === 'ol', 'items' => $el->getElementsByTagName('li')->length];
            }
        }
        $root = $doc->getElementById('sx-root');
        $out->text = self::clean($root?->textContent ?? '');
        $out->words = self::wordCount($out->text);

        return $out;
    }

    public static function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Sentences of the body text (Vietnamese/English punctuation).
     *
     * @return list<string>
     */
    public function sentences(): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+/u', implode(' ', array_column($this->paragraphs, 'text')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}
