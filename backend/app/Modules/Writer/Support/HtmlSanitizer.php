<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allow-list HTML sanitizer for article content (AI output AND editor input).
 *
 * - Only structural tags survive; everything else is unwrapped (text kept)
 *   or dropped with its content (script, style, iframe, object, svg, form...).
 * - No event handlers, no style, no ids; class only from an allow-list.
 * - Links: http(s), relative or #anchor only; external links get
 *   rel="nofollow noopener" and target is removed. With `$allowedLinks`
 *   set (AI output), any other link is unwrapped — the AI cannot invent URLs.
 * - Citations `<a data-evidence-id="…">` must point at evidence of this
 *   article's dossier; the href is rewritten to the real source URL, and an
 *   unknown id is unwrapped and reported (citation spoofing).
 */
final class HtmlSanitizer
{
    private const ALLOWED = [
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'p' => [], 'br' => [], 'hr' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sup' => [], 'sub' => [], 'mark' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [], 'blockquote' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'caption' => [], 'pre' => [], 'code' => [],
        'a' => ['href', 'title', 'data-evidence-id', 'data-internal'],
        'div' => ['class'], 'section' => ['class', 'data-section'], 'aside' => ['class'], 'figure' => [], 'figcaption' => [],
    ];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select', 'noscript', 'template', 'link', 'meta', 'base', 'head', 'title', 'frame', 'frameset', 'applet', 'audio', 'video', 'canvas', 'img', 'picture', 'source'];

    private const CLASSES = ['callout', 'callout-info', 'callout-warning', 'faq', 'comparison', 'specs', 'steps', 'citation', 'cta'];

    /** @var list<array{type: string, value: string}> */
    private array $violations = [];

    /**
     * @param  array<string, string>|null  $evidenceUrls  evidence id => source URL (citations allowed)
     * @param  list<string>|null  $allowedLinks  null = any safe URL; list = only these (normalised) URLs
     */
    public function clean(string $html, ?array $evidenceUrls = [], ?array $allowedLinks = null, ?string $siteHost = null): string
    {
        $this->violations = [];
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><body><div id="__root">'.$html.'</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if ($root === null) {
            return '';
        }
        $allowed = $allowedLinks !== null ? array_flip(array_map([self::class, 'normalizeUrl'], $allowedLinks)) : null;
        $this->walk($root, $evidenceUrls ?? [], $allowed, $siteHost);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $out));
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * @param  array<string, string>  $evidence
     * @param  array<string, int>|null  $allowed
     */
    private function walk(DOMNode $node, array $evidence, ?array $allowed, ?string $siteHost): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (! $child instanceof DOMElement) {
                $node->removeChild($child); // comments, PIs, CDATA

                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $this->violations[] = ['type' => 'dropped_tag', 'value' => $tag];
                $node->removeChild($child);

                continue;
            }
            $this->walk($child, $evidence, $allowed, $siteHost);
            if (! array_key_exists($tag, self::ALLOWED)) {
                $this->unwrap($child);

                continue;
            }
            $this->cleanAttributes($child, $tag);
            if ($tag === 'a' && ! $this->cleanLink($child, $evidence, $allowed, $siteHost)) {
                $this->unwrap($child);
            }
        }
    }

    private function cleanAttributes(DOMElement $el, string $tag): void
    {
        $keep = self::ALLOWED[$tag];
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->name);
            if (! in_array($name, $keep, true)) {
                if (str_starts_with($name, 'on') || $name === 'style' || $name === 'srcdoc') {
                    $this->violations[] = ['type' => 'attribute', 'value' => $name];
                }
                $el->removeAttribute($attr->name);
            }
        }
        if ($el->hasAttribute('class')) {
            $classes = array_values(array_intersect(preg_split('/\s+/', strtolower($el->getAttribute('class'))) ?: [], self::CLASSES));
            $classes === [] ? $el->removeAttribute('class') : $el->setAttribute('class', implode(' ', $classes));
        }
        foreach (['colspan', 'rowspan', 'start'] as $num) {
            if ($el->hasAttribute($num)) {
                $v = (int) $el->getAttribute($num);
                $v >= 1 && $v <= 50 ? $el->setAttribute($num, (string) $v) : $el->removeAttribute($num);
            }
        }
        if ($el->hasAttribute('data-section') && preg_match('/^[a-z0-9_-]{1,40}$/', $el->getAttribute('data-section')) !== 1) {
            $el->removeAttribute('data-section');
        }
    }

    /**
     * @param  array<string, string>  $evidence
     * @param  array<string, int>|null  $allowed
     */
    private function cleanLink(DOMElement $a, array $evidence, ?array $allowed, ?string $siteHost): bool
    {
        if ($a->hasAttribute('data-evidence-id')) {
            $id = strtolower(trim($a->getAttribute('data-evidence-id')));
            if (! isset($evidence[$id])) {
                $this->violations[] = ['type' => 'unknown_citation', 'value' => mb_substr($id, 0, 40)];

                return false;
            }
            $a->setAttribute('data-evidence-id', $id);
            $a->setAttribute('href', $evidence[$id]);
            $a->setAttribute('rel', 'nofollow noopener');
            $a->setAttribute('class', 'citation');

            return true;
        }

        $href = trim($a->getAttribute('href'));
        if ($href === '' || ! self::safeHref($href)) {
            if ($href !== '') {
                $this->violations[] = ['type' => 'unsafe_href', 'value' => mb_substr($href, 0, 80)];
            }

            return false;
        }
        if ($allowed !== null && ! str_starts_with($href, '#') && ! isset($allowed[self::normalizeUrl($href)])) {
            $this->violations[] = ['type' => 'unlisted_link', 'value' => mb_substr($href, 0, 200)];

            return false;
        }
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $internal = $host === '' || ($siteHost !== null && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', strtolower($siteHost)));
        $internal ? $a->removeAttribute('rel') : $a->setAttribute('rel', 'nofollow noopener');
        if ($internal) {
            $a->setAttribute('data-internal', '1');
        } else {
            $a->removeAttribute('data-internal');
        }

        return true;
    }

    public static function safeHref(string $href): bool
    {
        if (str_starts_with($href, '#') || (str_starts_with($href, '/') && ! str_starts_with($href, '//'))) {
            return preg_match('/[\x00-\x1f]/', $href) !== 1;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($href, FILTER_VALIDATE_URL) !== false;
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $p = parse_url($url);
        if (! is_array($p) || ! isset($p['host'])) {
            return rtrim($url, '/');
        }
        $host = preg_replace('/^www\./', '', strtolower($p['host']));

        return strtolower($p['scheme'] ?? 'https').'://'.$host.rtrim($p['path'] ?? '', '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }

    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    /**
     * Plain text of sanitized HTML (block boundaries become newlines).
     */
    public static function text(string $html): string
    {
        $html = (string) preg_replace('#<(br|/p|/h[1-6]|/li|/tr|/td|/th|/div|/section|/blockquote|/pre)\b[^>]*>#i', "$0\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);

        return trim((string) preg_replace("/\n\s*\n+/", "\n", $text));
    }

    public static function wordCount(string $html): int
    {
        $text = self::text($html);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }
}
