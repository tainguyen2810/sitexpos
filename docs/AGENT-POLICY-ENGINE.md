# Agent Policy Engine (Phase 8)

`backend/app/Modules/Agent/Policy/AgentPolicyEngine.php` — hàm thuần `evaluate(tool, AgentConfig, AgentPolicy, facts): Decision`. Chạy **phía server** cho mỗi tác vụ, hai lần: khi lập kế hoạch và **ngay trước khi thực thi** (cấu hình có thể đã đổi, ví dụ dừng khẩn cấp hoặc hết ngân sách). Không có cờ bỏ qua. UI chỉ hiển thị gợi ý (`needsApproval` trong `frontend/src/shared/api/agent.ts`), backend luôn quyết định.

## 1. Đầu vào
- `AgentConfig` (theo site, khách cấu hình): `enabled`, `autonomy_level`, `emergency_stop`, `allowed_actions`, `forbidden_actions`, `approval_requirements`, `max_daily_tasks`, `max_monthly_ai_spend_micro_usd`, `max_monthly_refreshes`, `publish_window_start/end`, `timezone`, `principal_user_id`, `level3_enabled_at`.
- `AgentPolicy` (một hàng nền tảng, Super Admin): `kill_switch` (+ lý do), `max_autonomy_level`, `forbidden_tools`, `max_tasks_per_run`, `max_concurrent_runs`, `task_timeout_seconds`, `run_timeout_minutes`.
- `facts` (`Support/PolicyFacts`): quyền hiện tại của principal trong workspace, quyền theo gói (`Billing\Contracts\Entitlements`), chi tiêu tháng, số task hôm nay, số refresh tháng, giờ hiện tại theo múi giờ site.

## 2. Thứ tự kiểm tra (dừng ở luật đầu tiên khớp)
| # | Điều kiện | Kết quả | Lý do |
|---|---|---|---|
| 1 | Kill switch nền tảng | deny | `platform_kill_switch` |
| 2 | Dừng khẩn cấp của site | deny | `emergency_stop` |
| 3 | Agent tắt | deny | `agent_disabled` |
| 4 | Tool không có trong registry | deny | `unknown_tool` |
| 5 | Nền tảng cấm tool | deny | `forbidden_by_platform` |
| 6 | Site cấm tool | deny | `forbidden_by_site` |
| 7 | Có danh sách cho phép và tool không nằm trong đó | deny | `not_in_allow_list` |
| 8 | Principal thiếu quyền của tool (ví dụ `content.publish`) | deny | `principal_lacks_permission` |
| 9 | Tool ghi và gói không bao gồm tính năng | deny | `plan_not_entitled` |
| 10 | Tool tốn phí và chi tiêu tháng + chi phí tool vượt ngân sách | deny | `monthly_budget_exceeded` |
| 11 | Tool ghi và vượt số task/ngày | deny | `daily_task_limit` |
| 12 | `create_refresh_brief` và đã đạt số refresh/tháng | deny | `monthly_refresh_limit` |
| 13 | Mức 0 và tool ghi | deny | `autonomy_level_0_read_only` |
| 14 | `publish_via_workflow` khi mức hiệu lực < 3 hoặc mức 3 chưa được người bật (`level3_enabled_by`) | deny | `publish_requires_level_3` |
| 15 | `publish_via_workflow` ngoài khung giờ | deny | `outside_publish_window` |
| 16 | Mức hiệu lực < `min_level` của tool | require_approval | `autonomy_level_N_requires_approval` |
| 17 | Site đặt `approval_requirements[tool] = true` | require_approval | `site_requires_approval` |
| — | Còn lại | allow | `policy_allows` |

## 3. Bất biến
- Mức 3 chỉ hiệu lực khi `level3_enabled_by/at` có giá trị (người xác nhận rõ ràng); `effectiveLevel = min(site, platform.max_autonomy_level)`.
- Agent không có tool nào đổi gói, billing, API key, thành viên hay quyền.
- Duyệt một tác vụ (`POST /agent/tasks/{id}/approve`) kiểm tra quyền **của người duyệt** (`approver_lacks_permission`) và chạy lại policy (`policy_denied` nếu nay bị chặn).
- Mỗi quyết định lưu `decision`, `decision_reasons`, `policy_snapshot` trên `agent_tasks` và ghi `agent_action_logs`.
- Nội dung đăng qua Agent vẫn qua Writer fact-check, SEO Audit và mọi cổng Publisher; không bao giờ ghi đè bài đã sửa trên WordPress (ADR-019).

## 4. Kiểm thử
`tests/Unit/Agent/AgentPolicyEngineTest.php` (thứ tự luật, mức 3, khung giờ qua nửa đêm, ngân sách) và `tests/Feature/Agent/AgentApiTest.php` (dừng khẩn cấp, kill switch, ngân sách, prompt injection, duyệt/từ chối).
