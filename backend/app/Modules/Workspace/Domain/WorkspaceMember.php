<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property WorkspaceRole $role
 * @property MemberStatus $status
 * @property string|null $invited_by
 * @property Carbon $joined_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class WorkspaceMember extends Model
{
    use BelongsToWorkspace, HasUlids;

    /** user_id/role/status are set by Actions only. */
    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'invited_by' => null];

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => MemberStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
