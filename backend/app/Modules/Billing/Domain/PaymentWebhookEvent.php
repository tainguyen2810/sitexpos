<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Received webhook (unique per provider event id → replay safe).
 *
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property bool $signature_valid
 * @property string $payload_hash
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property ?string $error
 * @property Carbon $received_at
 * @property ?Carbon $processed_at
 */
class PaymentWebhookEvent extends Model
{
    protected $table = 'payment_webhook_events';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
