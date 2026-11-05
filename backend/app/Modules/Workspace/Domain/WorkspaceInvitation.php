<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property string $token_hash
 * @property string $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $inviter
 */
class WorkspaceInvitation extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['accepted_at' => null, 'accepted_by' => null, 'revoked_at' => null];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    public function isPending(): bool
    {
        return $this->status() === 'pending';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
