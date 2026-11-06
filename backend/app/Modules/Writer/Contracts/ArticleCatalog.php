<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Contracts;

/**
 * Read-only view of Writer articles for Phase 6 (Media, Seo, Publishing).
 * Results are tenant-scoped (fail-closed workspace scope) and plain arrays,
 * so consumers can never write to Writer tables.
 */
interface ArticleCatalog
{
    /**
     * @return array{id: string, site_id: string, campaign_id: string|null, content_idea_id: string|null, title: string, status: string,
     *     primary_keyword: string, secondary_keywords: list<string>, search_intent: string, content_type: string, language: string,
     *     current_version_id: string|null, approved_version_id: string|null, approved_at: string|null, approved_by: string|null,
     *     unresolved_critical: int, unresolved_high: int, quality_score: int|null, is_demo: bool, updated_at: string|null,
     *     spent_micro_usd: int, created_at: string|null}|null
     */
    public function find(string $articleId): ?array;

    /**
     * The APPROVED version (never a draft) with the sources it cites, or
     * null when the article is not approved. `html` still contains the
     * `<sup><a data-evidence-id>` citation markers.
     *
     * @return array{version_id: string, number: int, title: string, html: string, content_hash: string, word_count: int,
     *     sources: array<string, array{url: string, title: string|null, publisher: string|null}>,
     *     brief: array{audience: string|null, search_intent: string|null, main_question: string|null, faq: list<array{question: string, answer: string}>}}|null
     */
    public function approvedContent(string $articleId): ?array;

    /**
     * Latest version (approved or not) for previews / media placement.
     *
     * @return array{version_id: string, number: int, title: string, html: string, content_hash: string, word_count: int}|null
     */
    public function currentContent(string $articleId): ?array;

    /**
     * Articles of a site (or all sites) in the given statuses, newest first.
     *
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    public function list(?string $siteId, array $statuses = ['APPROVED'], int $limit = 200): array;
}
