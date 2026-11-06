<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Sellable plan (catalog). Never deleted while a subscription references it.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property string $status
 * @property bool $is_free
 * @property bool $is_demo
 * @property int $display_order
 * @property ?string $current_version_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Plan extends Model
{
    use HasUlids;

    protected $table = 'plans';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_free' => 'boolean',
            'is_demo' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}
