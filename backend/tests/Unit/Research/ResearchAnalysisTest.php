<?php

use SiteXPost\Modules\Research\Analysis\IdeaPlanner;
use SiteXPost\Modules\Research\Analysis\IntentClassifier;
use SiteXPost\Modules\Research\Analysis\OpportunityScorer;
use SiteXPost\Modules\Research\Crawler\RobotsPolicy;
use SiteXPost\Modules\Research\Support\Text;

it('folds Vietnamese text for matching but keeps meaningful words', function () {
    expect(Text::fold('Tai nghe Chống Ồn Wi-Fi'))->toBe('tai nghe chong on wifi')
        ->and(Text::tokens('Cách chọn bàn phím cơ cho sinh viên'))->toContain('ban', 'phim', 'sinh', 'vien')
        ->and(Text::topicTokens('Cách chọn bàn phím cơ giá rẻ'))->not->toContain('cach', 'gia', 're')
        // accent-aware: "cơ", "dây", "ổ", "ví", "cáp" are words here, "có", "đây", "ở" are not
        ->and(Text::tokens('bàn phím cơ không dây'))->toBe(['ban', 'phim', 'co', 'day'])
        ->and(Text::tokens('ổ cứng ssd có tốt không'))->toBe(['o', 'cung', 'ssd', 'tot'])
        ->and(Text::topicTokens('cáp sạc nhanh giá rẻ'))->toContain('cap', 'sac')
        ->and(Text::tokens('ban phim co khong day'))->toBe(['ban', 'phim']); // unaccented input: ambiguous words are treated as function words
});

it('never cuts Vietnamese compound words when mining phrases', function () {
    $grams = Text::ngrams('Top 5 tai nghe chống ồn cho sinh viên', 2, 4);

    expect($grams)->toContain('tai nghe', 'tai nghe chống ồn')
        ->not->toContain('5 tai', 'nghe chống', 'ồn cho sinh')
        ->and(Text::ngrams('vệ sinh bàn phím cơ', 2, 3, Text::compoundsFrom(['bàn phím cơ'])))->not->toContain('sinh bàn');
});

it('classifies search intent and content format by rules', function () {
    $c = new IntentClassifier;

    expect($c->classify('so sánh tai nghe sony và bose'))->toMatchArray(['intent' => 'commercial', 'content_type' => 'comparison'])
        ->and($c->classify('cách vệ sinh bàn phím cơ'))->toMatchArray(['intent' => 'informational', 'content_type' => 'tutorial'])
        ->and($c->classify('laptop không nhận wifi'))->toMatchArray(['content_type' => 'troubleshooting'])
        ->and($c->classify('mua tai nghe chính hãng'))->toMatchArray(['intent' => 'transactional'])
        ->and($c->classify('đánh giá tai nghe sony'))->toMatchArray(['intent' => 'commercial'])
        ->and($c->classify('techshop đăng nhập', ['TechShop']))->toMatchArray(['intent' => 'navigational'])
        ->and($c->classify('tai nghe'))->toMatchArray(['intent' => 'informational', 'confidence' => 40]);
});

it('keeps different intents of the same topic as different ideas but merges word-order variants', function () {
    expect(IdeaPlanner::fingerprint('informational', 'tai nghe chống ồn'))->toBe(IdeaPlanner::fingerprint('informational', 'chống ồn tai nghe'))
        ->and(IdeaPlanner::fingerprint('informational', 'tai nghe chống ồn'))->not->toBe(IdeaPlanner::fingerprint('commercial', 'tai nghe chống ồn'));
});

it('scores only on available data and labels the score an internal estimate', function () {
    $s = new OpportunityScorer;
    $base = ['topic_tokens' => ['tai', 'nghe'], 'intent' => 'commercial', 'sources' => ['wordpress'], 'vocabulary' => ['tai' => true, 'nghe' => true],
        'product_vocabulary' => ['tai' => true, 'nghe' => true], 'goals' => ['sales'], 'coverage' => 'none', 'duplicate_same_intent' => false, 'gsc' => null, 'provider' => null];

    $noData = $s->score($base);
    $unavailable = collect($noData['components'])->where('available', false)->pluck('key')->all();
    expect($unavailable)->toBe(['search_console', 'market_demand', 'difficulty'])
        ->and(collect($noData['components'])->where('available', false)->pluck('points')->filter()->all())->toBe([])
        ->and($noData['measured'])->toBeFalse()
        ->and($noData['confidence'])->toBe(0.8);

    $dup = $s->score(['duplicate_same_intent' => true] + $base);
    expect($dup['score'])->toBe($noData['score'] - 20);

    $measured = $s->score(['provider' => ['search_volume' => 10000, 'difficulty' => 20, 'cpc_micro_usd' => null]] + $base);
    expect($measured['measured'])->toBeTrue()->and(collect($measured['components'])->firstWhere('key', 'market_demand')['available'])->toBeTrue();
});

it('follows RFC 9309 robots rules (longest match, allow wins ties)', function () {
    $p = RobotsPolicy::parse("User-agent: *\nDisallow: /private/\nAllow: /private/public-page\nDisallow: /*.pdf$\nCrawl-delay: 3\nSitemap: https://x.example/sitemap.xml\n");

    expect(RobotsPolicy::evaluate($p['rules'], '/'))->toBeTrue()
        ->and(RobotsPolicy::evaluate($p['rules'], '/private/secret'))->toBeFalse()
        ->and(RobotsPolicy::evaluate($p['rules'], '/private/public-page'))->toBeTrue()
        ->and(RobotsPolicy::evaluate($p['rules'], '/files/a.pdf'))->toBeFalse()
        ->and($p['delay_ms'])->toBe(3000)
        ->and($p['sitemaps'])->toBe(['https://x.example/sitemap.xml']);
});

it('writes idea titles from the keyword without inventing facts', function () {
    expect(IdeaPlanner::ruleTitle('laptop nào tốt cho lập trình', 'informational', 'vi'))->toBe('Laptop nào tốt cho lập trình?')
        ->and(IdeaPlanner::ruleTitle('cách vệ sinh bàn phím cơ', 'tutorial', 'vi'))->toBe('Cách vệ sinh bàn phím cơ')
        ->and(IdeaPlanner::ruleTitle('tai nghe sony', 'commercial', 'vi'))->toContain('Tai nghe sony')
        ->and(IdeaPlanner::ruleTitle('tai nghe sony', 'commercial', 'vi'))->not->toMatch('/\d{2,}/');
});
