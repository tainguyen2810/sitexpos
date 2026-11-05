<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Enums;

enum WorkspaceRole: string
{
    case Owner = 'OWNER';
    case Admin = 'ADMIN';
    case Editor = 'EDITOR';
    case Viewer = 'VIEWER';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => [
                Permission::WorkspaceView, Permission::WorkspaceUpdate, Permission::MembersView,
                Permission::MembersManage, Permission::AuditView, Permission::SitesManage,
                Permission::ContentEdit, Permission::ContentPublish,
            ],
            self::Editor => [
                Permission::WorkspaceView, Permission::MembersView, Permission::ContentEdit,
            ],
            self::Viewer => [
                Permission::WorkspaceView, Permission::MembersView,
            ],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Roles this role may assign, invite, change or remove.
     * OWNER is never assignable — ownership moves only via transfer.
     *
     * @return list<self>
     */
    public function manageableRoles(): array
    {
        return match ($this) {
            self::Owner => [self::Admin, self::Editor, self::Viewer],
            self::Admin => [self::Editor, self::Viewer],
            default => [],
        };
    }

    public function canManage(self $target): bool
    {
        return in_array($target, $this->manageableRoles(), true);
    }

    /**
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return [self::Admin->value, self::Editor->value, self::Viewer->value];
    }
}
