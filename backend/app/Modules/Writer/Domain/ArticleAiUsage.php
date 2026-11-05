<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Cost attribution of one AI call (unique call_key → never double counted).
 *
 * @property string $id
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
