<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Bulk generation batch (bounded, quota-reserved).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property ?string $campaign_id
 * @property ?string $writing_preset_id
 * @property string $source
 * @property string $status
 * @property int $total
 * @property int $concurrency
 * @property array<string, mixed>|null $settings
 * @property int $per_article_budget_micro_usd
 * @property int $estimated_cost_micro_usd
 * @property int $reserved_cost_micro_usd
 * @property ?string $idempotency_key
 * @property bool $is_demo
 * @property ?string $created_by
 * @property ?Carbon $paused_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
