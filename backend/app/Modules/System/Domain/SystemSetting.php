<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Platform-wide setting (not tenant data). Secret values are encrypted with
 * APP_KEY and never leave the server; see Support\SystemSettings.
 *
 * @property string $key
 * @property ?string $value
 * @property bool $is_secret
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SystemSetting extends Model
{
    protected $table = 'system_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }
}
