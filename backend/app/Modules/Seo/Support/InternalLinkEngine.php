<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Support;

use SiteXPost\Modules\Research\Support\Text;

/**
 * Internal link suggestions from the site's real content index (Phase 2
 * sync) and the Phase 4 planner clusters. Rules:
 * - only published items with a known URL (never invent URLs);
 * - the anchor is a phrase that already exists in the paragraph (no text
 *   is rewritten), 2–6 words, every word also in the target title, never
 *   just the article's own focus keyword (no cannibalizing anchors);
 * - at most one link per paragraph, one per target, five per article;
 * - never in the first paragraph, FAQ, sources, short paragraphs or
 *   paragraphs that already contain a link.
 * Ranking = semantic overlap + same cluster + compatible intent + anchor
 * quality; every signal is stored for the UI to explain.
 */
final class InternalLinkEngine
{
    public const MAX_PER_ARTICLE = 5;

    /**
     * @param  array<string, mixed>  $article  ArticleCatalog::find()
     * @param  list<array<string, mixed>>  $inventory  SiteDirectory::contentIndex()
     * @param  list<array<string, mixed>>  $ideas  PlannerDirectory::ideasForSite()
     * @return list<array<string, mixed>>
     */
    public function suggest(array $article, ArticleHtml $html, array $inventory, array $ideas, ?string $articleCluster, ?int $ownWpPostId): array
    {
        $topic = Text::tokens($article['title'].' '.$article['primary_keyword'].' '.implode(' ', (array) $article['secondary_keywords']).' '
            .implode(' ', array_column(array_filter($html->headings, fn ($h) => $h['level'] === 2), 'text')));
        $own = Text::tokens((string) $article['primary_keyword']);
        $intent = (string) ($article['search_intent'] ?? 'informational');
        $commercial = in_array($intent, ['commercial', 'commercial_investigation', 'transactional'], true);

        $paragraphs = array_values(array_filter($html->paragraphs, fn ($p) => $p['index'] > 0 && ! $p['has_link'] && $p['words'] >= 12 && ! self::excludedSection($p['section'])));

        $candidates = [];
        foreach ($inventory as $item) {
            if (($item['status'] ?? '') !== 'publish' || empty($item['url']) || (int) $item['wp_post_id'] === (int) $ownWpPostId) {
                continue;
            }
            if (($item['post_type'] ?? '') === 'product' && ! $commercial) {
                continue;
            }
            $tTokens = Text::tokens((string) $item['title']);
            if (count($tTokens) < 2) {
                continue;
            }
            $semantic = Text::similarity($topic, $tTokens);
            if ($semantic < 0.12 && Text::containment($tTokens, $topic) < 0.5) {
                continue;
            }
            $cluster = null;
            $bestIdea = 0.0;
            foreach ($ideas as $idea) {
                $s = Text::similarity($tTokens, Text::tokens((string) $idea['title']));
                if ($s > $bestIdea && $s >= 0.7) {
                    $bestIdea = $s;
                    $cluster = $idea['cluster'] ?? null;
                }
            }
            $sameCluster = $articleCluster !== null && $cluster !== null && $cluster === $articleCluster;
            $candidates[] = ['item' => $item, 'tokens' => $tTokens, 'semantic' => $semantic, 'same_cluster' => $sameCluster];
        }

        $out = [];
        $usedParagraphs = [];
        foreach ($candidates as $c) {
            $best = null;
            foreach ($paragraphs as $p) {
                $anchor = self::anchor($p['text'], $c['tokens'], $own);
                if ($anchor === null) {
                    continue;
                }
                $quality = min(1.0, count(Text::tokens($anchor)) / max(2, min(4, count($c['tokens']))));
                if ($best === null || $quality > $best['quality']) {
                    $best = ['paragraph' => $p, 'anchor' => $anchor, 'quality' => $quality];
                }
            }
            if ($best === null) {
                continue;
            }
            $intentFit = ($c['item']['post_type'] ?? '') === 'product' ? ($commercial ? 1.0 : 0.0) : 1.0;
            $score = (int) round(100 * (0.55 * min(1.0, $c['semantic'] * 2) + 0.2 * ($c['same_cluster'] ? 1 : 0) + 0.1 * $intentFit + 0.15 * $best['quality']));
            if ($score < 30) {
                continue;
            }
            $reason = 'Chủ đề liên quan ('.(int) round($c['semantic'] * 100).'% từ khóa trùng)'.($c['same_cluster'] ? ', cùng cụm chủ đề' : '')
                .'; anchor "'.$best['anchor'].'" có sẵn trong đoạn '.($best['paragraph']['index'] + 1).'.';
            $out[] = [
                'target_wp_post_id' => (int) $c['item']['wp_post_id'], 'target_post_type' => (string) $c['item']['post_type'],
                'target_url' => (string) $c['item']['url'], 'target_title' => mb_substr((string) $c['item']['title'], 0, 500),
                'anchor_text' => mb_substr($best['anchor'], 0, 190), 'section_heading' => $best['paragraph']['section'],
                'paragraph_index' => $best['paragraph']['index'], 'relevance' => min(100, $score),
                'signals' => ['semantic' => round($c['semantic'], 3), 'same_cluster' => $c['same_cluster'], 'intent_fit' => $intentFit, 'anchor_quality' => round($best['quality'], 2)],
                'reason' => mb_substr($reason, 0, 500),
            ];
        }
        usort($out, fn ($a, $b) => $b['relevance'] <=> $a['relevance']);
        $final = [];
        foreach ($out as $s) {
            if (count($final) >= self::MAX_PER_ARTICLE) {
                break;
            }
            if (isset($usedParagraphs[$s['paragraph_index']])) {
                continue;
            }
            $usedParagraphs[$s['paragraph_index']] = true;
            $final[] = $s;
        }

        return $final;
    }

    public static function excludedSection(string $heading): bool
    {
        $h = mb_strtolower($heading);
        foreach (['faq', 'câu hỏi thường gặp', 'hỏi đáp', 'nguồn', 'tham khảo', 'kết luận', 'tổng kết'] as $w) {
            if (str_contains($h, $w)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Longest 2–6 word phrase of the paragraph whose words all occur in the
     * target title (original casing kept so it can be linked in place).
     *
     * @param  list<string>  $targetTokens
     * @param  list<string>  $avoid  tokens of the article's focus keyword
     */
    public static function anchor(string $paragraph, array $targetTokens, array $avoid = []): ?string
    {
        $words = preg_split('/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $target = array_flip($targetTokens);
        $best = null;
        $bestLen = 0;
        $n = count($words);
        for ($i = 0; $i < $n; $i++) {
            for ($len = min(6, $n - $i); $len >= 2; $len--) {
                $phrase = trim(implode(' ', array_slice($words, $i, $len)), " \t,.;:!?()\"'“”‘’…-–");
                if ($phrase === '' || preg_match('/[,.;:!?()]/u', $phrase) === 1) {
                    continue;
                }
                $parts = preg_split('/\s+/u', $phrase) ?: [];
                if (Text::tokens((string) reset($parts)) === [] || Text::tokens((string) end($parts)) === []) {
                    continue; // no anchor starting / ending with a function word
                }
                $tokens = Text::tokens($phrase);
                if (count($tokens) < 2 || count(array_diff($tokens, array_keys($target))) > 0) {
                    continue;
                }
                if ($avoid !== [] && count(array_diff($tokens, $avoid)) === 0) {
                    continue; // never link the article's own focus keyword to another page
                }
                if (count($tokens) > $bestLen) {
                    $best = $phrase;
                    $bestLen = count($tokens);
                }
                break;
            }
        }

        return $best;
    }
}
