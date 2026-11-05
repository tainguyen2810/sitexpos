<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Google Search Console OAuth link of a site. Tokens are encrypted and never
 * returned by the API.
 *
 * @property string $id
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
