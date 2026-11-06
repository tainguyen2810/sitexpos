<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Reusable writing configuration (brand voice + defaults).
 *
 * @property string $id
 * @property string $workspace_id
 * @property ?string $site_id
 * @property string $name
 * @property bool $is_default
 * @property ?string $tone
 * @property ?string $writing_style
 * @property list<string>|null $preferred_terms
 * @property list<string>|null $forbidden_phrases
 * @property list<array<string, mixed>>|null $facts
 * @property list<string>|null $target_customers
 * @property ?string $geographic_context
 * @property ?string $editorial_guidelines
 * @property ?string $cta
 * @property array<string, mixed>|null $defaults
 * @property bool $is_demo
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WritingPreset extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'writing_presets';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'preferred_terms' => 'array',
            'forbidden_phrases' => 'array',
            'facts' => 'array',
            'target_customers' => 'array',
            'defaults' => 'array',
            'is_default' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }
}
