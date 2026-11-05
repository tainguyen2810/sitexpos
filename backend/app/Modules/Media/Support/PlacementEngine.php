<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;

/**
 * Suggests where images belong in an article, conservatively:
 * - one featured image;
 * - an opening image only for long articles (> 800 words);
 * - at most one image per H2 section of ≥ 150 words, skipping FAQ /
 *   conclusion sections, and never more than words / 350 images in total
 *   (cap 6) — images are not added just to raise a count;
 * - comparison sections / tables → infographic slot (deterministic renderer);
 * - a "real product" slot only when a WooCommerce product matches the topic,
 *   filled only with that product's own image (AI images never qualify).
 * Candidates are ranked by token overlap with the section; a slot without a
 * good candidate is still suggested (empty) with the reason, so the user can
 * pick, upload or generate.
 */
final class PlacementEngine
{
    /**
     * @param  array{title: string, primary_keyword: string}  $article
     * @param  list<MediaAsset>  $assets  ready assets of the site / article
     * @return list<array{slot: string, section_key: string|null, section_heading: string|null, position: int, media_asset_id: string|null, alt_text: string|null, caption: string|null, reason: string}>
     */
    public function suggest(array $article, string $html, array $assets): array
    {
        $words = HtmlSanitizer::wordCount($html);
        $sections = ArticleDocument::split($html);
        $budget = max(1, min(6, intdiv($words, 350)));
        $used = [];
        $out = [];
        $topic = Text::tokens($article['title'].' '.$article['primary_keyword']);

        // Featured: AI featured image made for this article, else best match.
        $featured = $this->best($assets, $topic, $used, fn (MediaAsset $a) => $a->source_type !== 'woocommerce' || $this->productMatches($a, $topic),
            fn (MediaAsset $a) => ($a->metadata['image_type'] ?? null) === 'featured' ? 0.5 : 0.0);
        $out[] = $this->row('featured', null, null, 0, $featured, $article['title'],
            $featured !== null ? __('media.placement.featured_match') : __('media.placement.no_candidate'));

        $position = 1;
        if ($words > 800 && $budget > 1) {
            $intro = collect($sections)->firstWhere('key', 'intro');
            $cand = $this->best($assets, $topic, $used, fn (MediaAsset $a) => $a->source_type !== 'woocommerce');
            $out[] = $this->row('intro', 'intro', null, $position++, $cand, $article['title'], __('media.placement.intro_long'));
            $budget--;
            unset($intro);
        }

        // Real product photo (WooCommerce only).
        foreach ($assets as $a) {
            if ($a->source_type === 'woocommerce' && ! isset($used[$a->id]) && $this->productMatches($a, $topic)) {
                $used[$a->id] = true;
                $out[] = $this->row('product', null, null, $position++, $a, (string) ($a->title ?? $article['title']), __('media.placement.product_match'));
                $budget--;
                break;
            }
        }

        foreach ($sections as $s) {
            if ($budget <= 0) {
                break;
            }
            if ($s['key'] === 'intro' || str_word_count(Text::fold($s['text'])) < 150 || $this->skipSection($s['heading'])) {
                continue;
            }
            $tokens = Text::tokens($s['heading'].' '.mb_substr($s['text'], 0, 400));
            if ($this->isComparison($s['heading'], $s['html'])) {
                $cand = $this->best($assets, $tokens, $used, fn (MediaAsset $a) => $a->source_type === 'infographic');
                $out[] = $this->row('infographic', $s['key'], $s['heading'], $position++, $cand, $s['heading'], __('media.placement.comparison'));
            } else {
                $cand = $this->best($assets, $tokens, $used, fn (MediaAsset $a) => $a->source_type !== 'woocommerce', null, 0.15);
                $out[] = $this->row('section', $s['key'], $s['heading'], $position++, $cand, $s['heading'], __('media.placement.section'));
            }
            $budget--;
        }

        return $out;
    }

    /**
     * Placement rules that also apply to manual choices.
     *
     * @throws MediaRejected
     */
    public static function assertCompatible(string $slot, ?MediaAsset $asset): void
    {
        if ($asset === null) {
            return;
        }
        if ($asset->status !== 'ready') {
            throw new MediaRejected('asset_not_ready');
        }
        if ($slot === 'product' && ($asset->is_ai_generated || ! in_array($asset->source_type, ['woocommerce', 'wordpress', 'upload'], true)
            || ! in_array($asset->license, ['wordpress_site', 'owned', 'customer_provided'], true))) {
            throw new MediaRejected('ai_not_product_photo');
        }
        if ($asset->license === 'unknown') {
            throw new MediaRejected('license_unknown');
        }
    }

    /**
     * @param  list<MediaAsset>  $assets
     * @param  list<string>  $tokens
     * @param  array<string, bool>  $used
     */
    private function best(array $assets, array $tokens, array &$used, callable $filter, ?callable $bonus = null, float $min = 0.0): ?MediaAsset
    {
        $best = null;
        $score = $min;
        foreach ($assets as $a) {
            if (isset($used[$a->id]) || $a->status !== 'ready' || $a->license === 'unknown' || ! $filter($a)) {
                continue;
            }
            $s = Text::containment(Text::tokens(($a->title ?? '').' '.($a->alt_text ?? '').' '.($a->caption ?? '').' '.($a->metadata['section_heading'] ?? '')), $tokens)
                + ($bonus !== null ? $bonus($a) : 0.0);
            if ($s > $score) {
                $best = $a;
                $score = $s;
            }
        }
        if ($best !== null) {
            $used[$best->id] = true;
        }

        return $best;
    }

    /**
     * @param  list<string>  $topic
     */
    private function productMatches(MediaAsset $a, array $topic): bool
    {
        return $a->source_type === 'woocommerce' && Text::containment(Text::tokens((string) $a->title), $topic) >= 0.6;
    }

    private function skipSection(string $heading): bool
    {
        $h = Text::fold($heading);

        return str_contains($h, 'cau hoi') || str_contains($h, 'faq') || str_contains($h, 'ket luan') || str_contains($h, 'tong ket') || str_contains($h, 'nguon');
    }

    private function isComparison(string $heading, string $html): bool
    {
        $h = Text::fold($heading);

        return str_contains($html, '<table') || str_contains($h, 'so sanh') || str_contains($h, ' vs ') || str_contains($h, 'khac biet');
    }

    /**
     * @return array{slot: string, section_key: string|null, section_heading: string|null, position: int, media_asset_id: string|null, alt_text: string|null, caption: string|null, reason: string}
     */
    private function row(string $slot, ?string $key, ?string $heading, int $position, ?MediaAsset $a, string $context, string $reason): array
    {
        return [
            'slot' => $slot, 'section_key' => $key, 'section_heading' => $heading !== null ? mb_substr($heading, 0, 300) : null, 'position' => $position,
            'media_asset_id' => $a?->id, 'alt_text' => $a !== null ? AltText::suggest($a, $context) : null,
            'caption' => $a !== null ? AltText::caption($a) : null, 'reason' => $reason,
        ];
    }
}
