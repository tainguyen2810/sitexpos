<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * JSON-LD that matches the published content and nothing more.
 *
 * - Off unless the site enabled injection (schema_configs.injection_enabled).
 * - Article / BlogPosting / BreadcrumbList are skipped when Yoast or Rank
 *   Math is active (they already print them → no duplicate graph).
 * - FAQPage only when the article has an FAQ H2 with ≥ 2 H3 questions and
 *   their answers, copied verbatim.
 * - HowTo only for guide content with an ordered list of ≥ 3 steps.
 * - BreadcrumbList only when the site says its theme supports it and the
 *   post URL is known.
 * - Author/publisher is the organization (site name) — never an invented
 *   person. Rich results are never promised.
 */
final class SchemaBuilder
{
    public const TYPES = ['Article', 'BlogPosting', 'FAQPage', 'HowTo', 'BreadcrumbList'];

    /**
     * @param  array<string, mixed>  $config  injection_enabled, enabled_types, organization_name, logo_url, breadcrumbs_supported
     * @param  array<string, mixed>  $site  publishingProfile
     * @param  array<string, mixed>  $article
     * @param  array<string, mixed>  $meta
     * @param  array{url?: string|null, image_url?: string|null, published_at?: string|null, modified_at?: string|null}  $extra
     * @return array{enabled: bool, types: list<string>, skipped: array<string, string>, graph: list<array<string, mixed>>}
     */
    public function build(array $config, array $site, array $article, string $title, string $html, array $meta, array $extra = []): array
    {
        $out = ['enabled' => (bool) ($config['injection_enabled'] ?? false), 'types' => [], 'skipped' => [], 'graph' => []];
        if (! $out['enabled']) {
            return $out;
        }
        $wanted = array_values(array_intersect((array) ($meta['schema_types'] ?? []), (array) ($config['enabled_types'] ?? []), self::TYPES));
        $seoPlugin = (bool) ($site['yoast'] ?? false) || (bool) ($site['rank_math'] ?? false);
        $org = ['@type' => 'Organization', 'name' => (string) (($config['organization_name'] ?? '') ?: ($site['host'] ?? ''))];
        if (! empty($config['logo_url'])) {
            $org['logo'] = ['@type' => 'ImageObject', 'url' => (string) $config['logo_url']];
        }
        $url = $extra['url'] ?? null;

        foreach ($wanted as $type) {
            if (in_array($type, ['Article', 'BlogPosting', 'BreadcrumbList'], true) && $seoPlugin) {
                $out['skipped'][$type] = 'seo_plugin_outputs';

                continue;
            }
            $node = match ($type) {
                'Article', 'BlogPosting' => $this->article($type, $title, $meta, $org, $url, $extra, (string) ($article['language'] ?? 'vi')),
                'FAQPage' => $this->faq($html),
                'HowTo' => in_array($article['content_type'] ?? '', ['guide', 'how_to', 'howto', 'tutorial'], true) ? $this->howTo($title, $html) : null,
                'BreadcrumbList' => ($config['breadcrumbs_supported'] ?? false) && $url !== null && $url !== '' ? $this->breadcrumb((string) ($site['url'] ?? ''), $title, $url) : null,
                default => null,
            };
            if ($node === null) {
                $out['skipped'][$type] = 'not_eligible';

                continue;
            }
            if ($type === 'Article' && in_array('BlogPosting', $out['types'], true) || $type === 'BlogPosting' && in_array('Article', $out['types'], true)) {
                $out['skipped'][$type] = 'duplicate_type';

                continue;
            }
            $out['types'][] = $type;
            $out['graph'][] = $node;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $org
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function article(string $type, string $title, array $meta, array $org, ?string $url, array $extra, string $lang): array
    {
        $node = ['@context' => 'https://schema.org', '@type' => $type, 'headline' => mb_substr($title, 0, 110), 'inLanguage' => $lang,
            'author' => $org, 'publisher' => $org];
        if (! empty($meta['meta_description'])) {
            $node['description'] = (string) $meta['meta_description'];
        }
        if ($url !== null && $url !== '') {
            $node['mainEntityOfPage'] = ['@type' => 'WebPage', '@id' => $url];
        }
        if (! empty($extra['image_url'])) {
            $node['image'] = [(string) $extra['image_url']];
        }
        if (! empty($extra['published_at'])) {
            $node['datePublished'] = (string) $extra['published_at'];
            $node['dateModified'] = (string) ($extra['modified_at'] ?? $extra['published_at']);
        }

        return $node;
    }

    /**
     * Q/A pairs under an H2 whose text says FAQ / câu hỏi thường gặp.
     *
     * @return array<string, mixed>|null
     */
    public function faq(string $html): ?array
    {
        $pairs = self::faqPairs($html);
        if (count($pairs) < 2) {
            return null;
        }

        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($p) => [
            '@type' => 'Question', 'name' => $p['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p['answer']],
        ], $pairs)];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function faqPairs(string $html): array
    {
        $root = self::root($html);
        $pairs = [];
        $inFaq = false;
        $q = null;
        $answer = '';
        foreach ($root->childNodes as $n) {
            if (! $n instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($n->tagName);
            $text = ArticleHtml::clean($n->textContent);
            if ($tag === 'h2') {
                if ($inFaq && $q !== null && $answer !== '') {
                    $pairs[] = ['question' => $q, 'answer' => $answer];
                }
                $folded = mb_strtolower($text);
                $inFaq = str_contains($folded, 'faq') || str_contains($folded, 'câu hỏi thường gặp') || str_contains($folded, 'hỏi đáp');
                $q = null;
                $answer = '';

                continue;
            }
            if (! $inFaq) {
                continue;
            }
            if ($tag === 'h3') {
                if ($q !== null && $answer !== '') {
                    $pairs[] = ['question' => $q, 'answer' => $answer];
                }
                $q = $text;
                $answer = '';
            } elseif ($q !== null && in_array($tag, ['p', 'ul', 'ol'], true) && $text !== '') {
                $answer = trim($answer.' '.$text);
            }
        }
        if ($inFaq && $q !== null && $answer !== '') {
            $pairs[] = ['question' => $q, 'answer' => $answer];
        }

        return array_values(array_filter($pairs, fn ($p) => $p['question'] !== '' && mb_strlen($p['answer']) >= 10));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function howTo(string $title, string $html): ?array
    {
        $root = self::root($html);
        $xp = new DOMXPath($root->ownerDocument ?? new DOMDocument);
        foreach ($xp->query('.//ol', $root) ?: [] as $ol) {
            /** @var DOMElement $ol */
            $prev = $ol->previousSibling;
            while ($prev !== null && ! $prev instanceof DOMElement) {
                $prev = $prev->previousSibling;
            }
            if ($prev instanceof DOMElement && strtolower($prev->tagName) === 'h2' && str_contains(mb_strtolower($prev->textContent), 'nguồn')) {
                continue; // the sources list is not a procedure
            }
            $steps = [];
            foreach ($ol->getElementsByTagName('li') as $li) {
                $t = ArticleHtml::clean($li->textContent);
                if ($t !== '') {
                    $steps[] = ['@type' => 'HowToStep', 'text' => mb_substr($t, 0, 500)];
                }
            }
            if (count($steps) >= 3) {
                return ['@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => mb_substr($title, 0, 110), 'step' => $steps];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function breadcrumb(string $home, string $title, string $url): array
    {
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Trang chủ', 'item' => rtrim($home, '/').'/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => mb_substr($title, 0, 110), 'item' => $url],
        ]];
    }

    private static function root(string $html): DOMElement
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="sx-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return $doc->getElementById('sx-root') ?? $doc->createElement('div');
    }
}
