<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Actions;

use Carbon\CarbonImmutable;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Publishing\Domain\PublishingBatch;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingSchedule;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Support\PublishingLimits;
use SiteXPost\Modules\Publishing\Support\PublishingStatus as S;
use SiteXPost\Modules\Publishing\Support\ScheduleRules;
use SiteXPost\Modules\Publishing\Support\SitePolicy;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Bulk publishing: preview (per-item eligibility + proposed slot in the
 * site's timezone) → create (one job per article, each through every gate;
 * failures stay per item) → pause / resume / cancel. Immediate items are
 * staggered (rate limit); scheduled items respect window, spacing and
 * daily limits. Never more than the plan's bulk limit.
 */
final class Batches
{
    public const STAGGER_SECONDS = 60;

    public function __construct(
        private readonly ArticleCatalog $articles,
        private readonly SiteDirectory $sites,
        private readonly ScheduleRules $rules,
        private readonly ManageJobs $jobs,
    ) {}

    /**
     * @param  list<string>  $articleIds
     * @return array{site: array<string, mixed>, items: list<array<string, mixed>>, eligible: int, limit: int, timezone: string}
     */
    public function preview(string $siteId, array $articleIds, string $mode, ?string $startAt, int $interval): array
    {
        $profile = $this->sites->publishingProfile($siteId) ?? throw ApiException::notFound();
        $limits = PublishingLimits::for((string) app(TenantContext::class)->workspaceId());
        if (count($articleIds) > $limits['max_bulk_items']) {
            throw new ApiException(422, 'bulk_limit_exceeded', __('publishing.codes.bulk_limit_exceeded', ['max' => $limits['max_bulk_items']]));
        }
        $policy = SitePolicy::for($siteId);
        $cursor = $startAt !== null ? CarbonImmutable::parse($startAt)->utc() : CarbonImmutable::now('UTC')->addMinutes(10);
        $items = [];
        $eligible = 0;
        $taken = [];
        foreach (array_values(array_unique($articleIds)) as $id) {
            $a = $this->articles->find($id);
            $code = null;
            if ($a === null || $a['site_id'] !== $siteId) {
                $code = 'article_not_found';
            } elseif ($a['status'] !== 'APPROVED') {
                $code = 'article_not_approved';
            } elseif ($a['unresolved_critical'] > 0 || $a['unresolved_high'] > 0) {
                $code = 'unresolved_claims';
            } elseif (PublishingJob::query()->where('active_key', $siteId.':'.$id)->exists()) {
                $code = 'active_job_exists';
            } elseif (WordPressPostMapping::query()->where('site_id', $siteId)->where('article_id', $id)->where('is_current', true)->whereNotNull('wp_post_id')->exists()) {
                $code = 'already_published_use_update';
            }
            $slot = null;
            if ($code === null && $mode === 'scheduled') {
                $slot = $this->slotAfter($cursor, $profile['timezone'], $siteId, $a['campaign_id'] ?? null, $policy, $limits, $taken);
                if ($slot === null) {
                    $code = 'no_slot_available';
                } else {
                    $taken[] = $slot;
                    $cursor = $slot->addMinutes(max($interval, (int) $policy['min_minutes_between_posts']));
                }
            }
            $code === null && $eligible++;
            $items[] = ['article_id' => $id, 'title' => $a['title'] ?? null, 'ok' => $code === null, 'code' => $code,
                'message' => $code !== null ? __('publishing.codes.'.$code) : null, 'scheduled_at' => $slot?->toIso8601ZuluString(),
                'local_time' => $slot?->setTimezone($profile['timezone'])->format('Y-m-d H:i')];
        }

        return ['site' => ['id' => $profile['id'], 'host' => $profile['host'], 'paired' => $profile['paired']], 'items' => $items, 'eligible' => $eligible,
            'limit' => $limits['max_bulk_items'], 'timezone' => $profile['timezone']];
    }

    /**
     * @param  array{site_id: string, article_ids: list<string>, mode: string, start_at?: string|null, interval_minutes?: int|null}  $data
     */
    public function create(array $data, string $userId, ?string $idempotencyKey): PublishingBatch
    {
        if ($idempotencyKey !== null && ($existing = PublishingBatch::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }
        $interval = (int) ($data['interval_minutes'] ?? 60);
        $preview = $this->preview($data['site_id'], $data['article_ids'], $data['mode'], $data['start_at'] ?? null, $interval);
        $batch = new PublishingBatch;
        $batch->forceFill(['site_id' => $data['site_id'], 'mode' => $data['mode'], 'status' => 'running', 'start_at' => $data['start_at'] ?? null,
            'interval_minutes' => $interval, 'total' => count($preview['items']), 'idempotency_key' => $idempotencyKey, 'created_by' => $userId])->save();

        $i = 0;
        foreach ($preview['items'] as $item) {
            if (! $item['ok']) {
                continue;
            }
            try {
                $job = $this->jobs->create(['article_id' => $item['article_id'], 'mode' => $data['mode'], 'scheduled_at' => $item['scheduled_at'],
                    'publishing_batch_id' => $batch->id], $userId, null);
                if ($job->status !== S::READY_FOR_REVIEW) {
                    continue; // gate failed: stays DRAFT with its reason (partial failure)
                }
                if ($data['mode'] !== 'scheduled') {
                    // Stagger immediate items so a bulk run never floods the website.
                    $job->forceFill(['scheduled_at' => now()->addSeconds(self::STAGGER_SECONDS * $i)])->save();
                }
                $this->jobs->approve($job, $userId, null, $data['mode'] !== 'scheduled' && $i > 0 ? CarbonImmutable::now('UTC')->addSeconds(self::STAGGER_SECONDS * $i) : null);
                $i++;
            } catch (ApiException) {
                // per-item failure is visible on the job (gate_results); the batch continues
            }
        }
        Audit::record('publishing.batch_created', $batch, ['mode' => $data['mode'], 'items' => count($preview['items']), 'eligible' => $preview['eligible']]);

        return $batch;
    }

    public function pause(PublishingBatch $b, string $userId): PublishingBatch
    {
        $this->assertStatus($b, ['running']);
        $b->forceFill(['status' => 'paused'])->save();
        Audit::record('publishing.batch_paused', $b, [], actorId: $userId);

        return $b;
    }

    public function resume(PublishingBatch $b, string $userId): PublishingBatch
    {
        $this->assertStatus($b, ['paused']);
        $b->forceFill(['status' => 'running'])->save();
        Audit::record('publishing.batch_resumed', $b, [], actorId: $userId);

        return $b;
    }

    public function cancel(PublishingBatch $b, string $userId): PublishingBatch
    {
        $this->assertStatus($b, ['running', 'paused']);
        $b->forceFill(['status' => 'canceled'])->save();
        foreach (PublishingJob::query()->where('publishing_batch_id', $b->id)->whereNotIn('status', [...S::TERMINAL, S::PUBLISHING])->get() as $job) {
            $job->forceFill(['status' => S::CANCELLED, 'active_key' => null, 'next_attempt_at' => null])->save();
            PublishingSchedule::query()->where('publishing_job_id', $job->id)->where('status', 'pending')->update(['status' => 'canceled']);
        }
        // Jobs not yet sent are cancelled too; a job whose request is in flight finishes (never half-cancelled).
        PublishingJob::query()->where('publishing_batch_id', $b->id)->where('status', S::PUBLISHING)->where('attempts', 0)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update(['status' => S::CANCELLED, 'active_key' => null, 'next_attempt_at' => null]);
        Audit::record('publishing.batch_canceled', $b, [], actorId: $userId);

        return $b;
    }

    /**
     * @return array<string, int>
     */
    public static function counts(PublishingBatch $b): array
    {
        return PublishingJob::query()->where('publishing_batch_id', $b->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)->all();
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $limits
     * @param  list<CarbonImmutable>  $taken
     */
    private function slotAfter(CarbonImmutable $from, string $tz, string $siteId, ?string $campaignId, array $policy, array $limits, array $taken): ?CarbonImmutable
    {
        $t = $from;
        for ($i = 0; $i < 400; $i++) {
            $slot = $this->rules->nextSlot($t, $tz, $siteId, $campaignId, $policy, $limits);
            if ($slot === null) {
                return null;
            }
            $sameDay = array_filter($taken, fn (CarbonImmutable $x) => $x->setTimezone($tz)->toDateString() === $slot->setTimezone($tz)->toDateString());
            $tooClose = array_filter($taken, fn (CarbonImmutable $x) => abs($x->diffInMinutes($slot)) < (int) $policy['min_minutes_between_posts']);
            if ($tooClose === [] && count($sameDay) < (int) $policy['max_posts_per_day']) {
                return $slot;
            }
            $t = $tooClose !== [] ? $slot->addMinutes(15) : $slot->setTimezone($tz)->addDay()->startOfDay()->setTimezone('UTC');
        }

        return null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(PublishingBatch $b, array $allowed): void
    {
        if (! in_array($b->status, $allowed, true)) {
            throw ApiException::conflict('invalid_batch_state', __('publishing.codes.invalid_batch_state'));
        }
    }
}
