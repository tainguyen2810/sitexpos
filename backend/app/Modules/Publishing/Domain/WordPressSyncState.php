<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`wordpress_sync_states`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $mapping_id
 * @property string $state
 * @property ?string $wp_status
 * @property ?string $wp_slug
 * @property ?string $wp_content_hash
 * @property ?string $wp_seo_hash
 * @property ?string $wp_modified_gmt
 * @property ?int $wp_revision_id
 * @property array<string, mixed>|null $details
 * @property Carbon $checked_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WordPressSyncState extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'wordpress_sync_states';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wp_revision_id' => 'integer',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
