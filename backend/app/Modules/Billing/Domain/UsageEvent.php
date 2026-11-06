<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Idempotent usage ledger (+consume / −release).
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $resource_key
 * @property string $period_start
 * @property int $amount
 * @property string $idempotency_key
 * @property ?Carbon $created_at
 */
class UsageEvent extends Model
{
    use BelongsToWorkspace;

    protected $table = 'usage_events';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: corrections are new rows, never edits or deletes.
        static::updating(static fn (): never => throw new LogicException(static::class.' is append-only.'));
        static::deleting(static fn (): never => throw new LogicException(static::class.' is append-only.'));
    }
}
