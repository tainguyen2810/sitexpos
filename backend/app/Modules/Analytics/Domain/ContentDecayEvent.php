<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`content_decay_events`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $page_hash
 * @property string $page_url
 * @property ?string $article_id
 * @property string $status
 * @property ?string $open_key
 * @property float $decline_pct
 * @property array<string, mixed> $recent
 * @property array<string, mixed> $previous
 * @property array<string, mixed>|null $baseline
 * @property array<string, mixed>|null $seasonal
 * @property array<string, mixed>|null $query_changes
 * @property array<string, mixed>|null $url_changes
 * @property array<string, mixed>|null $content_changes
 * @property list<string> $signals
 * @property Carbon $detected_at
 * @property Carbon $last_evaluated_at
 * @property ?string $decided_by
 * @property ?Carbon $decided_at
 * @property ?string $decision_note
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ContentDecayEvent extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_decay_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'decline_pct' => 'float',
            'recent' => 'array',
            'previous' => 'array',
            'baseline' => 'array',
            'seasonal' => 'array',
            'query_changes' => 'array',
            'url_changes' => 'array',
            'content_changes' => 'array',
            'signals' => 'array',
            'detected_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'decided_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
