<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingSchedule;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Jobs\RunPublishingJob;
use SiteXPost\Modules\Publishing\Support\PublishingGates;
use SiteXPost\Modules\Publishing\Support\PublishingLimits;
use SiteXPost\Modules\Publishing\Support\PublishingStatus as S;
use SiteXPost\Modules\Publishing\Support\ScheduleRules;
use SiteXPost\Modules\Publishing\Support\SitePolicy;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Publishing Center operations. A job is created per (article, site) —
 * at most one active job at a time (unique `active_key`) — then approved
 * by a human with content.publish. Approval freezes the approved article
 * version and the rendered content hash: the publisher refuses to send
 * anything that differs from what was approved.
 */
final class ManageJobs
{
    public const MODES = ['wp_draft', 'publish_now', 'scheduled', 'update'];

    public function __construct(
        private readonly ArticleCatalog $articles,
        private readonly SiteDirectory $sites,
        private readonly SeoGate $seo,
        private readonly PublishingGates $gates,
        private readonly ScheduleRules $schedule,
    ) {}

    /**
     * @param  array{article_id: string, mode: string, scheduled_at?: string|null, publishing_batch_id?: string|null}  $data
     */
    public function create(array $data, ?string $userId, ?string $idempotencyKey, string $approvalSource = 'user'): PublishingJob
    {
        if ($idempotencyKey !== null && ($existing = PublishingJob::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }
        $article = $this->articles->find($data['article_id']) ?? throw ApiException::notFound();
        $profile = $this->sites->publishingProfile($article['site_id']) ?? throw ApiException::notFound();
        $mode = $data['mode'];
        $mapping = WordPressPostMapping::query()->where('site_id', $article['site_id'])->where('article_id', $article['id'])->where('is_current', true)->first();
        $target = match ($mode) {
            'wp_draft' => 'draft',
            'update' => ($mapping?->wp_status === 'publish' ? 'publish' : 'draft'),
            default => 'publish',
        };
        $scheduledAt = null;
        if ($mode === 'scheduled' || $mode === 'auto') {
            $scheduledAt = isset($data['scheduled_at']) ? CarbonImmutable::parse((string) $data['scheduled_at'])->utc() : null;
            if ($scheduledAt === null) {
                throw new ApiException(422, 'schedule_required', __('publishing.codes.schedule_required'));
            }
        }

        $job = new PublishingJob;
        $job->forceFill([
            'site_id' => $article['site_id'], 'article_id' => $article['id'], 'campaign_id' => $article['campaign_id'], 'mode' => $mode,
            'publishing_batch_id' => $data['publishing_batch_id'] ?? null, 'mapping_id' => $mapping?->id,
            'status' => S::DRAFT, 'active_key' => $article['site_id'].':'.$article['id'], 'wp_target_status' => $target,
            'scheduled_at' => $scheduledAt, 'max_attempts' => SitePolicy::for($article['site_id'])['max_attempts'],
            'idempotency_key' => $idempotencyKey, 'approval_source' => $approvalSource, 'created_by' => $userId,
            'payload_meta' => ['site_host' => $profile['host'], 'timezone' => $profile['timezone']],
        ]);
        try {
            $job->save();
        } catch (QueryException $e) {
            if ($this->isUnique($e)) {
                throw ApiException::conflict('active_job_exists', __('publishing.codes.active_job_exists'));
            }
            throw $e;
        }
        $this->recheck($job);
        Audit::record('publishing.job_created', $job, ['mode' => $mode, 'article_id' => $article['id'], 'scheduled_at' => $scheduledAt?->toIso8601ZuluString()]);

        return $job->refresh();
    }

    /** Re-evaluate gates; DRAFT ⇄ READY_FOR_REVIEW. */
    public function recheck(PublishingJob $job): PublishingJob
    {
        $result = $this->gates->evaluate($job, 'create');
        $job->forceFill(['gate_results' => $this->summarize($result), 'blocked_reason' => $result['blocking']]);
        if ($result['ok'] && $job->status === S::DRAFT) {
            $job->status = S::READY_FOR_REVIEW;
        } elseif (! $result['ok'] && $job->status === S::READY_FOR_REVIEW) {
            $job->status = S::DRAFT;
        }
        $job->save();

        return $job;
    }

    /**
     * Human approval (content.publish). Re-runs every gate; freezes version + render hash.
     */
    public function approve(PublishingJob $job, ?string $userId, ?string $scheduledAt = null, ?CarbonImmutable $notBefore = null): PublishingJob
    {
        return DB::transaction(function () use ($job, $userId, $scheduledAt, $notBefore): PublishingJob {
            /** @var PublishingJob $job */
            $job = PublishingJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($scheduledAt !== null && in_array($job->mode, ['scheduled', 'auto'], true) && in_array($job->status, [S::DRAFT, S::READY_FOR_REVIEW], true)) {
                $job->scheduled_at = Carbon::parse($scheduledAt)->utc();
            }
            if ($job->status === S::DRAFT) {
                if ($this->recheck($job)->status === S::DRAFT) {
                    throw new ApiException(422, 'gates_failed', __('publishing.codes.'.$job->blocked_reason), ['gates' => $job->gate_results]);
                }
            }
            S::assert($job->status, S::APPROVED);
            $result = $this->gates->evaluate($job, 'approve');
            $job->forceFill(['gate_results' => $this->summarize($result), 'blocked_reason' => $result['blocking']]);
            if (! $result['ok']) {
                $job->save();
                throw new ApiException(422, 'gates_failed', __('publishing.codes.'.$result['blocking']), ['gates' => $job->gate_results]);
            }
            $render = $this->seo->render($job->article_id, true);
            $article = $render['article'];
            $job->forceFill([
                'status' => S::APPROVED, 'article_version_id' => $article['approved_version_id'], 'content_hash' => $render['hash'],
                'approved_by' => $userId, 'approved_at' => now(), 'approval_source' => $job->approval_source ?? 'user',
            ])->save();
            Audit::record('publishing.job_approved', $job, ['version_id' => $job->article_version_id, 'mode' => $job->mode, 'scheduled_at' => $job->scheduled_at?->toIso8601ZuluString()]);

            if (in_array($job->mode, ['scheduled', 'auto'], true)) {
                $this->toSchedule($job);
            } else {
                $this->startPublishing($job, $notBefore);
            }

            return $job;
        });
    }

    public function reschedule(PublishingJob $job, string $when, string $userId): PublishingJob
    {
        if (! in_array($job->status, [S::SCHEDULED, S::APPROVED], true)) {
            throw ApiException::conflict('invalid_transition', __('publishing.errors.invalid_transition', ['from' => $job->status, 'to' => S::SCHEDULED]));
        }
        $old = $job->scheduled_at?->toIso8601ZuluString();
        $job->scheduled_at = Carbon::parse($when)->utc();
        $this->toSchedule($job);
        Audit::record('publishing.job_rescheduled', $job, ['from' => $old, 'to' => $job->scheduled_at?->toIso8601ZuluString()], actorId: $userId);

        return $job;
    }

    public function cancel(PublishingJob $job, string $userId): PublishingJob
    {
        return DB::transaction(function () use ($job, $userId): PublishingJob {
            /** @var PublishingJob $job */
            $job = PublishingJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($job->status === S::PUBLISHING && $job->locked_until !== null && $job->locked_until->isFuture()) {
                throw ApiException::conflict('publish_in_progress', __('publishing.codes.publish_in_progress'));
            }
            S::assert($job->status, S::CANCELLED);
            $job->forceFill(['status' => S::CANCELLED, 'active_key' => null, 'next_attempt_at' => null])->save();
            PublishingSchedule::query()->where('publishing_job_id', $job->id)->whereIn('status', ['pending'])->update(['status' => 'canceled']);
            Audit::record('publishing.job_cancelled', $job, [], actorId: $userId);

            return $job;
        });
    }

    /** Manual retry of a FAILED job (re-runs every gate, resets the attempt budget). */
    public function retry(PublishingJob $job, string $userId): PublishingJob
    {
        S::assert($job->status, S::PUBLISHING);
        $result = $this->gates->evaluate($job, 'execute');
        $job->forceFill(['gate_results' => $this->summarize($result), 'blocked_reason' => $result['blocking']]);
        if (! $result['ok']) {
            $job->save();
            throw new ApiException(422, 'gates_failed', __('publishing.codes.'.$result['blocking']), ['gates' => $job->gate_results]);
        }
        $job->forceFill(['max_attempts' => $job->attempts + SitePolicy::for($job->site_id)['max_attempts']])->save();
        $this->startPublishing($job);
        Audit::record('publishing.job_retried', $job, ['attempts' => $job->attempts], actorId: $userId);

        return $job;
    }

    public function startPublishing(PublishingJob $job, ?CarbonImmutable $notBefore = null): void
    {
        S::assert($job->status, S::PUBLISHING);
        $at = $notBefore ?? CarbonImmutable::now('UTC');
        $job->forceFill(['status' => S::PUBLISHING, 'scheduled_at' => $job->scheduled_at ?? $at, 'next_attempt_at' => $at, 'last_error_code' => null, 'last_error_message' => null])->save();
        $pending = RunPublishingJob::dispatch($job->id)->afterCommit();
        if ($notBefore !== null && $notBefore->isFuture()) {
            $pending->delay($notBefore);
        }
    }

    private function toSchedule(PublishingJob $job): void
    {
        $profile = $this->sites->publishingProfile($job->site_id) ?? throw ApiException::notFound();
        if ($job->scheduled_at === null) {
            throw new ApiException(422, 'schedule_required', __('publishing.codes.schedule_required'));
        }
        $at = CarbonImmutable::parse($job->scheduled_at);
        $problems = $this->schedule->problems($at, $profile['timezone'], $job->site_id, $job->campaign_id, SitePolicy::for($job->site_id), PublishingLimits::for($job->workspace_id), $job->id, true);
        if ($problems !== []) {
            throw new ApiException(422, $problems[0], __('publishing.codes.'.$problems[0]), ['problems' => $problems, 'timezone' => $profile['timezone']]);
        }
        S::assert($job->status, S::SCHEDULED);
        $job->forceFill(['status' => S::SCHEDULED])->save();
        $row = PublishingSchedule::query()->where('publishing_job_id', $job->id)->first() ?? new PublishingSchedule;
        $row->forceFill(['site_id' => $job->site_id, 'publishing_job_id' => $job->id, 'scheduled_at' => $at, 'timezone' => $profile['timezone'],
            'local_time' => $at->setTimezone($profile['timezone'])->format('Y-m-d H:i'), 'status' => 'pending', 'dispatched_at' => null])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function summarize(array $result): array
    {
        return array_map(fn ($g) => ['gate' => $g['gate'], 'ok' => $g['ok'], 'code' => $g['code'], 'message' => $g['message'], 'details' => $g['details'] ?? []], $result['gates']);
    }

    private function isUnique(QueryException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'unique') || ($e->errorInfo[1] ?? null) === 1062;
    }
}
