<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Support;

use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;
use SiteXPost\Modules\Research\Domain\WebsiteProfile;

/**
 * Read-only SEO Planner facade for the Phase 5 writer (no writes into
 * Research tables from other modules).
 */
final class PlannerDirectoryService implements PlannerDirectory
{
    public function __construct(private readonly TenantContext $context) {}

    public function campaign(string $campaignId): ?array
    {
        $c = SeoCampaign::query()->with('settings')->find($campaignId);
        if ($c === null) {
            return null;
        }
        $s = $c->settingsOrDefault();

        return [
            'id' => $c->id, 'site_id' => $c->site_id, 'name' => $c->name, 'industry' => $c->industry, 'market' => $c->market, 'language' => $c->language,
            'products' => array_values($c->products ?? []), 'services' => array_values($c->services ?? []), 'audiences' => array_values($c->audiences ?? []),
            'goals' => array_values($c->goals ?? []), 'brand_terms' => array_values($c->brand_terms ?? []), 'status' => $c->status,
            'auto_create_content' => (bool) $s->auto_create_content, 'excluded_topics' => array_values($s->excluded_topics ?? []),
        ];
    }

    public function idea(string $ideaId): ?array
    {
        $idea = ContentIdea::query()->with(['primaryKeyword:id,keyword', 'keywords:id,keyword', 'cluster:id,name'])->find($ideaId);

        return $idea === null ? null : $this->shape($idea);
    }

    public function approvedIdeas(string $campaignId, ?array $ids = null, int $limit = 200): array
    {
        $q = ContentIdea::query()->with(['primaryKeyword:id,keyword', 'keywords:id,keyword', 'cluster:id,name'])
            ->where('campaign_id', $campaignId)->whereIn('status', ['approved', 'briefed']);
        if ($ids !== null) {
            $q->whereIn('id', $ids);
        }

        return $q->orderByDesc('opportunity_score')->orderBy('id')->limit($limit)->get()->map(fn (ContentIdea $i): array => $this->shape($i))->all();
    }

    public function ideasForSite(string $siteId, int $limit = 1000): array
    {
        $campaigns = SeoCampaign::query()->where('site_id', $siteId)->pluck('id');

        return ContentIdea::query()->with(['primaryKeyword:id,keyword', 'cluster:id,name'])->whereIn('campaign_id', $campaigns)
            ->where('status', '!=', 'rejected')->limit($limit)->get()
            ->map(fn (ContentIdea $i): array => [
                'id' => $i->id, 'title' => $i->title, 'primary_keyword' => $i->primaryKeyword?->keyword, 'search_intent' => $i->search_intent,
                'status' => $i->status, 'cluster' => $i->cluster?->name,
            ])->all();
    }

    public function knowledge(string $siteId): array
    {
        $profile = WebsiteProfile::query()->where('site_id', $siteId)->first();
        if ($profile === null) {
            return [];
        }

        return WebsiteKnowledge::query()->where('website_profile_id', $profile->id)->where('status', '!=', 'rejected')->orderBy('kind')->limit(200)->get()
            ->map(fn (WebsiteKnowledge $k): array => ['kind' => $k->kind, 'value' => $k->value, 'source' => $k->source, 'status' => $k->status, 'confidence' => $k->confidence])
            ->all();
    }

    public function autonomousCampaigns(): array
    {
        return SeoCampaign::query()->where('status', 'active')
            ->whereHas('settings', fn ($q) => $q->where('auto_create_content', true))
            ->get(['id', 'site_id'])->map(fn (SeoCampaign $c): array => ['id' => $c->id, 'site_id' => $c->site_id])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(ContentIdea $i): array
    {
        $primary = $i->primaryKeyword?->keyword;
        $secondary = $i->keywords->pluck('keyword')->reject(fn ($k) => $k === $primary)->values()->all();
        $outline = $i->brief['outline'] ?? ($i->evidence['outline'] ?? []);

        return [
            'id' => $i->id, 'campaign_id' => $i->campaign_id, 'site_id' => (string) SeoCampaign::query()->whereKey($i->campaign_id)->value('site_id'),
            'title' => $i->title, 'search_intent' => $i->search_intent, 'content_type' => $i->content_type, 'audience' => $i->audience,
            'angle' => $i->angle, 'rationale' => $i->rationale, 'status' => $i->status, 'primary_keyword' => $primary,
            'secondary_keywords' => array_values(array_slice($secondary, 0, 15)),
            'cluster' => $i->cluster !== null ? ['id' => $i->cluster->id, 'name' => $i->cluster->name] : null,
            'outline' => array_values(array_filter(is_array($outline) ? $outline : [], 'is_string')),
            'is_pillar' => (bool) $i->is_pillar,
        ];
    }
}
