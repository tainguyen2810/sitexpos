<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

final class CreateWorkspace
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{name:string, locale?:string|null, timezone?:string|null}  $data
     */
    public function handle(User $owner, array $data, bool $isDemo = false): Workspace
    {
        return DB::transaction(function () use ($owner, $data, $isDemo): Workspace {
            $workspace = new Workspace([
                'name' => $data['name'],
                'locale' => $data['locale'] ?? $owner->locale,
                'timezone' => $data['timezone'] ?? $owner->timezone,
            ]);
            $workspace->forceFill([
                'slug' => $this->uniqueSlug($data['name']),
                'owner_id' => $owner->id,
                'is_demo' => $isDemo,
            ])->save();

            $this->context->runAs($workspace, WorkspaceRole::Owner, function () use ($workspace, $owner): void {
                (new WorkspaceMember)->forceFill([
                    'user_id' => $owner->id,
                    'role' => WorkspaceRole::Owner,
                    'status' => MemberStatus::Active,
                    'joined_at' => now(),
                ])->save();

                Audit::record('workspace.created', $workspace, ['name' => $workspace->name], actorId: $owner->id);
            });

            $owner->forceFill(['last_workspace_id' => $workspace->id])->saveQuietly();

            return $workspace;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $base = Str::limit($base, 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Workspace::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
