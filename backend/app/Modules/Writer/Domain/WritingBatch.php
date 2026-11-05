<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Bulk generation batch (bounded, quota-reserved).
 *
 * @property string $id
 */
class WritingBatch extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'writing_batches';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_demo' => 'boolean',
            'total' => 'integer',
            'concurrency' => 'integer',
            'per_article_budget_micro_usd' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'reserved_cost_micro_usd' => 'integer',
            'paused_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WritingBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WritingBatchItem::class, 'writing_batch_id');
    }
}
