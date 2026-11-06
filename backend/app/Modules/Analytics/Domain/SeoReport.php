<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`seo_reports`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property ?string $site_id
 * @property string $period_type
 * @property string $period_start
 * @property string $period_end
 * @property string $status
 * @property array<string, mixed>|null $payload
 * @property ?string $error_code
 * @property ?string $generated_by
 * @property ?Carbon $emailed_at
 * @property int $recipients_count
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SeoReport extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_reports';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'emailed_at' => 'datetime',
            'recipients_count' => 'integer',
            'is_demo' => 'boolean',
        ];
    }
}
