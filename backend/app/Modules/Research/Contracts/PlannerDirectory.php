<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Contracts;

/**
 * Read-only view of SEO Planner data for other modules (Phase 5 writer).
 * All methods run inside the current tenant context (fail-closed scope).
 */
interface PlannerDirectory
{
    /**
     * @return array{id: string, site_id: string, name: string, industry: string, market: string, language: string, products: list<string>,
     *     services: list<string>, audiences: list<string>, goals: list<string>, brand_terms: list<string>, status: string,
     *     auto_create_content: bool, excluded_topics: list<string>}|null
     */
    public function campaign(string $campaignId): ?array;

    /**
     * @return array{id: string, campaign_id: string, site_id: string, title: string, search_intent: string, content_type: string,
     *     audience: string|null, angle: string|null, rationale: string|null, status: string, primary_keyword: string|null,
     *     secondary_keywords: list<string>, cluster: array{id: string, name: string}|null, outline: list<string>, is_pillar: bool}|null
     */
    public function idea(string $ideaId): ?array;

    /**
     * Ideas that a human approved (status approved / briefed), optionally limited to ids.
     *
     * @param  list<string>|null  $ids
     * @return list<array<string, mixed>>
     */
    public function approvedIdeas(string $campaignId, ?array $ids = null, int $limit = 200): array;

    /**
     * Ideas and clusters of a site, for duplicate / cannibalization checks.
     *
     * @return list<array{id: string, title: string, primary_keyword: string|null, search_intent: string, status: string, cluster: string|null}>
     */
    public function ideasForSite(string $siteId, int $limit = 1000): array;

    /**
     * Website Knowledge Profile items with their provenance.
     *
     * @return list<array{kind: string, value: string, source: string, status: string, confidence: int|null}>
     */
    public function knowledge(string $siteId): array;

    /**
     * Active campaigns that enabled autonomous content production.
     *
     * @return list<array{id: string, site_id: string}>
     */
    public function autonomousCampaigns(): array;
}
