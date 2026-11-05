<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;

/**
 * Duplicate & cannibalization check against the WordPress index, SEO
 * Planner ideas / clusters and the workspace's other articles (drafts and
 * approved). It only RECOMMENDS create_new / update_existing / merge /
 * reject — nothing is ever written to WordPress.
 */
class DuplicateDetector
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly PlannerDirectory $planner,
    ) {}

    /**
     * @return array{matches: list<array<string, mixed>>, recommendation: string, reason: string, checked_at: string}
     */
    public function check(Article $a, ?string $html = null): array
    {
        $title = Text::tokens($a->title);
        $kw = Text::tokens($a->primary_keyword);
        $shingles = $html !== null ? self::shingles(HtmlSanitizer::text($html)) : [];
        $matches = [];

        foreach ($this->sites->content($a->site_id, ['post', 'page', 'product'], 2000) as $item) {
            $t = Text::tokens((string) $item['title']);
            $sim = Text::similarity($title, $t);
            $kwIn = $kw !== [] ? Text::containment($kw, $t) : 0.0;
            $content = $shingles !== [] && ! empty($item['excerpt']) ? self::jaccard($shingles, self::shingles((string) $item['excerpt'])) : 0.0;
            if ($sim >= 0.5 || ($kwIn >= 0.99 && $sim >= 0.35) || $content >= 0.3) {
                $matches[] = ['type' => 'wp_'.$item['post_type'], 'id' => $item['id'], 'title' => $item['title'], 'url' => $item['url'] ?? null,
                    'title_similarity' => round($sim, 2), 'content_similarity' => round($content, 2), 'same_keyword' => $kwIn >= 0.99,
                    'risk' => $sim >= 0.85 ? 'duplicate_title' : ($content >= 0.3 ? 'near_duplicate' : 'cannibalization')];
            }
        }

        foreach ($this->planner->ideasForSite($a->site_id) as $idea) {
            if ($idea['id'] === $a->content_idea_id) {
                continue;
            }
            $sim = Text::similarity($title, Text::tokens($idea['title']));
            $sameKw = $idea['primary_keyword'] !== null && Text::fold($idea['primary_keyword']) === Text::fold($a->primary_keyword);
            if ($sim >= 0.6 || ($sameKw && $idea['search_intent'] === $a->search_intent)) {
                $matches[] = ['type' => 'idea', 'id' => $idea['id'], 'title' => $idea['title'], 'url' => null, 'title_similarity' => round($sim, 2),
                    'content_similarity' => 0.0, 'same_keyword' => $sameKw, 'cluster' => $idea['cluster'],
                    'risk' => $sameKw && $idea['search_intent'] === $a->search_intent ? 'duplicate_intent' : 'similar_title'];
            }
        }

        $others = Article::query()->where('site_id', $a->site_id)->where('id', '!=', $a->id)->where('status', '!=', 'CANCELLED')
            ->latest()->limit(200)->get(['id', 'title', 'primary_keyword', 'search_intent', 'status', 'current_version_id']);
        $versions = ArticleVersion::query()->whereIn('id', $others->pluck('current_version_id')->filter()->take(50))->get(['id', 'content_html'])->keyBy('id');
        foreach ($others as $o) {
            $sim = Text::similarity($title, Text::tokens($o->title));
            $sameKw = Text::fold($o->primary_keyword) === Text::fold($a->primary_keyword);
            $v = $o->current_version_id !== null ? $versions->get($o->current_version_id) : null;
            $content = $shingles !== [] && $v !== null ? self::jaccard($shingles, self::shingles(HtmlSanitizer::text($v->content_html))) : 0.0;
            if ($sim >= 0.6 || ($sameKw && $o->search_intent === $a->search_intent) || $content >= 0.3) {
                $matches[] = ['type' => 'article', 'id' => $o->id, 'title' => $o->title, 'url' => null, 'status' => $o->status->value,
                    'title_similarity' => round($sim, 2), 'content_similarity' => round($content, 2), 'same_keyword' => $sameKw,
                    'risk' => $content >= 0.3 ? 'near_duplicate' : ($sameKw && $o->search_intent === $a->search_intent ? 'duplicate_intent' : 'similar_title')];
            }
        }

        usort($matches, static fn ($x, $y) => max($y['title_similarity'], $y['content_similarity']) <=> max($x['title_similarity'], $x['content_similarity']));
        [$rec, $reason] = self::recommend($matches);

        return ['matches' => array_slice($matches, 0, 20), 'recommendation' => $rec, 'reason' => $reason, 'checked_at' => now()->toIso8601ZuluString()];
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return array{0: string, 1: string}
     */
    public static function recommend(array $matches): array
    {
        foreach ($matches as $m) {
            if ($m['type'] === 'article' && in_array($m['status'] ?? '', ['APPROVED'], true) && ($m['title_similarity'] >= 0.9 || $m['content_similarity'] >= 0.6)) {
                return ['reject', 'Đã có bài được duyệt gần như trùng: '.$m['title']];
            }
        }
        foreach ($matches as $m) {
            if (str_starts_with($m['type'], 'wp_') && in_array($m['risk'], ['duplicate_title', 'near_duplicate'], true)) {
                return ['update_existing', 'Website đã có bài tương tự — nên cập nhật bài cũ: '.$m['title']];
            }
        }
        foreach ($matches as $m) {
            if ($m['type'] === 'article' && in_array($m['risk'], ['duplicate_intent', 'near_duplicate'], true)) {
                return ['merge', 'Một bài khác đang soạn cùng từ khóa/ý định: '.$m['title']];
            }
        }
        foreach ($matches as $m) {
            if (str_starts_with($m['type'], 'wp_') && $m['risk'] === 'cannibalization') {
                return ['create_new', 'Có bài liên quan trên website — tạo bài mới nhưng phân biệt góc nhìn và liên kết nội bộ tới: '.$m['title']];
            }
        }

        return ['create_new', $matches === [] ? 'Không phát hiện trùng lặp.' : 'Chỉ có tiêu đề tương tự ở mức thấp.'];
    }

    /**
     * @return array<string, true>
     */
    public static function shingles(string $text, int $n = 5): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text)))) ?: [];
        $out = [];
        for ($i = 0; $i + $n <= count($words); $i++) {
            $out[implode(' ', array_slice($words, $i, $n))] = true;
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));

        return $inter / max(1, count($a) + count($b) - $inter);
    }
}
