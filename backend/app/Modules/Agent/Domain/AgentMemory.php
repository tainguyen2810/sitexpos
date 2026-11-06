<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`agent_memories`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $kind
 * @property string $content
 * @property array<string, mixed>|null $evidence
 * @property ?string $source_ref
 * @property bool $verified
 * @property int $confidence
 * @property ?Carbon $expires_at
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AgentMemory extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'agent_memories';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'verified' => 'boolean',
            'confidence' => 'integer',
            'expires_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
