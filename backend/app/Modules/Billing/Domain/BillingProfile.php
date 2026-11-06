<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Customer billing profile (company / tax information when provided).
 *
 * @property string $id
 * @property string $workspace_id
 * @property ?string $company_name
 * @property ?string $tax_code
 * @property ?string $address
 * @property ?string $contact_name
 * @property ?string $email
 * @property ?string $phone
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class BillingProfile extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'billing_profiles';

    protected $guarded = ['*'];
}
