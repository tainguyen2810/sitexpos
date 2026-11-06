<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Retrieval;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Main text + metadata of a retrieved page. Metadata comes ONLY from the
 * page itself (meta tags, JSON-LD, <time>); anything absent stays null —
 * the system never guesses an author, publisher or date.
 */
final class PageExtractor
{
    private const NOISE = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'form', 'nav', 'footer', 'header', 'aside', 'button', 'select', 'input', 'textarea'];

    private const MAX_TEXT = 200_000;

    /**
     * @return array{title: string|null, publisher: string|null, author: string|null, published_at: string|null, modified_at: string|null,
     *     language: string|null, canonical: string|null, license: string|null, text: string, access_blocked: string|null}
     */
    public function extract(string $body, string $contentType, string $url): array
    {
        if (! str_contains($contentType, 'html') && ! str_contains($contentType, 'xml')) {
            $text = $this->normalize(mb_convert_encoding($body, 'UTF-8', 'UTF-8'));

            return ['title' => null, 'publisher' => null, 'author' => null, 'published_at' => null, 'modified_at' => null, 'language' => null,
                'canonical' => null, 'license' => null, 'text' => mb_substr($text, 0, self::MAX_TEXT), 'access_blocked' => null];
        }

        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?>'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $meta = function (string $attr, string $name) use ($xp): ?string {
            $n = $xp->query("//meta[translate(@{$attr},'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$name}']/@content")?->item(0);
            $v = $n !== null ? trim((string) $n->nodeValue) : '';

            return $v === '' ? null : mb_substr($v, 0, 300);
        };
        $ld = $this->jsonLd($xp);

        $title = $meta('property', 'og:title') ?? $this->first($xp, '//title') ?? $this->first($xp, '//h1');
        $publisher = $meta('property', 'og:site_name') ?? $ld['publisher'];
        $author = $meta('name', 'author') ?? $meta('property', 'article:author') ?? $ld['author'];
        if ($author !== null && preg_match('#^https?://#i', $author) === 1) {
            $author = null; // profile URL, not a name
        }
        $published = $this->date($meta('property', 'article:published_time') ?? $ld['datePublished'] ?? $meta('name', 'date') ?? $this->attr($xp, '//time[@datetime]', 'datetime'));
        $modified = $this->date($meta('property', 'article:modified_time') ?? $ld['dateModified'] ?? $meta('property', 'og:updated_time'));
        $lang = $this->attr($xp, '//html[@lang]', 'lang');
        $canonical = $this->attr($xp, "//link[@rel='canonical']", 'href');
        $license = $this->attr($xp, "//link[@rel='license']", 'href') ?? $meta('name', 'copyright');

        foreach (self::NOISE as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) {
                $el->parentNode?->removeChild($el);
            }
        }
        foreach (iterator_to_array($xp->query('//*[@hidden or @aria-hidden="true" or contains(translate(@style," ",""),"display:none")]') ?: []) as $el) {
            $el->parentNode?->removeChild($el);
        }
        $main = $xp->query('//article')?->item(0) ?? $xp->query('//main')?->item(0) ?? $doc->getElementsByTagName('body')->item(0);
        $text = $main instanceof DOMElement ? $this->blockText($main) : '';
        $text = mb_substr($this->normalize($text), 0, self::MAX_TEXT);

        return [
            'title' => $title !== null ? mb_substr($title, 0, 500) : null,
            'publisher' => $publisher, 'author' => $author, 'published_at' => $published, 'modified_at' => $modified,
            'language' => $lang !== null ? mb_substr($lang, 0, 10) : null, 'canonical' => $canonical, 'license' => $license !== null ? mb_substr($license, 0, 300) : null,
            'text' => $text, 'access_blocked' => self::accessBlocked($text),
        ];
    }

    /**
     * Captcha / paywall / login wall: we never try to get around it.
     */
    public static function accessBlocked(string $text): ?string
    {
        $t = mb_strtolower(mb_substr($text, 0, 4000));
        if (preg_match('/(captcha|are you a robot|verify you are human|cf-chl|just a moment\.\.\.|xác minh bạn không phải robot)/u', $t) === 1) {
            return 'captcha';
        }
        if (mb_strlen($text) < 1500 && preg_match('/(subscribe to (read|continue)|đăng ký để đọc|sign in to (read|continue)|đăng nhập để (xem|đọc)|this content is for subscribers|paywall)/u', $t) === 1) {
            return 'paywall';
        }

        return null;
    }

    private function blockText(DOMElement $el): string
    {
        $html = $el->ownerDocument?->saveHTML($el) ?? '';
        $html = (string) preg_replace('#<(br|/p|/h[1-6]|/li|/tr|/div|/section|/blockquote|/pre|/table|/td|/th)\b[^>]*>#i', "$0\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function normalize(string $text): string
    {
        $text = (string) preg_replace("/[ \t\x{00A0}\x{200B}]+/u", ' ', $text);
        $text = (string) preg_replace("/ *\n */u", "\n", $text);

        return trim((string) preg_replace("/\n{2,}/u", "\n", $text));
    }

    private function first(DOMXPath $xp, string $q): ?string
    {
        $n = $xp->query($q)?->item(0);
        $v = $n !== null ? trim((string) preg_replace('/\s+/u', ' ', $n->textContent)) : '';

        return $v === '' ? null : $v;
    }

    private function attr(DOMXPath $xp, string $q, string $attr): ?string
    {
        $n = $xp->query($q)?->item(0);
        $v = $n instanceof DOMElement ? trim($n->getAttribute($attr)) : '';

        return $v === '' ? null : mb_substr($v, 0, 1000);
    }

    private function date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false || $ts < strtotime('1990-01-01') || $ts > time() + 86400) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * @return array{publisher: string|null, author: string|null, datePublished: string|null, dateModified: string|null}
     */
    private function jsonLd(DOMXPath $xp): array
    {
        $out = ['publisher' => null, 'author' => null, 'datePublished' => null, 'dateModified' => null];
        foreach (iterator_to_array($xp->query("//script[@type='application/ld+json']") ?: []) as $node) {
            $data = json_decode(trim((string) $node->textContent), true);
            if (! is_array($data)) {
                continue;
            }
            $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = static fn (mixed $v): ?string => is_string($v) ? $v : (is_array($v) ? (is_string($v['name'] ?? null) ? $v['name'] : (is_array($v[0] ?? null) && is_string($v[0]['name'] ?? null) ? $v[0]['name'] : null)) : null);
                $out['publisher'] ??= $name($item['publisher'] ?? null);
                $out['author'] ??= $name($item['author'] ?? null);
                $out['datePublished'] ??= is_string($item['datePublished'] ?? null) ? $item['datePublished'] : null;
                $out['dateModified'] ??= is_string($item['dateModified'] ?? null) ? $item['dateModified'] : null;
            }
        }

        return array_map(static fn (?string $v): ?string => $v !== null ? mb_substr(trim($v), 0, 300) : null, $out);
    }
}
