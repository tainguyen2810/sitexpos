<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Writer\Domain\WriterUsageCounter;

/**
 * Monthly article quota. Reservation is atomic (row lock), so two
 * concurrent bulk requests cannot both pass the limit.
 */
final class WriterQuota
{
    public function __construct(private readonly WriterSettings $settings, private readonly TenantContext $context) {}

    public function reserve(int $count): void
    {
        $ws = $this->context->workspaceId();
        $limit = $this->settings->int('max_articles_per_month', $ws);
        $period = now()->format('Y-m');
        DB::transaction(function () use ($count, $limit, $period): void {
            $row = WriterUsageCounter::query()->where('period', $period)->lockForUpdate()->first();
            if ($row === null) {
                try {
                    $row = new WriterUsageCounter;
                    $row->forceFill(['period' => $period, 'articles_reserved' => 0])->save();
                } catch (QueryException) {
                    $row = WriterUsageCounter::query()->where('period', $period)->lockForUpdate()->firstOrFail();
                }
            }
            if ($row->articles_reserved + $count > $limit) {
                throw new ApiException(429, 'writer_quota_exceeded', __('writer.errors.quota', ['max' => $limit, 'left' => max(0, $limit - $row->articles_reserved)]));
            }
            WriterUsageCounter::query()->whereKey($row->id)->increment('articles_reserved', $count);
        });
    }

    public function release(int $count): void
    {
        WriterUsageCounter::query()->where('period', now()->format('Y-m'))->where('articles_reserved', '>=', $count)->decrement('articles_reserved', $count);
    }

    /**
     * @return array{period: string, used: int, limit: int, remaining: int}
     */
    public function status(): array
    {
        $ws = $this->context->workspaceId();
        $limit = $this->settings->int('max_articles_per_month', $ws);
        $used = (int) WriterUsageCounter::query()->where('period', now()->format('Y-m'))->value('articles_reserved');

        return ['period' => now()->format('Y-m'), 'used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used)];
    }
}
