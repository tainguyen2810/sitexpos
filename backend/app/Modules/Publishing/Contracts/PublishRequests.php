<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Contracts;

use SiteXPost\Kernel\Http\ApiException;

/**
 * Phase 8 — Analytics (content refresh) and the SEO Agent reuse the Phase 6
 * Publisher through this contract: same gates, same approval freeze, same
 * conflict detection at send time. Callers must check `content.publish`
 * before `approve()`; the Publisher re-checks every gate itself.
 */
interface PublishRequests
{
    /**
     * Create (or return, by idempotency key) an "update" publishing job.
     *
     * @return array{id: string, status: string, blocked_reason: string|null}
     *
     * @throws ApiException
     */
    public function requestUpdate(string $articleId, string $userId, string $idempotencyKey, string $source): array;

    /**
     * @return array{id: string, status: string, blocked_reason: string|null}
     *
     * @throws ApiException
     */
    public function approve(string $jobId, string $userId): array;

    /**
     * @return array{id: string, status: string, blocked_reason: string|null, published_at: string|null}|null
     */
    public function job(string $jobId): ?array;
}
