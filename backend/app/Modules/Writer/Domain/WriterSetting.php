<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Platform configuration written by Super Admin: global row (workspace_id
 * null) + optional per-workspace overrides. Not tenant data.
 *
 * @property int $id
 * @property ?string $workspace_id
 * @property array<string, mixed>|null $values
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WriterSetting extends Model
{
    protected $table = 'writer_settings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['values' => 'array'];
    }
}
