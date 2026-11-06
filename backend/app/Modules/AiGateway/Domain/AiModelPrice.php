<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Carbon\CarbonInterface;
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
 * @property int|null $offpeak_input_per_mtok
 * @property int|null $offpeak_cached_input_per_mtok
 * @property int|null $offpeak_output_per_mtok
 * @property list<array{days: list<int>, from: string, to: string}>|null $peak_windows
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
        'offpeak_input_per_mtok', 'offpeak_cached_input_per_mtok', 'offpeak_output_per_mtok', 'peak_windows',
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
            'offpeak_input_per_mtok' => 'integer',
            'offpeak_cached_input_per_mtok' => 'integer',
            'offpeak_output_per_mtok' => 'integer',
            'peak_windows' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * Time-of-day pricing: off-peak rates apply outside `peak_windows`
     * (ISO weekdays 1=Mon…7=Sun, "HH:MM" UTC, `to` exclusive). Public holidays
     * are not modelled: a holiday inside a window is billed at the peak rate
     * (never under-counted).
     */
    public function hasOffPeak(): bool
    {
        return $this->peak_windows !== null && $this->peak_windows !== []
            && ($this->offpeak_input_per_mtok !== null || $this->offpeak_cached_input_per_mtok !== null || $this->offpeak_output_per_mtok !== null);
    }

    public function isPeak(CarbonInterface $at): bool
    {
        $utc = $at->copy()->utc();
        $day = $utc->dayOfWeekIso;
        $minute = $utc->hour * 60 + $utc->minute;
        foreach ($this->peak_windows ?? [] as $window) {
            if (! in_array($day, array_map('intval', $window['days']), true)) {
                continue;
            }
            if ($minute >= self::minutes($window['from']) && $minute < self::minutes($window['to'])) {
                return true;
            }
        }

        return false;
    }

    private static function minutes(string $hhmm): int
    {
        $parts = array_map('intval', explode(':', $hhmm));

        return ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
    }
}
