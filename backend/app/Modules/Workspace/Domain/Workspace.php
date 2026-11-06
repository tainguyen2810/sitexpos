<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Domain;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceType;

/**
 * The tenant. Not itself tenant-scoped: access is controlled by membership
 * (ResolveWorkspace) and by platform permissions.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property WorkspaceType $type
 * @property string $owner_id
 * @property string $locale
 * @property string $timezone
 * @property WorkspaceStatus $status
 * @property bool $is_demo
 * @property array<string, mixed>|null $settings
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'locale', 'timezone', 'settings'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'standard',
        'status' => 'active',
        'locale' => 'vi',
        'timezone' => 'Asia/Ho_Chi_Minh',
        'is_demo' => false,
        'settings' => null,
    ];

    protected function casts(): array
    {
        return [
            'type' => WorkspaceType::class,
            'status' => WorkspaceStatus::class,
            'is_demo' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<WorkspaceMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * @return HasMany<WorkspaceInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * @return HasMany<JobRun, $this>
     */
    public function jobRuns(): HasMany
    {
        return $this->hasMany(JobRun::class);
    }

    /**
     * Read-only cross-module reference, needed for scoped route bindings.
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
