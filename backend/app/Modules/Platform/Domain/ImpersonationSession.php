<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * @property string $id
 * @property string $staff_user_id
 * @property string $workspace_id
 * @property string $reason
 * @property bool $read_only
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property-read Workspace|null $workspace
 * @property-read User|null $staff
 */
class ImpersonationSession extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['read_only' => true, 'ended_at' => null];

    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function expiresAt(): Carbon
    {
        return $this->started_at->copy()->addMinutes((int) config('sitex.impersonation.max_minutes', 30));
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expiresAt()->isFuture();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
