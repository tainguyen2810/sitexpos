<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Audit\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use SiteXPost\Modules\Identity\Domain\User;

/**
 * Append-only audit record. Updates and deletes are refused at model level.
 *
 * Not tenant-scoped by global scope because platform actions have no
 * workspace; tenant reads MUST filter by workspace_id explicitly
 * (see AuditLogController).
 *
 * @property int $id
 * @property string|null $workspace_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $impersonator_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $changes
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property Carbon $created_at
 */
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Audit logs are immutable.'));
        self::deleting(static fn () => throw new LogicException('Audit logs are immutable.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }
}
