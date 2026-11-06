<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One trial per owner account / e-mail (abuse prevention).
 *
 * @property int $id
 * @property string $user_id
 * @property string $email_hash
 * @property string $workspace_id
 * @property string $plan_id
 * @property ?Carbon $created_at
 */
class TrialRedemption extends Model
{
    protected $table = 'trial_redemptions';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;
}
