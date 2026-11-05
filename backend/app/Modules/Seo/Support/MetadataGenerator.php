<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Support;

use Illuminate\Support\Str;

/**
 * Deterministic SEO metadata proposal from the article itself (no AI call,
 * no invented claims): title from the article title + focus keyword,
 * description from the first real paragraph, slug from the keyword.
 * Fields a human edited (`manual_fields`) are never overwritten.
 */
final class MetadataGenerator
{
    public const FIELDS = ['seo_title', 'meta_description', 'slug', 'canonical_url', 'focus_keyword', 'secondary_keywords', 'og_title', 'og_description',
        'social_image_asset_id', 'robots_noindex', 'robots_nofollow', 'schema_types'];

    /**
     * @param  array<string, mixed>  $article  ArticleCatalog::find()
     * @param  array<string, mixed>  $current  existing values (incl. manual_fields)
     * @return array<string, mixed>
     */
    public function generate(array $article, string $title, string $html, ?string $featuredAssetId, array $current = []): array
    {
        $kw = trim((string) $article['primary_keyword']);
        $doc = ArticleHtml::parse($html);
        $first = '';
        foreach ($doc->paragraphs as $p) {
            if ($p['words'] >= 12) {
                $first = $p['text'];
                break;
            }
        }
        $proposal = [
            'focus_keyword' => mb_substr($kw, 0, 190),
            'secondary_keywords' => array_values(array_slice(array_filter(array_map(fn ($k) => mb_substr(trim((string) $k), 0, 120), (array) $article['secondary_keywords'])), 0, 10)),
            'seo_title' => self::title($title, $kw),
            'meta_description' => self::description($first !== '' ? $first : $doc->text),
            'slug' => self::slug($kw !== '' ? $kw : $title),
            'canonical_url' => null,
            'robots_noindex' => false,
            'robots_nofollow' => false,
            'social_image_asset_id' => $featuredAssetId,
            'schema_types' => in_array($article['content_type'] ?? '', ['guide', 'how_to', 'howto', 'tutorial'], true) ? ['BlogPosting', 'HowTo', 'FAQPage', 'BreadcrumbList'] : ['BlogPosting', 'FAQPage', 'BreadcrumbList'],
        ];
        $proposal['og_title'] = $proposal['seo_title'];
        $proposal['og_description'] = $proposal['meta_description'];

        $manual = array_flip((array) ($current['manual_fields'] ?? []));
        foreach ($proposal as $field => $value) {
            if (isset($manual[$field]) && array_key_exists($field, $current)) {
                $proposal[$field] = $current[$field];
            }
        }

        return $proposal;
    }

    public static function title(string $title, string $kw): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($kw !== '' && mb_stripos($t, $kw) === false && mb_strlen($kw.': '.$t) <= 60) {
            $t = Str::ucfirst($kw).': '.$t;
        }
        if (mb_strlen($t) <= 60) {
            return $t;
        }
        $cut = mb_substr($t, 0, 60);
        $sp = mb_strrpos($cut, ' ');

        return rtrim($sp !== false && $sp > 30 ? mb_substr($cut, 0, $sp) : $cut, " ,.:;–-");
    }

    public static function description(string $text): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        $t = (string) preg_replace('/\[\d+\]/', '', $t);
        if (mb_strlen($t) <= 155) {
            return $t;
        }
        $cut = mb_substr($t, 0, 152);
        $sp = mb_strrpos($cut, ' ');

        return rtrim($sp !== false && $sp > 80 ? mb_substr($cut, 0, $sp) : $cut, " ,.:;–-").'…';
    }

    public static function slug(string $text): string
    {
        $slug = Str::slug(Str::ascii(str_replace(['đ', 'Đ'], ['d', 'D'], $text)));
        if (strlen($slug) <= 75) {
            return $slug;
        }

        return rtrim(substr($slug, 0, (int) (strrpos(substr($slug, 0, 75), '-') ?: 75)), '-');
    }
}
