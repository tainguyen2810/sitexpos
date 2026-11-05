<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform limits set by Super Admin: scope_key 'global' (defaults) or
 * 'ws:<id>' (override). Interim entitlement until plans/billing exist.
 *
 * @property string $id
 * @property string $scope_key
 */
class PublishingLimit extends Model
{
    use HasUlids;

    protected $table = 'publishing_limits';

    protected $guarded = ['*'];
}
