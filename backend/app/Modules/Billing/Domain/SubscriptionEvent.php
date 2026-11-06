<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Append-only subscription history.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $subscription_id
 * @property string $event
 * @property ?string $from_status
 * @property ?string $to_status
 * @property ?string $plan_version_id
 * @property ?string $payment_order_id
 * @property string $actor_type
 * @property ?string $actor_id
 * @property ?string $reason
 * @property array<string, mixed>|null $data
 * @property ?Carbon $created_at
 */
class SubscriptionEvent extends Model
{
    use BelongsToWorkspace;

    protected $table = 'subscription_events';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: corrections are new rows, never edits or deletes.
        static::updating(static fn (): never => throw new LogicException(static::class.' is append-only.'));
        static::deleting(static fn (): never => throw new LogicException(static::class.' is append-only.'));
    }
}
