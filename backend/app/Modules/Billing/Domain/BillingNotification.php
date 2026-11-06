<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Sent billing notification (dedupe key prevents duplicates).
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $type
 * @property string $dedupe_key
 * @property array<string, mixed>|null $data
 * @property Carbon $sent_at
 */
class BillingNotification extends Model
{
    use BelongsToWorkspace;

    protected $table = 'billing_notifications';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
