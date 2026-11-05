<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Analysis;

/**
 * SEO Opportunity Score v1 — an INTERNAL ranking (0–100), not measured SEO
 * data and not a ranking guarantee. Transparent formula:
 *
 *   base  = Σ points of AVAILABLE components / Σ max of AVAILABLE components × 100
 *   score = clamp(round(base × source_confidence − duplication_penalty), 0, 100)
 *
 * Components (max points):
 *   relevance        25  share of the keyword's topic words found in the campaign vocabulary
 *   intent_value     15  by search intent, adjusted to the campaign goals
 *   commercial_value 10  intent + product/service match (+ verified CPC when a provider gives it)
 *   product_fit      10  topic words matching declared products/services/catalog
 *   content_gap      15  15 = not covered by the site, 7 = partially, 0 = covered
 *   search_console   20  only when Search Console data exists: impressions and "striking distance" position 4–30
 *   market_demand    15  only when a provider gives VERIFIED search volume: log10(volume)/4
 *   difficulty       10  only with provider difficulty: (100 − KD)/10
 * Components without data are "unavailable" and excluded (they never count
 * as zero and are never estimated).
 *   source_confidence ×0.70 AI-only, ×0.80 one source, ×0.90 two, ×1.00 three or more / measured data
 *   duplication_penalty 0–20 when existing content with the same intent already targets it
 */
final class OpportunityScorer
{
    public const VERSION = 'v1';

    /**
     * @param  array{
     *     topic_tokens: list<string>, intent: string, sources: list<string>,
     *     vocabulary: array<string, true>, product_vocabulary: array<string, true>, goals: list<string>,
     *     coverage: string, duplicate_same_intent: bool,
     *     gsc: array{impressions: int, clicks: int, position: float}|null,
     *     provider: array{search_volume: int|null, difficulty: int|null, cpc_micro_usd: int|null}|null
     * }  $in
     * @return array{score: int, version: string, components: list<array{key: string, points: float|null, max: int, available: bool, note: string}>, confidence: float, penalty: int, measured: bool}
     */
    public function score(array $in): array
    {
        $tokens = $in['topic_tokens'];
        $n = max(1, count($tokens));
        $components = [];

        $inVocab = count(array_filter($tokens, fn ($t) => isset($in['vocabulary'][$t])));
        $relevance = 25 * min(1, $inVocab / $n);
        $components[] = ['key' => 'relevance', 'points' => round($relevance, 1), 'max' => 25, 'available' => true, 'note' => "{$inVocab}/{$n} từ chủ đề khớp ngành/sản phẩm"];

        $goals = $in['goals'];
        $intentPoints = match ($in['intent']) {
            'transactional' => 15, 'commercial' => 13, 'informational' => 9, default => 3,
        };
        if (in_array('sales', $goals, true) && in_array($in['intent'], ['transactional', 'commercial'], true)) {
            $intentPoints = 15;
        }
        if (array_intersect(['traffic', 'awareness', 'support'], $goals) !== [] && $in['intent'] === 'informational') {
            $intentPoints = 13;
        }
        $components[] = ['key' => 'intent_value', 'points' => (float) $intentPoints, 'max' => 15, 'available' => true, 'note' => 'Intent: '.$in['intent']];

        $productHits = count(array_filter($tokens, fn ($t) => isset($in['product_vocabulary'][$t])));
        $fit = $productHits === 0 ? 0 : ($productHits >= 2 || $productHits === $n ? 10 : 5);
        $components[] = ['key' => 'product_fit', 'points' => (float) $fit, 'max' => 10, 'available' => true, 'note' => "{$productHits} từ khớp sản phẩm/dịch vụ"];

        $commercial = match ($in['intent']) {
            'transactional' => 8, 'commercial' => 7, 'informational' => 3, default => 1,
        };
        if ($fit > 0) {
            $commercial += 2;
        }
        $cpc = $in['provider']['cpc_micro_usd'] ?? null;
        $note = 'Theo intent và mức khớp sản phẩm';
        if ($cpc !== null) {
            $commercial = min(10, 4 + (int) round(min(6, $cpc / 200_000)));
            $note = 'Theo CPC đo được từ nhà cung cấp';
        }
        $components[] = ['key' => 'commercial_value', 'points' => (float) min(10, $commercial), 'max' => 10, 'available' => true, 'note' => $note];

        $gap = match ($in['coverage']) {
            'none' => 15, 'partial' => 7, default => 0,
        };
        $components[] = ['key' => 'content_gap', 'points' => (float) $gap, 'max' => 15, 'available' => true, 'note' => match ($in['coverage']) {
            'none' => 'Website chưa có bài cho chủ đề này', 'partial' => 'Website mới đề cập một phần', default => 'Website đã có bài phù hợp',
        }];

        $measured = false;
        $gsc = $in['gsc'];
        if ($gsc !== null) {
            $measured = true;
            $imp = max(0, $gsc['impressions']);
            $pts = min(12, 3 * log10(1 + $imp));
            $pos = $gsc['position'];
            if ($pos >= 4 && $pos <= 30) {
                $pts += 8; // striking distance
            } elseif ($pos > 30) {
                $pts += 4;
            }
            $components[] = ['key' => 'search_console', 'points' => round(min(20, $pts), 1), 'max' => 20, 'available' => true,
                'note' => sprintf('%d lượt hiển thị, vị trí TB %.1f (Search Console)', $imp, $pos)];
        } else {
            $components[] = ['key' => 'search_console', 'points' => null, 'max' => 20, 'available' => false, 'note' => 'Chưa có dữ liệu'];
        }

        $volume = $in['provider']['search_volume'] ?? null;
        if ($volume !== null) {
            $measured = true;
            $components[] = ['key' => 'market_demand', 'points' => round(15 * min(1, log10(1 + $volume) / 4), 1), 'max' => 15, 'available' => true, 'note' => "Search volume {$volume}/tháng (nhà cung cấp)"];
        } else {
            $components[] = ['key' => 'market_demand', 'points' => null, 'max' => 15, 'available' => false, 'note' => 'Chưa có dữ liệu'];
        }
        $kd = $in['provider']['difficulty'] ?? null;
        if ($kd !== null) {
            $components[] = ['key' => 'difficulty', 'points' => round((100 - $kd) / 10, 1), 'max' => 10, 'available' => true, 'note' => "Keyword difficulty {$kd}/100 (nhà cung cấp)"];
        } else {
            $components[] = ['key' => 'difficulty', 'points' => null, 'max' => 10, 'available' => false, 'note' => 'Chưa có dữ liệu'];
        }

        $sum = 0.0;
        $max = 0;
        foreach ($components as $c) {
            if ($c['available']) {
                $sum += (float) $c['points'];
                $max += $c['max'];
            }
        }
        $base = $sum / $max * 100; // $max is always > 0 (fixed criteria)

        $real = array_values(array_diff(array_unique($in['sources']), ['ai_expansion']));
        $confidence = match (true) {
            $measured || count($real) >= 3 => 1.0,
            count($real) === 2 => 0.9,
            count($real) === 1 => 0.8,
            default => 0.7,
        };
        $penalty = $in['duplicate_same_intent'] ? 20 : 0;

        return [
            'score' => (int) max(0, min(100, round($base * $confidence - $penalty))),
            'version' => self::VERSION,
            'components' => $components,
            'confidence' => $confidence,
            'penalty' => $penalty,
            'measured' => $measured,
        ];
    }
}
