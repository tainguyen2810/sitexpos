<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Analysis;

use SiteXPost\Modules\Research\Domain\CompetitorFinding;
use SiteXPost\Modules\Research\Domain\CompetitorWebsite;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\ResearchSource;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\SourceSnapshot;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;
use SiteXPost\Modules\Research\Domain\WebsiteProfile;
use SiteXPost\Modules\Research\Support\KeywordStore;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Deterministic keyword mining from collected data (no AI):
 * - campaign seeds (industry, products, services, scope keywords);
 * - WordPress categories/tags/products and recurring phrases of post titles;
 * - headings and questions of the site's public pages;
 * - competitor page titles, headings, categories and questions.
 * Phrases must touch the campaign vocabulary (stay inside the industry) and
 * recur across documents (document frequency) unless they are explicit
 * entities (category, product, question).
 */
final class KeywordExtractor
{
    public function __construct(private readonly SiteDirectory $sites, private readonly KeywordStore $store) {}

    /**
     * Vocabulary of the campaign (folded topic words).
     *
     * @return array{all: array<string, true>, products: array<string, true>}
     */
    public function vocabulary(SeoCampaign $campaign): array
    {
        $settings = $campaign->settingsOrDefault();
        $seedTexts = array_merge([$campaign->industry], $settings->scope_keywords ?? []);
        $productTexts = array_merge($campaign->products ?? [], $campaign->services ?? []);

        $profile = WebsiteProfile::query()->where('site_id', $campaign->site_id)->first();
        if ($profile !== null) {
            foreach (WebsiteKnowledge::query()->where('website_profile_id', $profile->id)->where('status', '!=', 'rejected')
                ->whereIn('kind', ['industry', 'product_line', 'service', 'covered_topic'])->limit(200)->get() as $k) {
                if (in_array($k->kind, ['product_line', 'service'], true)) {
                    $productTexts[] = $k->value;
                } else {
                    $seedTexts[] = $k->value;
                }
            }
        }
        foreach ($this->sites->content($campaign->site_id, ['product'], 300) as $p) {
            $productTexts[] = $p['title'];
        }
        foreach ($this->sites->taxonomies($campaign->site_id) as $t) {
            if ($t['count'] > 0 && in_array($t['taxonomy'], ['category', 'product_cat'], true)) {
                $seedTexts[] = $t['name'];
            }
        }

        $products = [];
        foreach ($productTexts as $text) {
            foreach (Text::topicTokens((string) $text) as $tok) {
                $products[$tok] = true;
            }
        }
        $all = $products;
        foreach ($seedTexts as $text) {
            foreach (Text::topicTokens((string) $text) as $tok) {
                $all[$tok] = true;
            }
        }
        // generic words that would let anything in
        foreach (['tin', 'tuc', 'blog', 'news', 'san', 'pham', 'dich', 'vu', 'chung', 'uncategorized', 'khac'] as $generic) {
            unset($all[$generic]);
        }

        return ['all' => $all, 'products' => $products];
    }

    /**
     * @return array<string, int> candidates added per source
     */
    public function extract(SeoCampaign $campaign, ResearchRun $run): array
    {
        $vocab = $this->vocabulary($campaign)['all'];
        $settings = $campaign->settingsOrDefault();
        $added = [];
        $add = function (string $keyword, string $source, ?array $evidence = null, ?string $topic = null, bool $requireVocab = true) use ($campaign, $run, $vocab, &$added): void {
            if ($requireVocab && ! $this->touches($keyword, $vocab)) {
                return;
            }
            if ($this->store->upsert($campaign, ['keyword' => $keyword, 'source' => $source, 'evidence' => $evidence, 'topic' => $topic], $run->id) !== null) {
                $added[$source] = ($added[$source] ?? 0) + 1;
            }
        };

        // 1) Seeds declared by the customer
        foreach (array_merge([$campaign->industry], $campaign->products ?? [], $campaign->services ?? [], $settings->scope_keywords ?? []) as $seed) {
            $add((string) $seed, 'campaign', ['type' => 'campaign'], null, false);
        }

        // 2) WordPress taxonomies and products
        foreach ($this->sites->taxonomies($campaign->site_id) as $t) {
            $min = in_array($t['taxonomy'], ['post_tag', 'product_tag'], true) ? 2 : 1;
            if ($t['count'] >= $min) {
                $add($t['name'], in_array($t['taxonomy'], ['product_cat', 'product_tag'], true) ? 'woocommerce' : 'wordpress', ['type' => $t['taxonomy'], 'name' => $t['name'], 'posts' => $t['count']], $t['name']);
            }
        }
        $content = $this->sites->content($campaign->site_id);
        foreach ($content as $item) {
            if ($item['post_type'] === 'product') {
                $words = array_slice(explode(' ', Text::normalize($item['title'])), 0, 6);
                $add(implode(' ', $words), 'woocommerce', ['type' => 'product', 'title' => $item['title'], 'url' => $item['url']]);
            }
        }

        // 3) Recurring phrases: post titles + site headings + competitor titles/headings
        $docs = [];
        foreach ($content as $item) {
            if ($item['post_type'] === 'post') {
                $docs[] = ['text' => $item['title'], 'source' => 'wordpress', 'ref' => ['type' => 'post', 'title' => $item['title'], 'url' => $item['url']]];
            }
        }
        $siteSourceIds = ResearchSource::query()->where('research_run_id', $run->id)->where('type', 'website_crawl')->pluck('id');
        $questions = [];
        foreach (SourceSnapshot::query()->whereIn('research_source_id', $siteSourceIds)->limit(200)->get() as $snap) {
            foreach (($snap->headings ?? []) as $h) {
                if (($h['level'] ?? 3) <= 3) {
                    $docs[] = ['text' => (string) $h['text'], 'source' => 'website_crawl', 'ref' => ['type' => 'heading', 'url' => $snap->url]];
                }
            }
            foreach (($snap->questions ?? []) as $q) {
                $questions[] = [(string) $q, 'website_crawl', ['type' => 'question', 'url' => $snap->url]];
            }
        }
        $competitorIds = CompetitorWebsite::query()->where('campaign_id', $campaign->id)->pluck('id');
        foreach (CompetitorFinding::query()->whereIn('competitor_website_id', $competitorIds)->orderByDesc('fetched_at')->limit(1500)->get() as $f) {
            $ref = ['type' => 'competitor_'.$f->kind, 'title' => $f->title, 'url' => $f->url, 'competitor_id' => $f->competitor_website_id, 'fetched_at' => $f->fetched_at?->toIso8601ZuluString()];
            if ($f->kind === 'question') {
                $questions[] = [$f->title, 'competitor', $ref];

                continue;
            }
            if ($f->kind === 'category') {
                $add($f->title, 'competitor', $ref, $f->title);
            }
            $docs[] = ['text' => $f->title, 'source' => 'competitor', 'ref' => $ref];
            foreach (array_slice($f->headings ?? [], 0, 15) as $h) {
                $docs[] = ['text' => (string) $h, 'source' => 'competitor', 'ref' => $ref];
            }
        }

        $compounds = $this->compounds($campaign, $content, $docs);
        $df = [];
        $firstRef = [];
        foreach ($docs as $i => $doc) {
            foreach (array_unique(Text::ngrams($doc['text'], 2, 5, $compounds)) as $gram) {
                $key = Text::fold($gram);
                $df[$key]['n'] = ($df[$key]['n'] ?? 0) + 1;
                $df[$key]['text'] ??= $gram;
                $df[$key]['sources'][$doc['source']] = true;
                $firstRef[$key] ??= $doc['ref'];
            }
            // whole short titles / headings are natural long-tail queries
            $words = count(Text::tokens($doc['text']));
            if ($words >= 3 && $words <= 9 && $doc['source'] !== 'wordpress') {
                $add($doc['text'], $doc['source'], $doc['ref']);
            }
        }
        uasort($df, fn ($a, $b) => [$b['n'], mb_strlen($b['text'])] <=> [$a['n'], mb_strlen($a['text'])]);
        $kept = 0;
        foreach ($df as $key => $g) {
            if ($g['n'] < 2 || $kept >= 200) {
                continue;
            }
            $source = isset($g['sources']['competitor']) && count($g['sources']) === 1 ? 'competitor' : (isset($g['sources']['website_crawl']) ? 'website_crawl' : array_key_first($g['sources']));
            $add($g['text'], (string) $source, ['type' => 'phrase', 'documents' => $g['n']] + ($firstRef[$key] ?? []));
            $kept++;
        }

        foreach (array_slice($questions, 0, 150) as [$q, $source, $ref]) {
            $add(rtrim($q, '?'), $source, $ref);
        }

        return $added;
    }

    /**
     * Two-syllable words of this business: from the customer's own terms
     * (industry, products, audiences, categories, product names) plus pairs
     * that are strongly associated across the collected documents.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  list<array{text: string}>  $docs
     * @return array<string, true>
     */
    private function compounds(SeoCampaign $campaign, array $content, array $docs): array
    {
        $settings = $campaign->settingsOrDefault();
        $anchors = array_merge([$campaign->industry], $campaign->products ?? [], $campaign->services ?? [], $campaign->audiences ?? [], $settings->scope_keywords ?? []);
        foreach ($this->sites->taxonomies($campaign->site_id) as $t) {
            $anchors[] = $t['name'];
        }
        foreach ($content as $c) {
            if ($c['post_type'] === 'product') {
                $anchors[] = $c['title'];
            }
        }
        $out = Text::compoundsFrom($anchors);

        // Corpus association: pair seen in ≥2 documents and in most uses of its rarer word.
        $pairDf = [];
        $wordDf = [];
        foreach ($docs as $doc) {
            $w = array_values(array_filter(explode(' ', Text::fold($doc['text'])), static fn ($x) => $x !== ''));
            foreach (array_unique($w) as $x) {
                $wordDf[$x] = ($wordDf[$x] ?? 0) + 1;
            }
            $pairs = [];
            for ($i = 0; $i + 1 < count($w); $i++) {
                $pairs[$w[$i].' '.$w[$i + 1]] = [$w[$i], $w[$i + 1]];
            }
            foreach ($pairs as $k => $ab) {
                $pairDf[$k]['n'] = ($pairDf[$k]['n'] ?? 0) + 1;
                $pairDf[$k]['ab'] = $ab;
            }
        }
        foreach ($pairDf as $k => $p) {
            [$a, $b] = $p['ab'];
            if ($p['n'] >= 2 && ! ctype_digit($a) && ! ctype_digit($b) && $p['n'] / max(1, min($wordDf[$a] ?? 1, $wordDf[$b] ?? 1)) >= 0.6) {
                $out[$k] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $vocab
     */
    public function touches(string $keyword, array $vocab): bool
    {
        foreach (Text::topicTokens($keyword) as $t) {
            if (isset($vocab[$t])) {
                return true;
            }
        }

        return false;
    }
}
