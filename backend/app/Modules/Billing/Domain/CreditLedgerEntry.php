<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Immutable credit ledger entry (corrections are reversal entries).
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $wallet_id
 * @property ?string $grant_id
 * @property string $entry_type
 * @property int $amount_mc
 * @property int $balance_after_mc
 * @property string $idempotency_key
 * @property ?string $reference_type
 * @property ?string $reference_id
 * @property ?int $reverses_entry_id
 * @property ?string $description
 * @property ?string $actor_id
 * @property ?Carbon $created_at
 */
class CreditLedgerEntry extends Model
{
    use BelongsToWorkspace;

    protected $table = 'credit_ledger_entries';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount_mc' => 'integer',
            'balance_after_mc' => 'integer',
            'reverses_entry_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: corrections are new rows, never edits or deletes.
        static::updating(static fn (): never => throw new LogicException(static::class.' is append-only.'));
        static::deleting(static fn (): never => throw new LogicException(static::class.' is append-only.'));
    }
}
