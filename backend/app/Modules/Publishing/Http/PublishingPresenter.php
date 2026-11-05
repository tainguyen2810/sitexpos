<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use SiteXPost\Modules\Publishing\Actions\Batches;
use SiteXPost\Modules\Publishing\Domain\PublishingAttempt;
use SiteXPost\Modules\Publishing\Domain\PublishingBatch;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingSchedule;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Domain\WordPressSyncState;
use SiteXPost\Modules\Publishing\Support\PublishingStatus;

final class PublishingPresenter
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function page(Builder $query, callable $map, Request $request, int $default = 25): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->integer('per_page', $default)));
        $page = $query->cursorPaginate($perPage);

        return new JsonResponse([
            'data' => array_map($map, $page->items()),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'per_page' => $perPage],
        ]);
    }

    /**
     * @param  array<string, string>  $titles  article id => title
     * @return array<string, mixed>
     */
    public static function job(PublishingJob $j, array $titles = [], bool $detail = false): array
    {
        $out = [
            'id' => $j->id, 'site_id' => $j->site_id, 'article_id' => $j->article_id, 'article_title' => $titles[$j->article_id] ?? null,
            'campaign_id' => $j->campaign_id, 'publishing_batch_id' => $j->publishing_batch_id, 'mode' => $j->mode, 'status' => $j->status,
            'wp_target_status' => $j->wp_target_status, 'scheduled_at' => self::ts($j->scheduled_at), 'attempts' => (int) $j->attempts,
            'max_attempts' => (int) $j->max_attempts, 'next_attempt_at' => self::ts($j->next_attempt_at), 'last_error_code' => $j->last_error_code,
            'last_error_message' => $j->last_error_message, 'blocked_reason' => $j->blocked_reason, 'gate_results' => $j->gate_results ?? [],
            'approval_source' => $j->approval_source, 'approved_by' => $j->approved_by, 'approved_at' => self::ts($j->approved_at),
            'published_at' => self::ts($j->published_at), 'article_version_id' => $j->article_version_id,
            'wp_post_id' => $j->payload_meta['wp_post_id'] ?? null, 'url' => $j->payload_meta['url'] ?? null, 'timezone' => $j->payload_meta['timezone'] ?? 'UTC',
            'allowed_transitions' => PublishingStatus::map()[$j->status] ?? [], 'is_demo' => (bool) $j->is_demo,
            'created_at' => self::ts($j->created_at), 'updated_at' => self::ts($j->updated_at),
        ];
        if ($detail) {
            $out['attempts_log'] = PublishingAttempt::query()->where('publishing_job_id', $j->id)->orderBy('attempt_no')->get()
                ->map(fn (PublishingAttempt $a) => $a->only(['attempt_no', 'operation', 'outcome', 'error_code', 'http_status', 'retryable', 'wp_post_id', 'duration_ms', 'details'])
                    + ['created_at' => self::ts($a->created_at)])->all();
            $s = PublishingSchedule::query()->where('publishing_job_id', $j->id)->first();
            $out['schedule'] = $s === null ? null : $s->only(['status', 'timezone', 'local_time', 'note']) + ['scheduled_at' => self::ts($s->scheduled_at)];
            $out['conflicts'] = PublishingConflict::query()->where('publishing_job_id', $j->id)->orderByDesc('created_at')->get()->map(fn ($c) => self::conflict($c))->all();
            $out['has_rendered_html'] = $j->rendered_html !== null;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function conflict(PublishingConflict $c): array
    {
        return $c->only(['id', 'site_id', 'article_id', 'mapping_id', 'publishing_job_id', 'types', 'status', 'resolution', 'wp_state', 'expected_state', 'note', 'resolved_by', 'is_demo'])
            + ['resolved_at' => self::ts($c->resolved_at), 'created_at' => self::ts($c->created_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapping(WordPressPostMapping $m): array
    {
        $last = WordPressSyncState::query()->where('mapping_id', $m->id)->orderByDesc('checked_at')->orderByDesc('id')->first();

        return $m->only(['id', 'site_id', 'article_id', 'external_content_id', 'is_current', 'wp_post_id', 'wp_status', 'url', 'slug', 'site_host', 'last_published_version_id'])
            + ['last_published_at' => self::ts($m->last_published_at), 'last_checked_at' => self::ts($m->last_checked_at),
                'sync' => $last === null ? null : ['state' => $last->state, 'checked_at' => self::ts($last->checked_at), 'details' => $last->details]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function batch(PublishingBatch $b, bool $detail = false): array
    {
        $out = $b->only(['id', 'site_id', 'mode', 'status', 'interval_minutes', 'total']) + ['start_at' => self::ts($b->start_at), 'counts' => Batches::counts($b),
            'created_at' => self::ts($b->created_at)];
        if ($detail) {
            $out['items'] = PublishingJob::query()->where('publishing_batch_id', $b->id)->orderBy('scheduled_at')->orderBy('id')->limit(200)->get()
                ->map(fn (PublishingJob $j) => self::job($j))->all();
        }

        return $out;
    }

    public static function ts(?Carbon $t): ?string
    {
        return $t?->toIso8601ZuluString();
    }
}
