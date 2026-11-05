<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Workspace\Actions\CreateWorkspace;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

/**
 * DEVELOPMENT ONLY demo accounts and workspaces (flagged is_demo = true).
 * Refuses to run when APP_ENV=production.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'SiteXDemo2026';

    public const DOMAIN = 'demo.sitexpost.test';

    public function run(CreateWorkspace $createWorkspace, TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder is disabled in production.');
        }

        $owner = $this->user('owner', 'Chủ Demo');
        $admin = $this->user('admin', 'Quản trị Demo');
        $editor = $this->user('editor', 'Biên tập Demo');
        $viewer = $this->user('viewer', 'Người xem Demo');
        $other = $this->user('other', 'Khách hàng B');

        $this->user('superadmin', 'Super Admin Demo', PlatformRole::SuperAdmin);
        $this->user('support', 'Hỗ trợ Demo', PlatformRole::Support);
        $this->user('billing', 'Kế toán Demo', PlatformRole::BillingAdmin);

        $shopA = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->first()
            ?? $createWorkspace->handle($owner, ['name' => 'Cửa hàng A (Demo)'], isDemo: true);

        $context->runAs($shopA, null, function () use ($admin, $editor, $viewer): void {
            foreach ([[$admin, WorkspaceRole::Admin], [$editor, WorkspaceRole::Editor], [$viewer, WorkspaceRole::Viewer]] as [$user, $role]) {
                (WorkspaceMember::query()->where('user_id', $user->id)->first() ?? new WorkspaceMember)->forceFill([
                    'user_id' => $user->id,
                    'role' => $role,
                    'status' => MemberStatus::Active,
                    'joined_at' => now(),
                ])->save();
            }
        });

        if (! Workspace::query()->where('name', 'Agency B (Demo)')->exists()) {
            $createWorkspace->handle($other, ['name' => 'Agency B (Demo)'], isDemo: true);
        }

        // Phase 4 — topic discovery demo (DEMO-labelled, no fake metrics).
        $this->call(ResearchDemoSeeder::class);
        // Phase 5 — AI Writer demo (DEMO-labelled, no invented sources or verdicts).
        $this->call(WriterDemoSeeder::class);
        // Phase 6 — media, SEO audits, publishing jobs and conflicts (DEMO-labelled, nothing sent to WordPress).
        $this->call(Phase6DemoSeeder::class);

        $this->command?->table(['Email', 'Mật khẩu', 'Vai trò'], [
            ['owner@'.self::DOMAIN, self::PASSWORD, 'OWNER — Cửa hàng A'],
            ['admin@'.self::DOMAIN, self::PASSWORD, 'ADMIN — Cửa hàng A'],
            ['editor@'.self::DOMAIN, self::PASSWORD, 'EDITOR — Cửa hàng A'],
            ['viewer@'.self::DOMAIN, self::PASSWORD, 'VIEWER — Cửa hàng A'],
            ['other@'.self::DOMAIN, self::PASSWORD, 'OWNER — Agency B'],
            ['superadmin@'.self::DOMAIN, self::PASSWORD, 'SUPER_ADMIN (cần thiết lập 2FA khi đăng nhập admin)'],
            ['support@'.self::DOMAIN, self::PASSWORD, 'SUPPORT'],
            ['billing@'.self::DOMAIN, self::PASSWORD, 'BILLING_ADMIN'],
        ]);
    }

    private function user(string $local, string $name, ?PlatformRole $role = null): User
    {
        $user = User::query()->firstOrNew(['email' => $local.'@'.self::DOMAIN]);
        $user->fill(['name' => $name, 'password' => self::PASSWORD]);
        $user->forceFill(['email_verified_at' => now(), 'platform_role' => $role])->save();

        return $user;
    }
}
