<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Platform limits set by Super Admin: scope_key 'global' (defaults) or
 * 'ws:<id>' (override). Interim entitlement until plans/billing exist.
 *
 * @property string $id
 * @property string $scope_key
 * @property string $id
 * @property string $scope_key
 * @property ?int $max_storage_bytes
 * @property ?int $max_uploads_per_day
 * @property ?int $max_ai_images_per_day
 * @property ?int $max_ai_cost_per_job_micro_usd
 * @property bool $ai_images_enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MediaLimit extends Model
{
    use HasUlids;

    protected $table = 'media_limits';

    protected $guarded = ['*'];
}
