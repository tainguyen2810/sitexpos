<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\WritingPreset;

/**
 * Builds the (data-only) context blocks sent to the model. Knowledge and
 * preset facts keep their provenance label so the model — and the editor —
 * can tell user-provided facts from website-extracted, source-verified or
 * AI-inferred ones. Nothing here is ever placed in the system prompt.
 */
class ArticleContext
{
    public const PROVENANCE = ['user_provided', 'website_extracted', 'source_verified', 'ai_inferred'];

    public function __construct(
        private readonly PlannerDirectory $planner,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function base(Article $a): array
    {
        $campaign = $a->campaign_id !== null ? $this->planner->campaign($a->campaign_id) : null;

        return [
            'title' => $a->title,
            'primary_keyword' => $a->primary_keyword,
            'secondary_keywords' => $a->secondary_keywords ?? [],
            'search_intent' => $a->search_intent,
            'content_type' => $a->content_type,
            'audience' => $a->setting('audience'),
            'tone' => $a->setting('tone'),
            'language' => $a->language,
            'country' => $a->country,
            'target_words' => (int) $a->setting('target_words', 1500),
            'brand_rules' => $a->setting('brand_rules', []),
            'notes' => $a->setting('notes'),
            'business' => $campaign !== null ? array_intersect_key($campaign, array_flip(['industry', 'products', 'services', 'audiences', 'goals', 'brand_terms'])) : null,
            'brand_voice' => $this->brandVoice($a),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function brandVoice(Article $a): ?array
    {
        $preset = $a->writing_preset_id !== null ? WritingPreset::query()->find($a->writing_preset_id) : null;
        $knowledge = array_map(static fn (array $k): array => [
            'kind' => $k['kind'], 'value' => $k['value'],
            'provenance' => match (true) {
                $k['source'] === 'user' || $k['status'] === 'confirmed' => 'user_provided',
                $k['source'] === 'website' => 'website_extracted',
                default => 'ai_inferred',
            },
        ], array_slice($this->planner->knowledge($a->site_id), 0, 40));

        if ($preset === null && $knowledge === []) {
            return null;
        }

        return [
            'tone' => $preset?->tone,
            'writing_style' => $preset?->writing_style,
            'preferred_terms' => $preset->preferred_terms ?? [],
            'forbidden_phrases' => $preset->forbidden_phrases ?? [],
            'facts' => array_map(static fn (array $f): array => ['text' => $f['text'] ?? '', 'kind' => $f['kind'] ?? 'fact', 'provenance' => $f['provenance'] ?? 'user_provided'],
                array_slice($preset->facts ?? [], 0, 40)),
            'target_customers' => $preset->target_customers ?? [],
            'geographic_context' => $preset?->geographic_context,
            'editorial_guidelines' => $preset?->editorial_guidelines !== null ? mb_substr($preset->editorial_guidelines, 0, 2000) : null,
            'cta' => $preset?->cta,
            'website_knowledge' => $knowledge,
            // Learned + reviewed style guide: HOW to write (never facts, never text to copy).
            'style_profile' => StyleLearner::isUsable($preset?->style_profile) ? array_intersect_key((array) $preset?->style_profile,
                array_flip([...StyleLearner::TEXT_FIELDS, ...StyleLearner::LIST_FIELDS])) : null,
            'provenance_rule' => 'Chỉ "source_verified" mới được coi là đã kiểm chứng; "user_provided", "website_extracted", "ai_inferred" là thông tin cần thận trọng và không thay thế bằng chứng.',
        ];
    }

    /**
     * Lowercase tokens used to recognise the manufacturer's own domain.
     *
     * @return list<string>
     */
    public function brandTokens(Article $a): array
    {
        $parts = [$a->primary_keyword, $a->title, ...($a->secondary_keywords ?? [])];
        if ($a->campaign_id !== null && ($c = $this->planner->campaign($a->campaign_id)) !== null) {
            $parts = [...$parts, ...$c['brand_terms'], ...$c['products']];
        }
        foreach ((array) $a->setting('brand_domains', []) as $d) {
            $parts[] = (string) $d;
        }
        $tokens = [];
        foreach ($parts as $p) {
            foreach (preg_split('/[^a-z0-9]+/', Text::fold((string) $p)) ?: [] as $t) {
                if (strlen($t) >= 3 && ! ctype_digit($t)) {
                    $tokens[$t] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    /**
     * Internal link candidates: existing site content ranked by overlap with
     * the topic. These are the ONLY URLs the AI may link to.
     *
     * @return list<array{url: string, title: string, type: string}>
     */
    public function linkCandidates(Article $a, int $limit = 12): array
    {
        $topic = Text::tokens($a->primary_keyword.' '.$a->title.' '.implode(' ', $a->secondary_keywords ?? []));
        $scored = [];
        foreach ($this->sites->content($a->site_id, ['post', 'page', 'product'], 2000) as $item) {
            $url = $item['url'] ?? null;
            if (! is_string($url) || $url === '' || ($item['status'] ?? 'publish') !== 'publish') {
                continue;
            }
            $score = Text::similarity($topic, Text::tokens((string) ($item['title'] ?? '')));
            if ($score > 0.05) {
                $scored[] = ['url' => $url, 'title' => (string) $item['title'], 'type' => (string) ($item['post_type'] ?? 'post'), 'score' => $score];
            }
        }
        usort($scored, static fn ($x, $y) => $y['score'] <=> $x['score']);

        return array_map(static fn (array $s): array => ['url' => $s['url'], 'title' => $s['title'], 'type' => $s['type']], array_slice($scored, 0, $limit));
    }

    /**
     * Evidence list for prompts (ref, statement, excerpt, source).
     *
     * @param  list<string>|null  $refs
     * @return list<array<string, mixed>>
     */
    public function evidence(string $dossierId, ?array $refs = null, int $limit = 40, int $excerptChars = 400): array
    {
        $q = EvidenceItem::query()->with('source:id,url,final_url,title,publisher,source_type,reliability_score,published_at,modified_at')
            ->where('research_dossier_id', $dossierId)->orderByDesc('relevance')->orderBy('ref');
        if ($refs !== null) {
            $q->whereIn('ref', $refs === [] ? [-1] : $refs);
        }

        return $q->limit($limit)->get()->map(static fn (EvidenceItem $e): array => [
            'ref' => $e->ref,
            'statement' => $e->statement,
            'excerpt' => mb_substr($e->excerpt, 0, $excerptChars),
            'date_context' => $e->date_context,
            'version_context' => $e->version_context,
            'source' => ['url' => $e->source->final_url ?? $e->source?->url, 'title' => $e->source?->title, 'publisher' => $e->source?->publisher,
                'type' => $e->source?->source_type, 'reliability' => $e->source?->reliability_score,
                'date' => ($e->source->modified_at ?? $e->source?->published_at)?->toDateString()],
        ])->all();
    }

    public function approvedBrief(Article $a): ?ContentBrief
    {
        return ContentBrief::query()->where('article_id', $a->id)->where('status', 'approved')->orderByDesc('version')->first();
    }
}
