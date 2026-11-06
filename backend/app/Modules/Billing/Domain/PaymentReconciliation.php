<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Reconciliation decision log (automatic or staff).
 *
 * @property int $id
 * @property ?string $payment_transaction_id
 * @property ?string $payment_order_id
 * @property string $result
 * @property int $difference_minor
 * @property ?string $reviewed_by
 * @property ?string $note
 * @property ?Carbon $created_at
 */
class PaymentReconciliation extends Model
{
    protected $table = 'payment_reconciliations';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'difference_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: corrections are new rows, never edits or deletes.
        static::updating(static fn (): never => throw new LogicException(static::class.' is append-only.'));
        static::deleting(static fn (): never => throw new LogicException(static::class.' is append-only.'));
    }
}
