<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Suggested internal link between ideas and existing WordPress content.
 *
 * @property string $id
 */
class InternalLinkSuggestion extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'internal_link_suggestions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }
}
