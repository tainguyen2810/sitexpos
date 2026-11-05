<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * In-app notice (new SEO opportunities, failed research...).
 *
 * @property string $id
 * @property string $workspace_id
 * @property ?string $campaign_id
 * @property string $type
 * @property string $title
 * @property ?string $body
 * @property array<string, mixed>|null $data
 * @property string $dedupe_key
 * @property ?Carbon $read_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ResearchNotification extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'research_notifications';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
