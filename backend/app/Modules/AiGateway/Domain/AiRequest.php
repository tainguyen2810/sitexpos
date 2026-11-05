<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Append-only log of every provider call (one row per attempt). Never stores
 * prompt/response content or credentials: only a prompt hash, usage and cost.
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $workspace_id
 * @property string $task_key
 * @property string $provider
 * @property string $model
 * @property string|null $model_id
 * @property string|null $price_id
 * @property string|null $workflow_run_id
 * @property string|null $call_key
 * @property int $attempt
 * @property bool $is_fallback
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $http_status
 * @property bool $billing_uncertain
 * @property int $input_tokens
 * @property int $cached_input_tokens
 * @property int $cache_write_tokens
 * @property int $output_tokens
 * @property int $reasoning_tokens
 * @property int $image_count
 * @property int $search_count
 * @property int $cost_micro_usd
 * @property int $estimated_cost_micro_usd
 * @property int $latency_ms
 * @property string|null $prompt_hash
 * @property string|null $provider_request_id
 * @property string|null $finish_reason
 * @property Carbon $created_at
 */
class AiRequest extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_fallback' => 'boolean',
            'billing_uncertain' => 'boolean',
            'attempt' => 'integer',
            'http_status' => 'integer',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'image_count' => 'integer',
            'search_count' => 'integer',
            'cost_micro_usd' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $request): void {
            $request->ulid ??= strtolower((string) Str::ulid());
        });
    }
}
