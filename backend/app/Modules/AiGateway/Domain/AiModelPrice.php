<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One immutable price version of a model. Amounts are micro-USD per 1M tokens
 * (or per unit for images/searches). A request is always priced with the
 * version that was in force when it ran (`ai_requests.price_id`).
 *
 * @property string $id
 * @property string $model_id
 * @property int $version
 * @property Carbon $effective_from
 * @property string $currency
 * @property int $input_per_mtok
 * @property int $cached_input_per_mtok
 * @property int $cache_write_per_mtok
 * @property int $output_per_mtok
 * @property int|null $long_context_threshold
 * @property int|null $long_input_per_mtok
 * @property int|null $long_cached_input_per_mtok
 * @property int|null $long_cache_write_per_mtok
 * @property int|null $long_output_per_mtok
 * @property int $per_image
 * @property int $per_1k_searches
 * @property string|null $source_url
 * @property string|null $notes
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AiModel $model
 */
class AiModelPrice extends Model
{
    use HasUlids;

    public const RATE_FIELDS = [
        'input_per_mtok', 'cached_input_per_mtok', 'cache_write_per_mtok', 'output_per_mtok',
        'long_context_threshold', 'long_input_per_mtok', 'long_cached_input_per_mtok', 'long_cache_write_per_mtok', 'long_output_per_mtok',
        'per_image', 'per_1k_searches',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'datetime',
            'input_per_mtok' => 'integer',
            'cached_input_per_mtok' => 'integer',
            'cache_write_per_mtok' => 'integer',
            'output_per_mtok' => 'integer',
            'long_context_threshold' => 'integer',
            'long_input_per_mtok' => 'integer',
            'long_cached_input_per_mtok' => 'integer',
            'long_cache_write_per_mtok' => 'integer',
            'long_output_per_mtok' => 'integer',
            'per_image' => 'integer',
            'per_1k_searches' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
