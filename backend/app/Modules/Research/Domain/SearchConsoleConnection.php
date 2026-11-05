<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Google Search Console OAuth link of a site. Tokens are encrypted and never
 * returned by the API.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property ?string $property_url
 * @property ?string $google_email
 * @property ?string $access_token_encrypted
 * @property ?string $refresh_token_encrypted
 * @property ?Carbon $token_expires_at
 * @property string $status
 * @property ?string $last_error_code
 * @property ?Carbon $last_synced_at
 * @property ?string $connected_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SearchConsoleConnection extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'search_console_connections';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'refresh_token_encrypted' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @var list<string> */
    protected $hidden = ['access_token_encrypted', 'refresh_token_encrypted'];
}
