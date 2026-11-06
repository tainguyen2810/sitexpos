<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Crawler;

use DOMDocument;
use DOMElement;
use DOMXPath;
use SiteXPost\Modules\Research\Support\Text;

/**
 * Extracts page STRUCTURE only (title, meta, headings, questions, internal
 * links, word count). Body text is used for counting/hashing and discarded —
 * third-party content is never stored verbatim.
 */
final class HtmlExtractor
{
    private const QUESTION_STARTS = ['cách', 'làm sao', 'làm thế nào', 'tại sao', 'vì sao', 'có nên', 'nên', 'khi nào', 'bao nhiêu', 'là gì', 'what', 'how', 'why', 'when', 'which', 'can', 'should', 'is', 'are', 'do', 'does'];

    /**
     * @return array{title: string, meta_description: string, language: string|null, site_name: string|null, canonical: string|null,
     *               headings: list<array{level: int, text: string}>, questions: list<string>, internal_links: list<array{url: string, anchor: string}>,
     *               word_count: int, content_hash: string}
     */
    public function extract(string $html, string $url): array
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new DOMXPath($doc);

        foreach (['//script', '//style', '//noscript', '//template', '//svg'] as $q) {
            foreach (iterator_to_array($xp->query($q) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $title = Text::clean($xp->evaluate('string(//title)'), 500);
        $meta = Text::clean($xp->evaluate('string(//meta[translate(@name,"DESCRIPTION","description")="description"]/@content)'), 1000);
        $siteName = Text::clean($xp->evaluate('string(//meta[@property="og:site_name"]/@content)'), 190);
        $lang = Text::clean($xp->evaluate('string(/html/@lang)'), 10);
        $canonical = Text::clean($xp->evaluate('string(//link[@rel="canonical"]/@href)'), 1000);

        $headings = [];
        $questions = [];
        foreach ($xp->query('//h1|//h2|//h3') ?: [] as $h) {
            if (! $h instanceof DOMElement) {
                continue;
            }
            $text = Text::clean($h->textContent, 300);
            if ($text === '' || mb_strlen($text) < 3) {
                continue;
            }
            $headings[] = ['level' => (int) substr($h->nodeName, 1), 'text' => $text];
            if (self::isQuestion($text)) {
                $questions[] = $text;
            }
            if (count($headings) >= 80) {
                break;
            }
        }

        $links = [];
        $seen = [];
        foreach ($xp->query('//a[@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $abs = PublicFetcher::resolve($url, $a->getAttribute('href'));
            if ($abs === null || ! PublicFetcher::sameSite($url, $abs) || isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;
            $links[] = ['url' => mb_substr($abs, 0, 1000), 'anchor' => Text::clean($a->textContent, 190)];
            if (count($links) >= 150) {
                break;
            }
        }

        $bodyText = Text::clean($xp->evaluate('string(//body)'), 2_000_000);
        $words = $bodyText === '' ? 0 : count(preg_split('/\s+/u', $bodyText) ?: []);

        return [
            'title' => $title,
            'meta_description' => $meta,
            'language' => $lang !== '' ? strtolower(substr($lang, 0, 2)) : null,
            'site_name' => $siteName !== '' ? $siteName : null,
            'canonical' => $canonical !== '' ? $canonical : null,
            'headings' => $headings,
            'questions' => array_values(array_unique($questions)),
            'internal_links' => $links,
            'word_count' => $words,
            'content_hash' => hash('sha256', $bodyText),
        ];
    }

    public static function isQuestion(string $text): bool
    {
        if (str_ends_with(trim($text), '?')) {
            return true;
        }
        $lower = mb_strtolower($text);
        foreach (self::QUESTION_STARTS as $q) {
            if (str_starts_with($lower, $q.' ') && in_array($q, ['cách', 'làm sao', 'làm thế nào', 'tại sao', 'vì sao', 'có nên', 'khi nào', 'what', 'how', 'why', 'when', 'which', 'should'], true)) {
                return true;
            }
        }

        return str_contains($lower, ' là gì') || str_contains($lower, ' có nên ') || str_contains($lower, ' bao nhiêu');
    }
}
