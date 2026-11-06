<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Cost attribution of one AI call (unique call_key → never double counted).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $site_id
 * @property ?string $campaign_id
 * @property ?string $workflow_run_id
 * @property string $step
 * @property string $task_key
 * @property ?string $provider
 * @property ?string $model
 * @property string $call_key
 * @property ?string $ai_request_id
 * @property string $status
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $estimated_cost_micro_usd
 * @property int $cost_micro_usd
 * @property bool $usage_reported
 * @property int $retries
 * @property int $fetches
 * @property ?string $error_code
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ArticleAiUsage extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'article_ai_usage';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'cost_micro_usd' => 'integer',
            'usage_reported' => 'boolean',
            'retries' => 'integer',
            'fetches' => 'integer',
        ];
    }
}
