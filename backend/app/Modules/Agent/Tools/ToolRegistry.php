<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Tools;

use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * The closed list of tools the SEO Agent may use. There is no generic
 * "run SQL", "HTTP request", "shell" or "change settings" tool: each tool
 * maps to one existing, permission-checked module action. Billing,
 * subscriptions, API keys, members and agent policy are not reachable.
 *
 * min_level: lowest autonomy level at which the tool runs without a human
 * approval (below it → approval request; at level 0 → denied when it writes).
 */
final class ToolRegistry
{
    public const TOOLS = [
        'read_analytics' => ['min_level' => 0, 'permission' => 'workspace.view', 'writes' => false, 'content' => false, 'cost' => 0,
            'description' => 'Đọc số liệu Search Console đã đồng bộ, opportunity, decay, health score (chỉ đọc).'],
        'read_wp_content' => ['min_level' => 0, 'permission' => 'workspace.view', 'writes' => false, 'content' => false, 'cost' => 0,
            'description' => 'Đọc danh mục bài/trang đã đồng bộ từ WordPress (chỉ đọc).'],
        'analyze_site' => ['min_level' => 0, 'permission' => 'workspace.view', 'writes' => false, 'content' => false, 'cost' => 0,
            'description' => 'Tính lại phân loại nội dung, decay, opportunity và health score từ dữ liệu đã lưu.'],
        'run_seo_audit' => ['min_level' => 1, 'permission' => 'content.edit', 'writes' => true, 'content' => false, 'cost' => 0,
            'description' => 'Chạy SEO Audit (Phase 6) cho một bài; chỉ ghi kết quả audit.'],
        'create_proposal' => ['min_level' => 1, 'permission' => 'content.edit', 'writes' => true, 'content' => false, 'cost' => 0,
            'description' => 'Tạo đề xuất (recommendation) chờ người duyệt; không thay đổi website.'],
        'create_refresh_brief' => ['min_level' => 2, 'permission' => 'content.edit', 'writes' => true, 'content' => true, 'cost' => 400_000,
            'description' => 'Mở quy trình làm mới bài: kiểm tra WordPress, brief, AI đề xuất từng section (generate_draft), fact-check (run_fact_check). Luôn dừng ở bước người duyệt.'],
        'publish_via_workflow' => ['min_level' => 3, 'permission' => 'content.publish', 'writes' => true, 'content' => true, 'cost' => 0,
            'description' => 'Duyệt bước cập nhật WordPress cho nội dung ĐÃ được người duyệt, qua Publisher (mọi cổng kiểm tra chạy lại), trong khung giờ cho phép.'],
    ];

    /** Tools that are named in the spec but are steps of create_refresh_brief, not separate entry points. */
    public const PIPELINE_STEPS = ['generate_draft' => 'create_refresh_brief', 'run_fact_check' => 'create_refresh_brief', 'submit_for_approval' => 'create_refresh_brief'];

    public static function exists(string $tool): bool
    {
        return array_key_exists($tool, self::TOOLS);
    }

    /**
     * @return array{min_level: int, permission: string, writes: bool, content: bool, cost: int, description: string}
     */
    public static function get(string $tool): array
    {
        return self::TOOLS[$tool];
    }

    public static function permission(string $tool): Permission
    {
        return Permission::from(self::TOOLS[$tool]['permission']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::TOOLS as $name => $t) {
            $out[] = ['name' => $name] + $t;
        }

        return $out;
    }
}
