<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $status
 * @property string $trigger
 * @property ?string $file_name
 * @property ?int $size_bytes
 * @property ?string $sha256
 * @property ?list<string> $contents
 * @property string $offsite_status
 * @property ?string $offsite_disk
 * @property string $verify_status
 * @property ?Carbon $verified_at
 * @property ?string $error
 * @property ?string $requested_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 */
class SystemBackup extends Model
{
    use HasUlids;

    protected $table = 'system_backups';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'contents' => 'array',
            'size_bytes' => 'integer',
            'verified_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
