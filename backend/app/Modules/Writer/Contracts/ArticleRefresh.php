<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Contracts;

use SiteXPost\Kernel\Http\ApiException;

/**
 * Phase 8 — the only write path from Analytics / Agent into the Writer for
 * a content refresh. Every call goes through the Phase 5 Editorial
 * actions, so AI rewrites stay PROPOSALS until applied, applying creates a
 * new article version (history kept), changed sections are re-fact-checked
 * by the recheck workflow and approval keeps its human quality gate.
 */
interface ArticleRefresh
{
    /**
     * @return list<array{key: string, heading: string, words: int}>
     */
    public function sections(string $articleId): array;

    /**
     * AI proposal for one section ('expand' | 'regenerate_section' | 'rewrite').
     *
     * @throws ApiException
     */
    public function proposeSectionRewrite(string $articleId, string $sectionKey, string $action, string $userId, string $idempotencyKey): string;

    /**
     * @return array{status: string, section_key: string|null, proposed_html: string|null, error_code: string|null}|null
     */
    public function revision(string $revisionId): ?array;

    /**
     * Apply a proposed revision → new version id.
     *
     * @throws ApiException
     */
    public function applyRevision(string $articleId, string $revisionId, string $userId): string;

    /**
     * Re-fact-check sections changed since their last check (RecheckWorkflow).
     *
     * @throws ApiException
     */
    public function startRecheck(string $articleId, string $userId, string $idempotencyKey): string;

    /**
     * @return array{status: string, current_version_id: string|null, approved_version_id: string|null, unresolved_critical: int, unresolved_high: int, workflow_active: bool}|null
     */
    public function state(string $articleId): ?array;

    /**
     * Human approval of the refreshed version (Writer approval gate applies).
     *
     * @throws ApiException
     */
    public function approve(string $articleId, string $versionId, string $userId, ?string $note): void;

    /**
     * @return array{id: string, number: int, html: string, content_hash: string}|null
     */
    public function version(string $versionId): ?array;
}
