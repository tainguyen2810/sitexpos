<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Queries;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Publishing\Actions\ManageJobs;
use SiteXPost\Modules\Publishing\Contracts\PublishRequests;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;

final class PublishRequestService implements PublishRequests
{
    public function __construct(private readonly ManageJobs $jobs) {}

    public function requestUpdate(string $articleId, string $userId, string $idempotencyKey, string $source): array
    {
        $job = $this->jobs->create(['article_id' => $articleId, 'mode' => 'update'], $userId, mb_substr($idempotencyKey, 0, 100), mb_substr($source, 0, 16));

        return self::shape($job);
    }

    public function approve(string $jobId, string $userId): array
    {
        $job = PublishingJob::query()->find($jobId) ?? throw ApiException::notFound();

        return self::shape($this->jobs->approve($job, $userId));
    }

    public function job(string $jobId): ?array
    {
        $job = PublishingJob::query()->find($jobId);

        return $job === null ? null : self::shape($job) + ['published_at' => $job->published_at?->toIso8601ZuluString()];
    }

    /**
     * @return array{id: string, status: string, blocked_reason: string|null}
     */
    private static function shape(PublishingJob $job): array
    {
        return ['id' => $job->id, 'status' => (string) $job->status, 'blocked_reason' => $job->blocked_reason !== null ? (string) $job->blocked_reason : null];
    }
}
