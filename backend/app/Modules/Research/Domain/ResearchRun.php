<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One research execution of a campaign (backed by a durable workflow run).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property ?string $workflow_run_id
 * @property string $trigger
 * @property string $status
 * @property ?string $stage
 * @property int $progress
 * @property ?string $idempotency_key
 * @property array<string, mixed>|null $stats
 * @property array<string, mixed>|null $warnings
 * @property int $cost_micro_usd
 * @property bool $quota_reserved
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?string $created_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ResearchRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'research_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'warnings' => 'array',
            'cost_micro_usd' => 'integer',
            'progress' => 'integer',
            'quota_reserved' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public const ACTIVE = ['queued', 'running'];

    /**
     * @return BelongsTo<SeoCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SeoCampaign::class, 'campaign_id');
    }

    /**
     * @return HasMany<ResearchSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ResearchSource::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
