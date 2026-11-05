<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Reusable writing configuration (brand voice + defaults).
 *
 * @property string $id
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
