<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`copilot_queries`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property ?string $site_id
 * @property string $question
 * @property string $intent
 * @property array<string, mixed> $answer
 * @property array<string, mixed>|null $data_refs
 * @property string $mode
 * @property ?string $model
 * @property int $cost_micro_usd
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CopilotQuery extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'copilot_queries';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'answer' => 'array',
            'data_refs' => 'array',
            'cost_micro_usd' => 'integer',
        ];
    }
}
