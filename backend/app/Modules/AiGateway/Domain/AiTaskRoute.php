<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Routing rule for one task type: primary model, ordered fallbacks, a
 * cheaper model used when a budget passes its alert threshold, limits and
 * the paid-retry policy.
 *
 * @property string $id
 * @property string $task_key
 * @property string|null $description
 * @property string $queue
 * @property string $priority
 * @property string $primary_model_id
 * @property string|null $economy_model_id
 * @property list<string>|null $fallback_model_ids
 * @property int $max_input_tokens
 * @property int $max_output_tokens
 * @property string|null $temperature
 * @property int|null $timeout_seconds
 * @property int $max_attempts
 * @property bool $allow_paid_retry
 * @property bool $is_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AiModel $primaryModel
 * @property-read AiModel|null $economyModel
 */
class AiTaskRoute extends Model
{
    use HasUlids;

    public const PRIORITIES = ['interactive', 'batch'];

    protected $fillable = [
        'description', 'queue', 'priority', 'primary_model_id', 'economy_model_id', 'fallback_model_ids',
        'max_input_tokens', 'max_output_tokens', 'temperature', 'timeout_seconds', 'max_attempts', 'allow_paid_retry', 'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'fallback_model_ids' => 'array',
            'max_input_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'temperature' => 'decimal:2',
            'timeout_seconds' => 'integer',
            'max_attempts' => 'integer',
            'allow_paid_retry' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function primaryModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'primary_model_id');
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function economyModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'economy_model_id');
    }
}
