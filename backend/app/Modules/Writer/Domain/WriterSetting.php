<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform configuration written by Super Admin: global row (workspace_id
 * null) + optional per-workspace overrides. Not tenant data.
 *
 * @property int $id
 * @property string|null $workspace_id
 * @property array<string, mixed> $values
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
