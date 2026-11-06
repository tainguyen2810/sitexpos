<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Global billing setting (key → JSON value).
 *
 * @property string $key
 * @property array<string, mixed> $value
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class BillingSetting extends Model
{
    protected $table = 'billing_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
