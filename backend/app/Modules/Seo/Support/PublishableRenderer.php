<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Media\Contracts\MediaLibrary;
use SiteXPost\Modules\Seo\Domain\SeoLinkSuggestion;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Builds the exact HTML that is previewed, audited and published:
 * approved version (H1 removed — WordPress prints the title) +
 * citations turned into a "Nguồn tham khảo" list of the sources really cited
 * + accepted internal links (inserted only where the anchor text already
 * exists in the paragraph, max one link per paragraph) + accepted images
 * (featured separately; inline figures with alt / caption / AI label).
 * Publishing uses `{{sitex-image:KEY}}` placeholders that the connector
 * replaces after uploading; previews use signed preview URLs.
 */
final class PublishableRenderer
{
    public function __construct(private readonly ArticleCatalog $articles, private readonly MediaLibrary $media) {}

    /**
     * @return array{article: array<string, mixed>, version_id: string, title: string, html: string, hash: string, approved: bool,
     *     images: list<array<string, mixed>>, featured: array<string, mixed>|null, links: list<array<string, mixed>>, stale_links: list<string>, sources: int}
     */
    public function render(string $articleId, bool $forPublish): array
    {
        $article = $this->articles->find($articleId) ?? throw ApiException::notFound();
        $approved = $this->articles->approvedContent($articleId);
        $content = $approved ?? ($forPublish ? null : $this->articles->currentContent($articleId));
        if ($content === null) {
            throw new ApiException(409, 'article_not_approved', __('seo.errors.article_not_approved'));
        }
        $sources = $approved['sources'] ?? [];

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="sx-root">'.$content['html'].'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $root = $doc->getElementById('sx-root');
        if ($root === null) {
            throw new ApiException(422, 'invalid_content', __('api.validation_failed'));
        }

        foreach (iterator_to_array($xp->query('.//h1', $root) ?: []) as $h1) {
            $h1->parentNode?->removeChild($h1);
        }

        // Citations → numbered references to the sources list.
        $cited = [];
        foreach (iterator_to_array($xp->query('.//a[@data-evidence-id]', $root) ?: []) as $a) {
            /** @var DOMElement $a */
            $id = $a->getAttribute('data-evidence-id');
            $wrapper = $a->parentNode instanceof DOMElement && strtolower($a->parentNode->tagName) === 'sup' ? $a->parentNode : $a;
            if (! isset($sources[$id])) {
                $wrapper->parentNode?->removeChild($wrapper);

                continue;
            }
            $n = $cited[$id] ??= count($cited) + 1;
            $sup = $doc->createElement('sup');
            $link = $doc->createElement('a', '['.$n.']');
            $link->setAttribute('href', '#nguon-'.$n);
            $sup->appendChild($link);
            $wrapper->parentNode?->replaceChild($sup, $wrapper);
        }

        // Internal links.
        $paragraphs = iterator_to_array($xp->query('.//p', $root) ?: []);
        $nonEmpty = array_values(array_filter($paragraphs, fn (DOMElement $p) => trim($p->textContent) !== ''));
        $applied = [];
        $stale = [];
        foreach (SeoLinkSuggestion::query()->where('article_id', $articleId)->where('status', 'accepted')->orderBy('paragraph_index')->get() as $s) {
            $p = $nonEmpty[$s->paragraph_index] ?? null;
            if ($p === null || $p->getElementsByTagName('a')->length > 0 || ! $this->linkify($doc, $p, $s->anchor_text, $s->target_url)) {
                $stale[] = $s->id;

                continue;
            }
            $applied[] = ['id' => $s->id, 'url' => $s->target_url, 'anchor' => $s->anchor_text, 'wp_post_id' => $s->target_wp_post_id];
        }

        // Images.
        $images = [];
        $featured = null;
        foreach ($this->media->placements($articleId, true) as $pl) {
            $asset = $pl['asset'];
            if ($asset === null || $asset['status'] !== 'ready') {
                continue;
            }
            $key = 'p'.substr($pl['id'], -10);
            $remoteWp = in_array($asset['source_type'], ['wordpress', 'woocommerce'], true) ? (int) ($asset['wp_media_id'] ?? 0) : 0;
            $image = ['key' => $key, 'placement_id' => $pl['id'], 'asset_id' => $asset['id'], 'slot' => $pl['slot'], 'alt' => (string) $pl['alt_text'],
                'caption' => $pl['caption'], 'title' => $asset['title'], 'is_ai_generated' => $asset['is_ai_generated'], 'bytes' => $asset['bytes'],
                'width' => $asset['width'], 'height' => $asset['height'], 'wp_media_id' => $remoteWp > 0 ? $remoteWp : null,
                'remote_url' => $remoteWp > 0 ? $asset['source_url'] : null, 'preview_url' => $asset['preview_url'], 'source_type' => $asset['source_type']];
            if ($pl['slot'] === 'featured') {
                $featured = $image;

                continue;
            }
            $src = $forPublish ? ($image['remote_url'] ?? '{{sitex-image:'.$key.'}}') : (string) ($asset['preview_url'] ?? '');
            $figure = $this->figure($doc, $src, $image);
            $anchor = $this->anchorFor($xp, $root, $pl['slot'], $pl['section_heading']);
            if ($anchor === null) {
                $root->appendChild($figure);
            } else {
                $anchor->parentNode?->insertBefore($figure, $anchor->nextSibling);
            }
            $images[] = $image;
        }

        if ($cited !== []) {
            $root->appendChild($doc->createElement('h2', 'Nguồn tham khảo'));
            $ol = $doc->createElement('ol');
            foreach ($cited as $id => $n) {
                $li = $doc->createElement('li');
                $li->setAttribute('id', 'nguon-'.$n);
                $a = $doc->createElement('a');
                $a->appendChild($doc->createTextNode((string) ($sources[$id]['title'] ?: $sources[$id]['url'])));
                $a->setAttribute('href', $sources[$id]['url']);
                $a->setAttribute('rel', 'nofollow noopener');
                $a->setAttribute('target', '_blank');
                $li->appendChild($a);
                if (! empty($sources[$id]['publisher'])) {
                    $li->appendChild($doc->createTextNode(' — '.$sources[$id]['publisher']));
                }
                $ol->appendChild($li);
            }
            $root->appendChild($ol);
        }

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        $html = trim($html);

        return [
            'article' => $article, 'version_id' => $content['version_id'], 'title' => $content['title'] ?: $article['title'], 'html' => $html,
            'hash' => hash('sha256', $html.'|'.json_encode([$featured['asset_id'] ?? null, array_column($images, 'alt'), array_column($images, 'caption')])),
            'approved' => $approved !== null, 'images' => $images, 'featured' => $featured, 'links' => $applied, 'stale_links' => $stale, 'sources' => count($cited),
        ];
    }

    private function linkify(DOMDocument $doc, DOMElement $p, string $anchor, string $url): bool
    {
        foreach (iterator_to_array($p->childNodes) as $node) {
            if (! $node instanceof DOMText) {
                continue;
            }
            $text = $node->wholeText;
            $pos = mb_stripos($text, $anchor);
            if ($pos === false) {
                continue;
            }
            $before = mb_substr($text, 0, $pos);
            $match = mb_substr($text, $pos, mb_strlen($anchor));
            $after = mb_substr($text, $pos + mb_strlen($anchor));
            $a = $doc->createElement('a');
            $a->appendChild($doc->createTextNode($match));
            $a->setAttribute('href', $url);
            $p->insertBefore($doc->createTextNode($before), $node);
            $p->insertBefore($a, $node);
            $p->insertBefore($doc->createTextNode($after), $node);
            $p->removeChild($node);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function figure(DOMDocument $doc, string $src, array $image): DOMElement
    {
        $figure = $doc->createElement('figure');
        $figure->setAttribute('class', 'sitex-figure'.($image['is_ai_generated'] ? ' sitex-ai-image' : ''));
        $img = $doc->createElement('img');
        $img->setAttribute('src', $src);
        $img->setAttribute('alt', (string) $image['alt']);
        if (! empty($image['width']) && ! empty($image['height'])) {
            $img->setAttribute('width', (string) $image['width']);
            $img->setAttribute('height', (string) $image['height']);
        }
        $img->setAttribute('loading', 'lazy');
        $figure->appendChild($img);
        $caption = trim((string) ($image['caption'] ?? ''));
        if ($image['is_ai_generated'] && ! str_contains(mb_strtolower($caption), 'ai')) {
            $caption = trim($caption.' ('.__('media.labels.ai_caption').')');
        }
        if ($caption !== '') {
            $fc = $doc->createElement('figcaption');
            $fc->appendChild($doc->createTextNode($caption));
            $figure->appendChild($fc);
        }

        return $figure;
    }

    private function anchorFor(DOMXPath $xp, DOMElement $root, string $slot, ?string $heading): ?DOMNode
    {
        if (in_array($slot, ['intro', 'product'], true) || $heading === null || $heading === '') {
            return $xp->query('./p[1]', $root)?->item(0);
        }
        foreach ($xp->query('./h2', $root) ?: [] as $h2) {
            if (ArticleHtml::clean($h2->textContent) === ArticleHtml::clean($heading)) {
                $n = $h2->nextSibling;
                while ($n !== null && ! ($n instanceof DOMElement && in_array(strtolower($n->tagName), ['p', 'table', 'ul', 'ol'], true))) {
                    $n = $n->nextSibling;
                }
                if ($slot === 'infographic' || $slot === 'comparison') {
                    $m = $h2->nextSibling;
                    while ($m !== null && ! ($m instanceof DOMElement && strtolower($m->tagName) === 'h2')) {
                        if ($m instanceof DOMElement && strtolower($m->tagName) === 'table') {
                            return $m;
                        }
                        $m = $m->nextSibling;
                    }
                }

                return $n ?? $h2;
            }
        }

        return null;
    }
}
