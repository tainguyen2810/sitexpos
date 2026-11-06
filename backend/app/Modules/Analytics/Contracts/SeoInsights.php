<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Contracts;

/**
 * The only door from the SEO Agent into Analytics. Reads are tenant-scoped
 * summaries; the writes create proposals or start the controlled refresh
 * pipeline (which always stops at a human approval).
 */
interface SeoInsights
{
    /**
     * Recompute snapshots, decay, opportunities, recommendations and health
     * from stored metrics (no external call).
     *
     * @return array<string, mixed>
     */
    public function analyze(string $siteId): array;

    /**
     * @return array{data_latest: ?string, health: ?int, open_opportunities: int, decay_open: int,
     *     opportunities: list<array<string, mixed>>, recommendations: list<array<string, mixed>>, awaiting_publish: list<array<string, mixed>>}
     */
    public function observations(string $siteId, int $limit = 20): array;

    /**
     * Agent proposal (status "proposed", source "agent"). Idempotent per fingerprint.
     *
     * @param  array{type: string, target_label: string, rationale: string, evidence: array<string, mixed>, article_id?: ?string, opportunity_id?: ?string}  $data
     */
    public function propose(string $siteId, array $data, string $agentRunId): string;

    /**
     * @return array{id: string, status: string}
     */
    public function startRefresh(string $articleId, ?string $recommendationId, string $userId, string $agentTaskId, string $idempotencyKey): array;

    /**
     * @return array{id: string, status: string, error_code: ?string, approved_by: ?string, article_id: string}|null
     */
    public function refresh(string $refreshId): ?array;

    /**
     * Approve the WordPress update of refreshed content that a human already approved.
     *
     * @return array{id: string, status: string}
     */
    public function publishRefresh(string $refreshId, string $userId): array;

    /**
     * Finished before/after experiments (verified outcomes for agent memory).
     *
     * @return list<array{id: string, change_type: string, outcome: ?string, page_url: string, observation_end: string}>
     */
    public function experimentOutcomes(string $siteId, string $sinceDate): array;
}
