# SiteX Post — API Contracts

Phiên bản: `v1`. Mọi API trả JSON UTF-8. Thời gian ISO-8601 UTC (`2026-11-05T05:23:12Z`). ID là ULID.

| Bề mặt | Base URL | Xác thực |
|---|---|---|
| Customer API | `https://api.<domain>/api/v1` | Sanctum SPA cookie + CSRF |
| Admin API | `https://api.<domain>/api/admin/v1` | Sanctum SPA cookie + CSRF + `platform_role` + 2FA |
| Connector API (Cloud, plugin gọi) | `https://api.<domain>/connector/v1` | Chữ ký HMAC-SHA256 `SITEX-HMAC-V1` (SECURITY.md §6.2) |
| Plugin API (WP, Cloud gọi) | `https://<site>/wp-json/sitex-post/v1` (hoặc `?rest_route=`) | Chữ ký HMAC-SHA256, response cũng được ký |
| Webhooks | `https://api.<domain>/webhooks/{provider}` | Chữ ký provider |

## 1. Quy ước chung

### 1.1 Response thành công
```json
{ "data": { ... } }
```
Danh sách (cursor pagination):
```json
{
  "data": [ ... ],
  "meta": { "next_cursor": "eyJ...", "prev_cursor": null, "per_page": 25 }
}
```
`request_id` của response thành công nằm ở header `X-Request-Id` (không lặp trong body); response lỗi có thêm `error.request_id`.

Query: `?cursor=...&per_page=25` (tối đa 100), `?sort=-created_at`, `?filter[status]=published`, `?q=...`.

### 1.2 Lỗi
```json
{
  "error": {
    "code": "validation_failed",
    "message": "Dữ liệu không hợp lệ.",
    "details": { "email": ["Email đã được sử dụng."] },
    "request_id": "01JC..."
  }
}
```

| HTTP | `code` | Khi nào |
|---|---|---|
| 400 | `bad_request` | Request sai định dạng |
| 401 | `unauthenticated` | Chưa đăng nhập |
| 403 | `forbidden` | Đủ danh tính nhưng thiếu quyền |
| 403 | `email_not_verified`, `two_factor_required` | |
| 404 | `not_found` | Không tồn tại **hoặc thuộc tenant khác** |
| 409 | `conflict`, `invalid_state_transition` | Xung đột trạng thái |
| 402 | `quota_exceeded`, `subscription_required`, `subscription_inactive`, `plan_limit_reached`, `feature_unavailable`, `insufficient_credits` | Hết quota/gói/credit (Phase 7, chỉ khi Billing `enforcement = enforce`) |
| 419 | `csrf_mismatch` | CSRF hết hạn |
| 422 | `validation_failed` | Validate thất bại |
| 429 | `rate_limited` | Có header `Retry-After` |
| 500 | `server_error` | Không lộ chi tiết |
| 503 | `maintenance`, `provider_unavailable` | |

`message` dịch theo `Accept-Language` (`vi` mặc định).

### 1.3 Header
- Request: `Accept: application/json`, `Accept-Language: vi`, `X-XSRF-TOKEN`, `X-Requested-With: XMLHttpRequest`, tùy chọn `X-Request-Id` (chỉ nhận ULID/UUID/chuỗi an toàn, nếu không sẽ sinh mới). `Idempotency-Key` bắt buộc với endpoint tạo tác vụ tốn phí (Phase 3: `POST /workspaces/{ws}/ai/tasks`), xem §2.4.
- Response: `X-Request-Id`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`.

### 1.4 Tác vụ bất đồng bộ
Endpoint khởi chạy tác vụ dài trả `202`:
```json
{ "data": { "job_run": { "id": "01JC...", "status": "queued", "type": "research.industry" } } }
```
Theo dõi: `GET /workspaces/{ws}/job-runs/{id}` (polling 2–5s; WebSocket/SSE là tùy chọn phase sau).

### 1.5 Tenant
Mọi tài nguyên tenant nằm dưới `/workspaces/{workspace}`. Trường `workspace_id` gửi trong body bị cấm (`422`).

---

## 2. Customer API (`/api/v1`)

Ký hiệu quyền: **O**wner, **A**dmin, **E**ditor, **V**iewer. ✦ = có trong Phase 1.

### 2.1 Auth & tài khoản (Fortify + Sanctum) ✦
Endpoint auth do Fortify cung cấp (headless) dưới tiền tố `/api/v1/auth`. Đăng nhập dùng cookie session (Sanctum SPA), không có token trong localStorage.

| Method | Path | Mô tả |
|---|---|---|
| GET | `/sanctum/csrf-cookie` (gốc, không `/api/v1`) | Lấy cookie `XSRF-TOKEN` |
| POST | `/auth/register` | `{name, email, password, password_confirmation, locale?}` → 201, tự đăng nhập, gửi email xác minh |
| POST | `/auth/login` | `{email, password, remember?}` → `{two_factor: false}` hoặc `{two_factor: true}`. Rate limit 5/phút (email+IP), 60/giờ (IP) |
| POST | `/auth/two-factor-challenge` | `{code}` hoặc `{recovery_code}` |
| POST | `/auth/logout` | 204 |
| POST | `/auth/forgot-password` | `{email}` → luôn 200 với thông điệp chung (chống dò email) |
| POST | `/auth/reset-password` | `{token, email, password, password_confirmation}`; đăng xuất mọi phiên |
| POST | `/auth/email/verification-notification` | Gửi lại email xác minh |
| GET | `/auth/email/verify/{id}/{hash}?expires&signature` | URL ký; email trỏ tới SPA `FRONTEND_URL/verify-email?link=...`, SPA gọi URL này |
| PUT | `/auth/user/password` | `{current_password, password, password_confirmation}`; đăng xuất các phiên khác |
| POST | `/auth/user/confirm-password` | `{password}` — cần trước các thao tác 2FA (nếu chưa xác nhận: 423) |
| POST/DELETE | `/auth/user/two-factor-authentication` | Bật (chưa xác nhận) / tắt 2FA |
| POST | `/auth/user/confirmed-two-factor-authentication` | `{code}` xác nhận 2FA |
| GET | `/auth/user/two-factor-qr-code` · `/auth/user/two-factor-secret-key` | |
| GET/POST | `/auth/user/two-factor-recovery-codes` | Xem / tạo lại mã khôi phục |
| GET | `/me` | User + workspace + phiên hỗ trợ đang mở (nếu có) |
| PATCH | `/me` | `{name?, locale?, timezone?}` |
| GET | `/me/sessions` | Danh sách phiên; `id` là handle sha256, không phải session id thật. `meta.supported=false` nếu `SESSION_DRIVER` ≠ `database` |
| DELETE | `/me/sessions/{id}` | Đăng xuất một thiết bị |
| DELETE | `/me/sessions/others` | `{password}` — đăng xuất mọi thiết bị khác |

`GET /me` response:
```json
{
  "data": {
    "id": "01JC...", "name": "Tài", "email": "a@b.com", "email_verified": true,
    "locale": "vi", "timezone": "Asia/Ho_Chi_Minh", "two_factor_enabled": false,
    "platform_role": null, "platform_permissions": [], "status": "active",
    "workspaces": [ { "id": "01jd...", "name": "Shop A", "slug": "shop-a", "role": "OWNER", "status": "active", "is_demo": false } ],
    "impersonation": null
  }
}
```

### 2.2 Workspace ✦
| Method | Path | Quyền | Mô tả |
|---|---|---|---|
| GET | `/workspaces` | user | Workspace của tôi |
| POST | `/workspaces` | user đã xác minh email | `{name, timezone?, locale?}` → 201, người tạo là OWNER |
| GET | `/workspaces/{ws}` | OAEV | Chi tiết + `my_role` + `permissions[]` |
| PATCH | `/workspaces/{ws}` | OA | `{name?, timezone?, locale?}` |
| DELETE | `/workspaces/{ws}` | O | `{confirm_name, password}` → `pending_deletion` (workspace bị khóa ngay, xóa vĩnh viễn ở phase sau) |
| POST | `/workspaces/{ws}/transfer-ownership` | O | `{member_id, password}` |
| GET | `/workspaces/{ws}/members` | OAEV | |
| PATCH | `/workspaces/{ws}/members/{member}` | OA | `{role}` (không thành OWNER) |
| DELETE | `/workspaces/{ws}/members/{member}` | OA hoặc chính mình (rời) | OWNER không thể rời |
| GET | `/workspaces/{ws}/invitations` | OA | |
| POST | `/workspaces/{ws}/invitations` | OA | `{email, role}` |
| DELETE | `/workspaces/{ws}/invitations/{inv}` | OA | Thu hồi |
| GET | `/invitations/{token}` | công khai (rate limit) | Xem thông tin lời mời |
| POST | `/invitations/{token}/accept` | user đăng nhập, email khớp | |
| GET | `/workspaces/{ws}/audit-logs` | OA | Lọc theo action, actor, thời gian |
| GET | `/workspaces/{ws}/job-runs/{id}` | OAEV | Trạng thái tác vụ |
| GET | `/workspaces/{ws}/dashboard` | OAEV | Phase 1: số thành viên, lời mời đang chờ, checklist onboarding, module sắp có (không có số liệu giả) |

Quy tắc tenant (Phase 1, có test):
- `{ws}` không phải workspace của user → **404** (không lộ sự tồn tại). Tài nguyên con (`{member}`, `{invitation}`) của workspace khác → 404.
- Workspace `suspended` → 403 `workspace_suspended`; `pending_deletion` → 404.
- Phiên hỗ trợ (impersonation) → vai trò ADMIN chỉ xem; mọi request không phải GET → 403 `impersonation_read_only`.
- Body chứa `workspace_id`, `tenant_id`, `user_id`, `owner_id`, `platform_role` → 422.

Workspace response:
```json
{
  "data": {
    "id": "01JD...", "name": "Shop A", "slug": "shop-a", "type": "standard",
    "locale": "vi", "timezone": "Asia/Ho_Chi_Minh", "status": "active",
    "my_role": "OWNER",
    "permissions": ["workspace.update", "members.manage", "sites.manage", "billing.manage"],
    "created_at": "2026-11-05T05:23:12Z"
  }
}
```

### 2.3 Sites (Phase 2) ✦
Quyền: O=OWNER, A=ADMIN, E=EDITOR, V=VIEWER. `{site}` luôn được tra trong workspace của route (scoped binding) → site của workspace khác trả 404.

| Method | Path | Quyền | Ghi chú |
|---|---|---|---|
| GET | `/workspaces/{ws}/sites` | OAEV | Kèm `counts.content`, `counts.taxonomies` |
| POST | `/workspaces/{ws}/sites` | OA | `{name, url, content_language?}` → 201, site `pending_pairing`; `meta.pairing_code`, `meta.pairing_expires_at`, `meta.cloud_url` (mã chỉ trả một lần). URL phải HTTPS công khai (SsrfGuard) và chưa có trong workspace |
| GET | `/workspaces/{ws}/sites/{site}` | OAEV | `SiteResource`: status, versions, publish_author, features, issues, last_error, các mốc thời gian |
| PATCH | `/workspaces/{ws}/sites/{site}` | OA | `{name?, content_language?, settings?: {default_post_status, default_category_id}}` |
| DELETE | `/workspaces/{ws}/sites/{site}` | OA | Thu hồi khóa rồi **xóa cứng** site và dữ liệu con (bài trên WP giữ nguyên) |
| POST | `/workspaces/{ws}/sites/{site}/pairing-code` | OA | Cấp mã mới (vô hiệu mã cũ) → `{pairing_code, expires_at, cloud_url}` |
| POST | `/workspaces/{ws}/sites/{site}/health-check` | OAE | 202 `{job_run}` |
| POST | `/workspaces/{ws}/sites/{site}/sync` | OAE | 202 `{job_run}` — đồng bộ chuyên mục, thẻ, bài, trang (+ sản phẩm, danh mục sản phẩm nếu có WooCommerce) |
| POST | `/workspaces/{ws}/sites/{site}/test-publish` | OA | 202 `{job_run}` — tạo/cập nhật **một** bài nháp thử (2 ảnh, SEO noindex), idempotent |
| POST | `/workspaces/{ws}/sites/{site}/rotate-keys` | OA | 200 site; 502 `rotation_failed` nếu plugin không xác nhận (khóa cũ giữ nguyên) |
| POST | `/workspaces/{ws}/sites/{site}/revoke` | OA | 200 site + `meta.plugin_notified`; 409 nếu đã ngắt |
| GET | `/workspaces/{ws}/sites/{site}/taxonomies?filter[taxonomy]=` | OAEV | Tối đa 1000 term |
| GET | `/workspaces/{ws}/sites/{site}/content?filter[post_type]=&q=&cursor=` | OAEV | Cursor pagination; `wp_post_id`, `url`, `is_managed`, `product` |
| GET | `/workspaces/{ws}/sites/{site}/logs?filter[level]=&cursor=` | OAEV | Nhật ký kết nối (không chứa secret/payload) |
| GET | `/workspaces/{ws}/sites/{site}/health-checks` | OAEV | 50 lần kiểm tra gần nhất |
| GET | `/workspaces/{ws}/job-runs/{id}` | OAEV | Trạng thái tác vụ nền (`queued/running/succeeded/failed`, `error`) |

Thao tác 202 trên site chưa kết nối → `409 not_connected`.

### 2.4 Tác vụ AI & sử dụng AI (Phase 3) ✦
| Method | Path | Quyền | Ghi chú |
|---|---|---|---|
| GET | `/workspaces/{ws}/ai/tasks?status=&cursor=` | OAEV | Danh sách workflow run của workspace (mới nhất trước) |
| POST | `/workspaces/{ws}/ai/tasks` | OAE + email đã xác minh | Body `{prompt (2–4000), structured?: bool, web_search?: bool}`; header **`Idempotency-Key`** bắt buộc (8–120 ký tự `[A-Za-z0-9_\-:.]`). Trả **202** `{data: {workflow_run, job_run}}` khi tạo mới; **200** cùng run khi lặp lại key với cùng nội dung; **409 `idempotency_conflict`** khi cùng key nhưng nội dung khác. Throttle `ai-tasks`. |
| GET | `/workspaces/{ws}/ai/tasks/{run}` | OAEV | Trạng thái (`pending/running/retry_scheduled/succeeded/failed/canceled`), các bước, sự kiện, `output` (text/json, token, `cost_micro_usd`), `error_code` |
| POST | `/workspaces/{ws}/ai/tasks/{run}/cancel` | OAE | 409 `invalid_state_transition` nếu đã kết thúc |
| POST | `/workspaces/{ws}/ai/tasks/{run}/retry` | OAE | Chỉ với `failed`/`canceled`; mở chu kỳ mới → 202 |
| GET | `/workspaces/{ws}/ai/usage` | OAEV | Tổng request/token/ảnh/lượt tìm kiếm/chi phí + trạng thái các ngân sách đang bật của workspace (đã dùng, đang giữ chỗ, giới hạn, hành động) |

HTTP không bao giờ gọi provider AI trực tiếp: tác vụ chạy trên queue (`writing`/`research`/`factcheck` theo route). Lỗi nghiệp vụ AI trả qua `error_code` của run, ví dụ `budget_exceeded`, `no_model_available`, `rate_limited`, `server_error`, `connect_failed`, `response_timeout`, `invalid_output`, `refused`, `input_too_large`, `interrupted_paid_call`, `duplicate_call`, `deadline_exceeded`.

### 2.4b Billing, credit & thanh toán (Phase 7) ✦
Tiền tố `/workspaces/{ws}/billing`. Quyền: `billing.view` (mọi thành viên) cho GET; `billing.manage` (chỉ **O**wner) cho mọi thao tác ghi. Tiền `*_minor` + `currency`, credit `*_mc` (1 credit = 1000 mc). Endpoint tạo đơn/trial/đổi gói cần email đã xác minh và giới hạn `throttle:billing-orders`. Trạng thái thanh toán **không bao giờ** nhận từ client. Chi tiết: `BILLING-ARCHITECTURE.md`.

| Method | Path | Mô tả |
|---|---|---|
| GET | `/plans` | Gói đang bán (version hiện hành, giới hạn, tính năng, `trial.eligible/reason`, `is_demo`) + `providers[]` thanh toán đang bật |
| GET | `/subscription` | Gói hiện tại, `effective` (nguồn giới hạn, `enforcing`, limits, features, `paid_usage_allowed`), thay đổi đã lên lịch, đơn đang chờ |
| GET | `/subscription/history` | `subscription_events` (phân trang) |
| POST | `/subscription/trial` | `{plan_code}` → 201. Lỗi: `trial_disabled`, `trial_already_used`, `trial_not_eligible`, `already_subscribed` |
| POST | `/subscription/free` | `{plan_code}` — chỉ gói `is_free` (`payment_required` nếu không) |
| POST | `/subscription/checkout` | `{plan_code, interval: month\|year, provider?, idempotency_key?}` → 201 đơn `PENDING` kèm `instructions` (VietQR). Cùng khóa → cùng đơn |
| POST | `/subscription/quote` | `{plan_code, interval}` → `{kind: upgrade\|downgrade, amount_minor, effective_at, new_period_end, proration?, credits_policy}` |
| POST · DELETE | `/subscription/change` | Đổi gói theo quote (nâng cấp có tiền → `order`); DELETE hủy thay đổi đã lên lịch |
| POST | `/subscription/cancel` · `/subscription/resume` | Hủy cuối kỳ / bỏ hủy |
| POST | `/subscription/renew` | Tạo đơn gia hạn (`cannot_renew` nếu không áp dụng) |
| PATCH | `/subscription/settings` | `{renewal_reminders: bool}` |
| GET | `/usage` | Giới hạn & đã dùng theo tài nguyên + chi phí/credit AI theo kỳ |
| GET | `/credits` | `{wallet: {balance_mc, held_mc, available_mc, expiring_soon[]…}, grants[]}` |
| GET | `/credits/transactions` | Sổ cái credit (phân trang, lọc `entry_type`) |
| GET | `/credits/packages` | Gói credit đang bán |
| POST | `/credits/purchase` | `{package_code, provider?, idempotency_key?}` → 201 đơn |
| GET | `/orders` · `/orders/{id}` | Đơn thanh toán; chi tiết kèm `instructions` (ngân hàng, số TK, tên, số tiền, `memo` = mã `SXP…`, `qr_payload`, `confirmation`) |
| POST | `/orders/{id}/transferred` | Khách báo đã chuyển → `PROCESSING` (không kích hoạt gì) |
| POST | `/orders/{id}/cancel` | Hủy đơn chưa trả |
| GET | `/documents` · `/documents/{id}` | Chứng từ nội bộ (biên nhận, chứng từ hoàn tiền) — `is_tax_invoice: false` |
| GET · PUT | `/profile` | Hồ sơ thanh toán (công ty, MST, địa chỉ, email nhận chứng từ) |

`workspace_id` trong body → 422; đơn/chứng từ của workspace khác → 404.

### 2.5 SEO Planner (Phase 4) ✦

Tiền tố: `/workspaces/{ws}/research`. Quyền: xem = mọi thành viên; thao tác (`operate`) = Owner/Admin/Editor; Search Console & xóa chiến dịch = quyền `sites.manage`; impersonation chỉ xem. Danh sách dùng cursor (`?cursor=`), mặc định 25–50/trang. Tham số con dạng `{x:id}` được scope theo chiến dịch (404 nếu không thuộc).

| Method | Path | Ghi chú |
|---|---|---|
| GET | `/usage` | quota tháng, giới hạn hiệu lực, nguồn khả dụng (không lộ cấu hình bí mật) |
| GET · POST | `/notifications` · `/notifications/{id}/read` | `meta.unread` |
| GET | `/calendar?from=&to=&campaign_id=&site_id=&cluster_id=&status=` | lịch toàn workspace |
| GET · POST | `/campaigns` | POST cần site đã xác minh; 429 `research_quota_exceeded` khi vượt `max_campaigns` |
| GET · PATCH · DELETE | `/campaigns/{c}` | |
| GET | `/campaigns/{c}/overview` | |
| GET · PATCH | `/campaigns/{c}/settings` | `ideas_per_run` ≤ `max_ideas_per_run` (422) |
| GET · PUT | `/campaigns/{c}/schedule` | `{is_enabled, frequency, day_of_week?, day_of_month?, hour_utc}` |
| GET | `/campaigns/{c}/intelligence` | `{profile, knowledge[]}` |
| POST · PATCH · DELETE | `/campaigns/{c}/intelligence/knowledge[/{id}]` | DELETE = đánh dấu `rejected` (giữ lại để AI không thêm lại) |
| GET · POST | `/campaigns/{c}/runs` | POST → 202 `{research_run, job_run}`; header `Idempotency-Key`; 409 `research_in_progress`; 429 quota; throttle 6/phút |
| GET | `/campaigns/{c}/runs/{id}` · `/snapshots` | `sources[]` có `url`, `status`, `robots_allowed`, `fetched_at` |
| POST | `/campaigns/{c}/runs/{id}/cancel` · `/retry` | |
| GET | `/campaigns/{c}/keywords?intent=&status=&source=&cluster_id=&topic=&min_score=&has_metrics=1&q=&sort=score|keyword|created&direction=` | `metrics.search_volume/difficulty/cpc_micro_usd` = `null` nếu không có nguồn thật; `metrics.provider{source, collected_at}` |
| POST · PATCH | `/campaigns/{c}/keywords` · `/keywords/{id}` · POST `/keywords/bulk` `{ids, status}` | |
| GET · PATCH | `/campaigns/{c}/ideas[/{id}]` (lọc `status, search_intent, content_type, priority, source, cluster_id, research_run_id, unscheduled=1, q`) | |
| POST | `/campaigns/{c}/ideas/{id}/brief` | dựng Content Brief từ dữ liệu đã lưu, không gọi AI |
| GET · PATCH | `/campaigns/{c}/clusters[/{id}]` · `/gaps[/{id}]` · GET `/internal-links?cluster_id=` | link có `from/to {type, id, label}` |
| GET · POST · PATCH · DELETE | `/campaigns/{c}/competitors[/{id}]` · GET `/competitors/{id}/findings` | URL qua SsrfGuard; 422 nếu trỏ tới mạng nội bộ hoặc chính website; 429 khi vượt `max_competitors_per_campaign` |
| GET · POST | `/campaigns/{c}/calendar` | 409 `already_scheduled` |
| PATCH · DELETE | `/campaigns/{c}/calendar/{id}` | kéo thả = PATCH `scheduled_for`, `position`; `status` `proposed|approved|paused|canceled|done` |
| POST | `/campaigns/{c}/calendar/auto-plan` | `{scheduled, skipped_limit}` — chỉ xếp lịch, không tạo bài |
| GET · DELETE | `/campaigns/{c}/search-console` | trạng thái kết nối (không có token) |
| POST | `/campaigns/{c}/search-console/connect` | `{authorization_url}`; 409 `gsc_not_configured` |
| GET · PUT | `/campaigns/{c}/search-console/properties` · `/property` | |
| GET | `/api/v1/oauth/google/callback` | công khai, throttle 20/phút/IP, state ký + hết hạn, redirect về UI `?gsc=` |

### 2.5b AI Writer (Phase 5) ✦
Tiền tố `/api/v1/workspaces/{ws}/writer`. Danh sách dùng envelope cursor `{data, meta: {next_cursor, prev_cursor, per_page}}`. Route có `throttle:writer-ai` giới hạn 20/phút; autosave 30/phút. POST tạo bài/lô/khởi chạy workflow nhận header `Idempotency-Key`. Không lời gọi AI nào chạy trong request: các POST khởi chạy trả 202 kèm workflow run. Quyền: xem = thành viên; sửa = `content.edit`; duyệt bài, xác minh claim CRITICAL = `content.publish`.

| Method | Path | Ghi chú |
|---|---|---|
| GET | `/overview` · `/options` · `/ideas` · `/estimate` | Tổng quan, lựa chọn wizard (gồm `auto_approve {enabled, min_quality}`), ý tưởng Phase 4, ước tính chi phí |
| GET | `/history?kind=&status=&article_id=&cursor=` | Lịch sử workflow của workspace, mỗi dòng kèm `article {id, title, status}` |
| GET · POST · GET · PATCH · DELETE | `/presets` · `/presets/{p}` | `presets` trả `{data: [...]}` (không phân trang). Mỗi fact của brand voice có `provenance` |
| POST | `/presets/{p}/learn-style` | v1.0.5. Body `{site_id?, content_item_ids?[], samples?[{title?, text}]}` (tổng 2–20 bài) → **202** `{data: {job_run}}`. Kết quả vào `style_draft`; `PATCH /presets/{p}` với `style_profile` để áp dụng |
| DELETE | `/presets/{p}/style-draft` | Bỏ bản phân tích chờ duyệt, trả preset |
| GET | `/batches?status=queued\|running\|paused\|completed\|canceled\|failed` · `/batches/{b}` | |
| POST | `/batches/preview` · `/batches` | Tối đa `max_batch_size` bài; cần email đã xác minh |
| POST | `/batches/{b}/pause` · `/resume` · `/cancel` · `/retry` | |
| GET | `/articles?q=&status=&content_type=&site_id=&campaign_id=&needs_review=&sort=&direction=` | `q` tìm theo tiêu đề |
| POST | `/articles` | Tạo từ wizard (10 `content_type`, `review_mode: step_by_step\|brief_only\|final_only`, `approval_mode: manual\|auto` (auto cần Super Admin bật), `research_depth`, `budget_limit_micro_usd`…) |
| GET · PATCH | `/articles/{a}` | PATCH: `title`, `review_mode`, `approval_mode`, `budget_limit_micro_usd`, `writing_preset_id` (không sửa được status/nội dung/chi phí qua đây) |
| POST | `/articles/{a}/cancel` | |
| GET · POST | `/articles/{a}/workflows` | POST `{kind: research\|outline\|draft\|recheck}`; 429 `writer_concurrency_limit` khi vượt giới hạn đồng thời |
| POST | `/articles/{a}/workflows/resume` · `/workflows/cancel` | Resume sau FAILED/hết ngân sách |
| GET | `/articles/{a}/research` · `/evidence` | Hồ sơ nghiên cứu, nguồn (trạng thái tải, lý do bị chặn), bằng chứng nguyên văn |
| GET · POST | `/articles/{a}/brief` · `/brief/approve` | Approve nhận brief đã sửa |
| GET · POST | `/articles/{a}/outline` · `/outline/approve` | Approve nhận sections (heading nullable cho phần mở bài, `word_range`, `required_facts[].evidence_refs`, `subheadings`, `internal_links`) |
| GET | `/articles/{a}/editor` | Nội dung hiện tại + `lock_version` |
| PUT | `/articles/{a}/autosave` | 409 khi xung đột phiên bản |
| GET · POST | `/articles/{a}/versions` | Lưu phiên bản |
| GET | `/articles/{a}/versions/{v}` · `/diff?from=&to=` | |
| POST | `/articles/{a}/versions/{v}/restore` | |
| GET · POST | `/articles/{a}/revisions` | POST = yêu cầu AI viết lại đoạn/section → đề xuất |
| GET · POST | `/articles/{a}/revisions/{r}` · `/apply` · `/discard` | |
| GET | `/articles/{a}/claims` · `/claims/{c}` | |
| POST | `/articles/{a}/claims/{c}/verify` | `{verdict, note (≥10), evidence_ids?, reference_url?}`; VERIFIED/CONTRADICTED cần bằng chứng |
| GET | `/articles/{a}/fact-checks` · `/quality` | |
| POST | `/articles/{a}/quality/audit` | Chấm lại rubric cho phiên bản hiện tại |
| GET | `/articles/{a}/approval` | Checklist `{ok, blocking[], warnings[], stats}` |
| POST | `/articles/{a}/approve` `{version_id, acknowledge_high_risk?, note?}` · `/request-changes` `{note}` | 422 khi còn mục chặn; `version_id` phải là phiên bản hiện tại |
| POST | `/articles/{a}/duplicate-decision` | `{decision: create_new\|update_existing\|merge\|reject, note?}` |
| GET | `/articles/{a}/usage` | Chi phí AI theo bước |

### 2.6 Media, SEO & Publishing (Phase 6) ✦

Tiền tố `/workspaces/{ws}`. `{articleRef}` = id bài Writer (Phase 5) của workspace — bài của workspace khác trả 404. Quyền: đọc = `workspace.view`; ghi = `content.edit`; đánh dấu **P** = `content.publish`; **S** = `sites.manage`. Tác vụ dài trả **202 + `job_run`**. Chi tiết: `MEDIA-ENGINE.md`, `SEO-AUDIT-RULES.md`, `WORDPRESS-PUBLISHER.md`.

**Media** (`/media`)
| Method | Path | Ghi chú |
|---|---|---|
| GET | `/assets` | Lọc `site_id`, `source_type`, `ai=1`, `status`, `license`, `q`; cursor. URL file là URL ký hạn 30 phút |
| POST | `/assets` | multipart `file`, `license` (`owned`/`customer_provided`/`licensed_stock`), alt?, caption?, site_id? → 202 (xử lý trong queue `media`). Sai MIME / file ngụy trang → 422 |
| GET/PATCH/DELETE | `/assets/{asset}` | PATCH: title, alt_text, caption. Ảnh AI không bỏ được nhãn AI |
| POST | `/assets/{asset}/derive` | Cắt / đổi kích thước → bản sao mới (201) |
| GET | `/sources` · POST `/sources/sync` `{site_id}` | Đồng bộ Media Library / ảnh WooCommerce (tham chiếu) → 202 |
| POST | `/infographics` | Dữ liệu có cấu trúc + `sources[]` hoặc `customer_provided: true` → 201 |
| GET | `/usage` | Dung lượng, lượt tải / ảnh AI hôm nay so với giới hạn |
| POST | `/generations/preview` | Prompt đề xuất + ước tính chi phí + model khả dụng (không gọi AI) |
| GET/POST | `/generations` | POST: article_id?, image_type, prompt (đã sửa), aspect_ratio, style, quality, image_count, budget_cap → 202. Không có model `image_generation` → 422 `no_image_model` |
| GET | `/generations/{job}` · POST `/generations/{job}/regenerate` | Regenerate → phiên bản mới, 202 |
| GET | `/articles/{articleRef}/placements` · POST `/placements/suggest` · PUT `/placements` | Ảnh AI vào ô `product` → 422 `ai_not_product_photo`; license `unknown` → 422 `license_unknown`. Vượt hạn mức media → 429; ảnh AI bị tắt → 403 `ai_images_disabled` |
| GET | `/api/v1/media-files/{ws}/{asset}/{variant}` | Không phiên đăng nhập; chữ ký URL là quyền truy cập; hết hạn → 403 |

**SEO** (`/seo`)
| Method | Path | Ghi chú |
|---|---|---|
| GET | `/articles` | Bài kèm điểm audit gần nhất |
| GET · POST `/generate` · PUT | `/articles/{articleRef}/metadata` | PUT cần `lock_version` (409 `stale_metadata` khi lệch). Canonical khác domain / noindex khi website chưa cho phép → 422 `canonical_requires_policy` / `robots_requires_policy` |
| GET · POST | `/articles/{articleRef}/audits` | POST chạy audit đồng bộ (201) |
| POST | `/articles/{articleRef}/audits/link-check` | Kiểm tra link ngoài qua SsrfGuard → 202 |
| GET | `/audits/{auditRun}` | Kết quả + issues (rule, severity, message, explanation, fix, context) |
| GET | `/articles/{articleRef}/schema` | JSON-LD sẽ chèn + loại bị bỏ qua và lý do |
| GET · POST `/suggest` | `/articles/{articleRef}/links` | Gợi ý link nội bộ |
| PATCH | `/links/{suggestion}` `{status: accepted\|rejected}` | |
| GET · PUT (**S**) | `/sites/{site}/config` | Cấu hình schema + cho phép canonical/robots |

**Publishing** (`/publishing`)
| Method | Path | Ghi chú |
|---|---|---|
| GET | `/articles` · `/articles/{articleRef}/preview` | Preview = HTML render + snippet SEO, gắn nhãn mô phỏng |
| GET · POST | `/jobs` | POST `{article_id, mode: wp_draft\|publish_now\|scheduled\|update, scheduled_at?, approve?}` + `Idempotency-Key` (8–120 ký tự). `approve: true` cần **P**. Trùng job hoạt động → 409 `active_job_exists` |
| GET | `/jobs/{job}` · `/jobs/{job}/html` | Kết quả từng cổng, lần thử, HTML đã gửi |
| POST | `/jobs/{job}/recheck` | Chạy lại cổng |
| POST (**P**) | `/jobs/{job}/approve` `{scheduled_at?}` · `/reschedule` `{scheduled_at}` · `/retry` (202) | Cổng không đạt → 422 `gates_failed` (`details.gates` = kết quả từng cổng); chuyển trạng thái sai → 409 `invalid_transition` |
| POST | `/jobs/{job}/cancel` | Bài đã có trên WP không bị xóa |
| GET | `/calendar?from&to&site_id` | Lịch đăng + mục lịch nội dung Phase 4, theo múi giờ website |
| GET | `/conflicts` · `/conflicts/{c}` · POST (**P**) `/conflicts/{c}/resolve` `{resolution, note?}` | resolution: `keep_wordpress`, `overwrite`, `merge_manually`, `new_draft`, `cancel_update`; đã giải quyết → 409 |
| GET | `/mappings` · POST `/mappings/{m}/check` (202) | Kiểm tra đồng bộ với WordPress |
| GET · PUT (**S**) | `/sites/{site}/policy` | Bật auto-publish cần `confirm_auto_publish: true` (422 nếu thiếu) và nền tảng cho phép; có audit |
| GET · POST (**P**) | `/batches` · POST `/batches/preview` | Bulk: tối đa `max_bulk_items`; `Idempotency-Key` |
| GET · POST (**P**) | `/batches/{b}` · `/pause` · `/resume` · `/cancel` | |

### 2.7 Analytics (Phase 8) ✦
Tiền tố `/workspaces/{ws}/analytics`. Đọc: mọi thành viên (`workspace.view`). Kết nối / property / đồng bộ / cài đặt: `sites.manage`. Duyệt cơ hội, decay, đề xuất, tạo/duyệt refresh, experiment, báo cáo: `content.edit`; đăng refresh: `content.publish`. Bộ lọc chung: `site_id`, `from`, `to`, `search_type`, `device` (`desktop|mobile|tablet`), `country` (ISO-3 thường, ví dụ `vnm`), `q`, `sort`, `mapped_only`. Mọi response danh sách có `meta.latest_date` và ghi chú độ trễ GSC. Xem `ANALYTICS-ARCHITECTURE.md`, `GSC-INTEGRATION.md`.

| Method | Path | Mô tả |
|---|---|---|
| GET | `/sites` | Site + trạng thái Search Console, property Analytics, health mới nhất |
| GET | `/sites/{site}/connection` | Trạng thái chi tiết (`oauth_configured`, `search_console`, `analytics`, `ga4.available=false`) |
| POST | `/sites/{site}/connect` | → `{url}` OAuth Google (scope `webmasters.readonly`) |
| GET | `/sites/{site}/properties` | Property truy cập được |
| PUT | `/sites/{site}/property` | `{property_url}`; 422 `property_unknown` nếu không thuộc danh sách |
| DELETE | `/sites/{site}/connection` | Thu hồi token tại Google + xóa token (`revoked_at_google`) |
| POST | `/sites/{site}/sync` | `{kind: incremental\|backfill}` + `Idempotency-Key` → 202 `{run}`; 409 `sync_in_progress` |
| GET | `/sync-runs` | Lịch sử đồng bộ (`status`, `rows_upserted`, `api_requests`, `error_code`, `next_retry_at`) |
| GET | `/overview` · `/queries` · `/pages` · `/dimensions` | Dashboard, so sánh với kỳ trước |
| GET | `/articles` · `/articles/{article}` | Bài đã duyệt + hiệu suất theo URL khớp chính xác; chi tiết: chuỗi ngày, truy vấn, URL đã khớp (`source`), snapshot, refresh, experiment |
| GET | `/content` · `/clusters` | Phân loại nội dung (`classification`, `labels`, `reasons`) · cụm chủ đề |
| GET | `/opportunities[/{id}]` · PATCH `/opportunities/{id}` | Lọc `rule`, `severity`, `status`; PATCH `{status: open\|accepted\|dismissed\|resolved, note?}` |
| GET | `/decay` · PATCH `/decay/{id}` | `{status: DECAY_SUSPECTED\|NEEDS_INVESTIGATION\|CONFIRMED_ISSUE\|NO_ACTION, note?}` |
| GET | `/recommendations` · PATCH `/recommendations/{id}` | Lọc `status`, `source`, `type`; PATCH `{status: approved\|rejected\|dismissed, note?}` |
| GET · POST | `/refresh-jobs` | Tạo `{article_id, recommendation_id?}` + `Idempotency-Key` → 202; 409 `refresh_in_progress`, `refresh_requires_approved_article`, `refresh_not_published` |
| GET | `/refresh-jobs/{id}` | Brief, diff theo section, fact-check, SEO audit, kiểm tra WordPress |
| POST | `/refresh-jobs/{id}/approve` · `/publish` · `/cancel` · `/poll` | Duyệt (`content.edit`), đăng qua Publisher (`content.publish`, 422 `gates_failed`, 409 `wordpress_changed`), hủy, tiến độ |
| GET · POST | `/experiments` · GET `/experiments/{id}` · POST `/experiments/{id}/cancel` | Theo dõi trước/sau (`method = before_after`) |
| GET | `/health` | Điểm hiện tại + lịch sử + thành phần `{value, weight, available, detail}` |
| GET · POST | `/reports` · GET `/reports/{id}` · GET `/reports/{id}/csv` | `{period_type: weekly\|monthly\|custom, period_start?, period_end?}` → 202 |
| POST | `/reports/{id}/email` | `{recipient_ids[]}` — chỉ thành viên đang hoạt động; người khác → 403 `recipient_not_member` |
| GET · POST | `/copilot` · GET `/copilot/{id}` | Câu hỏi; trả `answer.{facts, inferences, recommendations}`, `data_refs`, `data_freshness`, `mode: rules\|ai_pending\|ai`; không thực thi |
| GET · PUT | `/settings` | Ngưỡng quy tắc, trọng số health, `backfill_days`, `raw_retention_days` |

### 2.8 SEO Agent (Phase 8) ✦
Tiền tố `/workspaces/{ws}/agent`. Xem `AUTONOMOUS-SEO-AGENT.md`, `AGENT-POLICY-ENGINE.md`.

| Method | Path | Quyền | Mô tả |
|---|---|---|---|
| GET | `/tools` | view | Registry: `{name, min_level, permission, writes, content, cost, description}` |
| GET · PUT | `/sites/{site}/config` | view · `sites.manage` | Cấu hình; lên mức 3 bắt buộc `confirm_level3: true` (409 `level3_confirmation_required`) |
| POST | `/sites/{site}/emergency-stop` | dừng: `content.edit` · bỏ dừng: `sites.manage` | `{stop: bool}` — dừng hủy mọi run/task mở |
| POST | `/sites/{site}/runs` | `content.edit` | `{kind}` + `Idempotency-Key` → 202; 409 `agent_run_active`, `agent_disabled`, `agent_emergency_stop`, `agent_platform_kill_switch`, `agent_busy` |
| GET | `/runs[/{id}]` | view | Run + tasks + phase |
| POST | `/runs/{id}/pause` · `/resume` · `/cancel` | `content.edit` | |
| GET | `/tasks` | view | Mặc định `status=awaiting_approval` |
| POST | `/tasks/{id}/approve` · `/reject` · `/retry` | `content.edit` (+ quyền của tool) | `{note?}`; 403 `approver_lacks_permission`, 409 `policy_denied` |
| GET · POST | `/sites/{site}/schedules` · PATCH `/schedules/{id}` | view · `sites.manage` | Lịch theo múi giờ site; trả `next_run_at`, `preview[]` |
| GET | `/logs` · `/memories` | view | Nhật ký chỉ ghi thêm; bộ nhớ có `expires_at` |

---

## 3. Admin API (`/api/admin/v1`)

Yêu cầu `platform_role` + 2FA đã xác nhận. User thường nhận **404** (không lộ khu admin); staff chưa bật 2FA nhận 403 `two_factor_required`. Mọi thao tác ghi cần `reason` (5–500 ký tự) và được ghi audit. ✦ = Phase 1.

| Method | Path | Vai trò |
|---|---|---|
| GET | `/dashboard` ✦ | Tất cả — Phase 1: tổng user, workspace, đăng ký mới 7/30 ngày |
| GET | `/workspaces` ✦ · `/workspaces/{ws}` ✦ | Tất cả |
| POST | `/workspaces/{ws}/suspend` ✦ · `/unsuspend` ✦ | SUPER_ADMIN, SUPPORT — `{reason}` |
| GET | `/users` ✦ · `/users/{user}` ✦ | Tất cả |
| POST | `/users/{user}/suspend` ✦ · `/unsuspend` ✦ | SUPER_ADMIN, SUPPORT |
| POST | `/impersonations` ✦ | SUPER_ADMIN, SUPPORT — `{workspace_id, reason}`; luôn chỉ xem, hết hạn sau `SITEX_IMPERSONATION_MAX_MINUTES` (30) |
| DELETE | `/impersonations/current` ✦ | |
| GET | `/audit-logs` ✦ | Theo SECURITY.md §3.1 |
| GET/POST/PATCH | `/staff` ✦ | SUPER_ADMIN — gán/thu hồi platform_role |
| CRUD | `/plans`, `/plans/{id}/prices`, `/plans/{id}/limits`, `/credit-packs` | SUPER_ADMIN, BILLING_ADMIN (Phase 4) |
| GET | `/invoices`, `/payments` · POST `/workspaces/{ws}/credits/grant` | SUPER_ADMIN, BILLING_ADMIN (Phase 4) |
| GET | `/ai/usage?days=30&workspace_id=` ✦ | SUPER_ADMIN, BILLING_ADMIN — tổng, theo ngày/provider/model/task/workspace, lỗi |
| GET | `/ai/requests?status=&provider=&task_key=&error_code=&failed=1&workspace_id=&cursor=` ✦ | SUPER_ADMIN, BILLING_ADMIN — log request (không có prompt/secret) |
| GET · PATCH | `/ai/providers` · `/ai/providers/{id}` ✦ | SUPER_ADMIN — bật/tắt, timeout, retry; `api_key` (ghi-một-chiều, min 8) hoặc `clear_api_key: true`; `base_url`, `response_format` chỉ với provider tùy chỉnh (nếu không 422 `builtin_endpoint_locked`). Trả `has_api_key`, `api_key_last4`, `api_key_source`, không bao giờ trả khóa |
| POST | `/ai/providers` ✦ | SUPER_ADMIN — thêm provider OpenAI-compatible: `code`, `name`, `base_url` (https, qua SsrfGuard), `api_key?`, `response_format?`; tạo ở trạng thái tắt |
| DELETE | `/ai/providers/{id}` ✦ | SUPER_ADMIN — chỉ provider tùy chỉnh chưa có model (409 `provider_builtin` / `provider_in_use`) |
| POST | `/ai/providers/{id}/test-connection` ✦ | SUPER_ADMIN, 10/phút — gọi danh sách model (miễn phí): `ok`, `latency_ms`, `error_code`, `models_found`, `models[{model_name, available}]`, `sample` |
| POST | `/ai/providers/{id}/circuit/reset` ✦ | SUPER_ADMIN |
| GET · POST · PATCH | `/ai/models` · `/ai/models/{id}` ✦ | SUPER_ADMIN — model mới tắt cho tới khi có giá; PATCH đổi được `model_name` (Model ID, 409 nếu trùng) và `capabilities.reasoning_effort` (`none`/`low`/`high`/`max`, dùng cho DeepSeek) |
| DELETE | `/ai/models/{id}` ✦ | SUPER_ADMIN — chỉ khi chưa có lời gọi và chưa nằm trong route nào (409 `model_in_use`) |
| POST | `/ai/models/{id}/test` ✦ | SUPER_ADMIN, 10/phút — một lời gọi thật có tính phí (≤ 200 token ra), `prompt?`, `structured?`; trả `ok`, `text`, `json`, `usage`, `cost_micro_usd`, `finish_reason`, hoặc `error_code` đã lọc secret |
| GET · POST | `/ai/models/{id}/prices` ✦ | SUPER_ADMIN — tạo version giá mới (không sửa version cũ); tùy chọn `offpeak_*` + `peak_windows` (phải đi cùng nhau, `to` > `from`) |
| DELETE | `/ai/models/{id}/prices/{price}` ✦ | SUPER_ADMIN — chỉ version chưa hiệu lực, nếu không 409 `price_version_locked` |
| GET · PATCH | `/ai/routes` · `/ai/routes/{id}` ✦ | SUPER_ADMIN — chuỗi model, queue, timeout, `max_attempts`, `allow_paid_retry` |
| GET | `/ai/budgets` ✦ | SUPER_ADMIN, BILLING_ADMIN |
| PUT · DELETE | `/ai/budgets` · `/ai/budgets/{id}` ✦ | SUPER_ADMIN — `{scope: global|workspace, workspace_id?, period: day|month, limit_micro_usd, alert_threshold_pct?, action_on_exceed?: alert|pause_batch|pause_all, is_enabled?}` (upsert theo scope + period) |
| GET | `/jobs/overview` ✦ | SUPER_ADMIN, SUPPORT — Horizon, độ dài queue, số run theo trạng thái |
| GET | `/jobs/workflows?status=&type=&workspace_id=` · `/jobs/workflows/{id}` ✦ | SUPER_ADMIN, SUPPORT |
| POST | `/jobs/workflows/{id}/cancel` · `/retry` ✦ | SUPER_ADMIN, SUPPORT — ghi audit |
| GET | `/jobs/dead-letters` ✦ · POST `/{id}/retry` · `/{id}/discard` | SUPER_ADMIN, SUPPORT |
(✦ ở các dòng `/ai/*`, `/jobs/*` = Phase 3.)
| GET · PATCH | `/research/sources` · `/research/sources/{source}` (Phase 4) | SUPER_ADMIN (`platform.ai.manage`) — bật/tắt, `rate_limit_per_minute`, `timeout_seconds`, `secret` (ghi-một-chiều; `null`/rỗng = xóa), `config.location_code`, `reset_failures`. Chỉ trả `has_secret`, `secret_managed_by` |
| GET · PATCH | `/research/settings` (Phase 4) | SUPER_ADMIN — user-agent, crawl delay, kích thước trang, giới hạn sitemap, bật/tắt crawl website/đối thủ, lưu snapshot, làm mới hồ sơ |
| GET · PUT · DELETE | `/research/limits` · `/research/limits/{id}` (Phase 4) | SUPER_ADMIN — upsert `{scope: global|workspace, workspace_id?, max_campaigns, max_runs_per_month, max_ideas_per_run, max_competitors_per_campaign, max_calendar_items_per_month, max_concurrent_runs, max_ai_cost_per_run_micro_usd}` |
| GET | `/research/runs?status=&workspace_id=&cursor=` · `/research/failures` (Phase 4) | `platform.jobs.manage` — chỉ đọc |
| GET | `/research/costs` · `/research/usage` (Phase 4) | `platform.ai.view_costs` |
| GET · PATCH | `/writer/settings` (Phase 5) | `platform.ai.manage` — giới hạn, nghiên cứu, chính sách duyệt, ngưỡng lạm dụng (xem `WriterSettings::DEFAULTS`) |
| GET · PATCH | `/writer/workspaces/{ws}/settings` (Phase 5) | `platform.ai.manage` — ghi đè theo workspace |
| GET | `/writer/routing` (Phase 5) | `platform.ai.manage` — chỉ đọc task writer → route AI Gateway |
| GET | `/writer/costs` (Phase 5) | `platform.ai.view_costs` |
| GET | `/writer/runs` · `/writer/failures` (Phase 5) | `platform.jobs.manage` |
| GET | `/writer/abuse` (Phase 5) | `platform.ai.manage` — workspace vượt ngưỡng bài/giờ, chi phí/ngày, tỷ lệ lỗi |
| GET | `/system/health` | SUPER_ADMIN — queue backlog, failed jobs, provider status |

---


**Phase 6 — Media & Publishing** (`/api/admin/v1`)
| Method | Path | Quyền nền tảng |
|---|---|---|
| GET | `/media/overview` | `platform.ai.view_costs` — dung lượng, ảnh AI, chi phí, lỗi theo workspace |
| GET · PUT | `/media/limits` | `platform.ai.manage` — `scope_key` `global` / `ws:<id>`; có audit |
| GET | `/publishing/overview` | `platform.jobs.manage` — job theo trạng thái, lỗi, xung đột mở |
| GET · PUT | `/publishing/limits` | `platform.jobs.manage` — kill switch `publishing_enabled`, `auto_publish_allowed`, giới hạn; có audit |

**Phase 7 — Billing** (`/api/admin/v1/billing`, quyền `platform.billing.manage`: SUPER_ADMIN, BILLING_ADMIN; mọi thao tác ghi có audit `billing.*`, thao tác nhạy cảm bắt buộc `reason`/`note`)
| Method | Path | Mô tả |
|---|---|---|
| GET · POST | `/plans` | Danh sách (kèm versions, `live_subscriptions`) / tạo gói |
| PATCH · DELETE | `/plans/{id}` | Sửa / xóa (409 `plan_in_use` nếu có subscription) |
| POST | `/plans/{id}/versions` | Tạo version nháp (giá, trial, credit, tính năng, giới hạn) |
| PATCH | `/plan-versions/{id}` · POST `/plan-versions/{id}/publish` | Sửa nháp (409 `version_immutable` sau publish) / publish |
| GET · POST · PATCH | `/credit-packages[/{id}]` | Gói credit |
| GET · POST | `/credit-policies` | Chính sách quy đổi credit (versioned, không sửa) |
| GET | `/subscriptions` · `/subscriptions/{id}` | Lọc theo trạng thái/gói/workspace; chi tiết kèm events |
| POST | `/subscriptions/{id}/actions` | `{action: pause\|resume\|suspend\|unsuspend\|extend\|cancel, reason, days?}` |
| GET | `/orders` · `/orders/{id}` | Chi tiết kèm refunds, transactions, reconciliations |
| POST | `/orders/{id}/manual-payment` | `{bank_reference (UNIQUE), amount_minor, note}` → 201; trùng → 409 |
| POST | `/orders/{id}/cancel` · `/orders/{id}/refunds` | Hủy đơn / yêu cầu hoàn `{amount_minor, reason}` |
| GET | `/transactions` | Giao dịch ngân hàng theo `match_status` |
| POST | `/transactions/{id}/approve` · `/reject` | `{order_id?, note}` / `{note}` |
| GET | `/refunds` · POST `/refunds/{id}/complete` | `{succeeded, external_ref?, failure_reason?}` |
| GET | `/workspaces/{ws}/wallet` · POST `/workspaces/{ws}/credit-adjustments` | Ví + sổ cái; điều chỉnh `{amount_mc, kind, reason, expires_at?}` (không cho âm ví) |
| GET | `/credit-adjustments` · `/uncertain-usage` | Lịch sử điều chỉnh; lời gọi AI `needs_review` |
| GET | `/reports/revenue` · `/reports/costs` · `/reports/profitability` | `?from&to` — tiền mặt / ghi nhận / hoãn lại / nợ credit; chi phí AI; lợi nhuận theo tenant/gói + cảnh báo (không tự khóa) |
| GET · PUT | `/settings` | Cài đặt billing (`enforcement`, trial, ân hạn, proration, hết hạn đơn, nhắc gia hạn, tỷ giá, thông tin bên bán…) |
| GET · PUT | `/providers[/{code}]` | Cấu hình cổng; `credentials` ghi-một-chiều, chỉ trả `credentials_set[]` |

**Phase 8 — Analytics & Agent**
| Method | Path | Quyền | Mô tả |
|---|---|---|---|
| GET | `/analytics/overview?days=` | `platform.jobs.manage` | Property theo trạng thái, dữ liệu cũ, request GSC, dòng ghi, lỗi thường gặp, site lỗi, refresh, Copilot (số câu, chi phí); `oauth_configured` |
| GET | `/agent/overview?days=` | `platform.jobs.manage` | Run theo trạng thái, quyết định, task theo tool, run lỗi, chi phí ước tính, số site theo mức tự động, dừng khẩn cấp |
| GET · PUT | `/agent/policy` | `platform.ai.manage` | Kill switch (+ `kill_reason`), `max_autonomy_level`, `forbidden_tools`, `max_tasks_per_run`, `max_concurrent_runs`, timeout; audit `agent.platform_policy_updated` / `agent.platform_kill_switch` |

## 4. Connector API trên Cloud (`/connector/v1`) — plugin gọi (Phase 2) ✦

**Tải plugin (công khai, sau v1.0.0):** `GET /api/v1/plugin/sitex-post.zip`, không cần đăng nhập vì plugin không chứa bí mật nào; giới hạn `SITEX_PLUGIN_DOWNLOADS_PER_MINUTE_IP` (mặc định 20/phút/IP). File zip được dựng từ `wordpress-plugin/sitex-post`, trong đó giá trị mặc định của `SITEX_POST_CLOUD_URL` được thay bằng `sitex.connector.public_url` (chỉ chấp nhận URL http(s) thuần). `wp-config.php` vẫn ghi đè được. Việc ghép nối vẫn cần mã dùng một lần.

| Method | Path | Xác thực | Mô tả |
|---|---|---|---|
| POST | `/pair/preview` | Mã ghép nối | Xem trước: workspace, site, URL, danh sách quyền. Không cấp gì |
| POST | `/pair` | Mã ghép nối + xác nhận của quản trị WP | Ghép nối, trả `key_id` + `secret` **một lần** |
| POST | `/heartbeat` | Chữ ký `w2c` | `{site_url, wp_version, php_version, plugin_version, features?, publish_author?}` |
| POST | `/revoke` | Chữ ký `w2c` | Quản trị WP ngắt kết nối |

Giới hạn: `pair/*` 10 lần/giờ/IP; `heartbeat`/`revoke` 60 lần/phút/site; body tối đa 64 KB. (`/events` của thiết kế Phase 0 chưa triển khai.)

`POST /pair` request:
```json
{
  "pairing_code": "K7Q2-M9XA",
  "site_url": "https://example.com",
  "rest_url": "https://example.com/wp-json/",
  "publish_author": { "wp_user_id": 3, "display_name": "Biên tập viên" },
  "confirmed_by": { "wp_user_id": 1, "display_name": "Admin" },
  "environment": { "wp_version": "6.8.3", "php_version": "8.2.12", "plugin_version": "1.0.0", "locale": "vi" }
}
```
Response `200`:
```json
{
  "data": {
    "site_id": "01JE...", "site_name": "Blog", "workspace_name": "Cửa hàng Hoa Mai",
    "key_id": "01JE...", "secret": "base64url-32-bytes",
    "signature_version": "SITEX-HMAC-V1", "cloud_api_base": "https://api.<domain>/connector/v1"
  }
}
```
Lỗi: `422 invalid_pairing_code` (sai/hết hạn/đã dùng/quá số lần), `409 site_url_mismatch`, `422 validation_failed` (`rest_url` khác host), `429 rate_limited`. Chữ ký sai: `401 invalid_signature` (mọi lý do cùng một mã).

## 5. Plugin API trên WordPress (`/wp-json/sitex-post/v1`) — Cloud gọi (Phase 2) ✦

Fallback khi site dùng permalink mặc định: `/?rest_route=/sitex-post/v1/...` (Cloud tự chọn theo `rest_url` đã ghép nối). Mọi endpoint trừ `/ping` yêu cầu chữ ký `c2w`; response có chữ ký `resp`.

| Method | Path | Mô tả |
|---|---|---|
| GET | `/ping` | Công khai: `{plugin: "sitex-post", version, paired}` |
| GET | `/health` | Phiên bản WP/PHP/plugin/WooCommerce/Yoast, `features` (woocommerce, yoast, rank_math, seo_adapter, pretty_permalinks, https), `author` (id, tên, `can_publish`, `can_upload`), `limits.max_upload_bytes` |
| GET | `/terms?taxonomy=category\|post_tag\|product_cat\|product_tag&page&per_page` | `{items: [{id, name, slug, parent, count}], total, total_pages}` |
| GET | `/content?type=post\|page\|product&page&per_page` | `{items: [{id, type, status, title, url, slug, excerpt, date_gmt, modified_gmt, term_ids, managed, product?: {sku, price, regular_price, sale_price, currency, stock_status, type}}], total, total_pages}` |
| POST | `/posts` | Tạo hoặc trả lại bài theo `idempotency_key`: `{idempotency_key, post_type: post\|page, title, content_html, excerpt?, slug?, status: draft\|pending\|publish\|future, date_gmt?, categories?: [id\|tên], tags?: [tên], featured_media_id?, seo?: {title, description, focus_keyword, canonical, noindex}, sitex?: {...}}` → 201 `{id, created: true, post_type, status, url, seo_adapter}` hoặc 200 `created: false` |
| PUT | `/posts/{id}` | Cập nhật bài do SiteX quản lý (bài khác → 403, bài trong thùng rác → 410) |
| POST | `/media?idempotency_key&filename&alt&title&caption` | Body = byte ảnh thô (`Content-Type: image/*`) → `{id, url, created}`; idempotent theo key |
| POST | `/credentials/rotate` | `{key_id, secret, grace_seconds}` (ký bằng khóa hiện tại) |
| POST | `/disconnect` | Xóa secret cục bộ (response vẫn được ký bằng khóa vừa xóa) |

**Bổ sung Phase 6 (plugin 1.1.0)**
| Method | Path | Mô tả |
|---|---|---|
| GET | `/posts/{id}/state` | Trạng thái có ký: status, slug, url, modified_gmt, `content_hash`, `written_hash`, `revision_id`, SEO + `seo_hash`, `seo_adapter` |
| GET | `/posts/lookup?idempotency_key=` | Tìm bài theo khóa (đối soát sau timeout) |
| GET | `/media?page&per_page` | Media Library (tham chiếu, ≤ 100/trang) |
| POST/PUT | `/posts`, `/posts/{id}` | Thêm `expected {content_hash, slug, seo_hash}`, `force` (chỉ khi người dùng chọn ghi đè), `schema` (JSON-LD). Cập nhật bài đã bị sửa trên WP → **409 `sitex_conflict`** |

Ảnh nội dung: Cloud tải ảnh lên trước, rồi thay placeholder `{{sitex-image:KEY}}` (URL) và `{{sitex-image-id:KEY}}` (ID) trong `content_html`.

SEO meta: Yoast (`_yoast_wpseo_*`), Rank Math (`rank_math_*`) nếu đang bật; nếu không, plugin in thẻ `<title>`/description/robots/canonical qua `wp_head`. Plugin luôn lưu bản sao `_sitex_post_seo_*`.

Idempotency: Cloud lưu `site_publications (site_id, idempotency_key) → wp_post_id` (Phase 2, đăng thử) và `wordpress_post_mappings` (Phase 6); plugin lưu `_sitex_post_idempotency_key` + khóa tạm, nên kể cả khi Cloud mất response và gửi lại, WordPress vẫn chỉ có một bài.

## 6. Webhooks thanh toán (Phase 7) ✦

`POST /api/v1/billing/webhooks/{provider}` — công khai, không session/CSRF, `throttle:billing-webhook` (theo IP). Chỉ provider dùng adapter `signed_webhook` và đang bật (không thì 404 / `webhook_not_supported`).

- Header: `X-SiteX-Timestamp: <unix giây>`, `X-SiteX-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + raw_body)>`. Lệch > ±300 s, sai/thiếu chữ ký → `401 webhook_rejected` (không xử lý, chỉ log lý do + SHA-256 payload).
- Body: `{"event_id": "...", "transactions": [{"id", "amount", "currency", "direction": "in|out", "description", "counterparty?", "occurred_at"}]}`.
- `(provider, event_id)` UNIQUE: gửi lại → `200 {"data": {"status": "duplicate"}}`. Hợp lệ → xử lý đồng bộ, `200 {"data": {"status": "processed", "results": [{external_id, match_status, reason}]}}`.
- Quy tắc khớp: `PAYMENT-RECONCILIATION.md §4`.

## 7. Versioning & tương thích

- Breaking change → `v2` song song; `v1` duy trì ≥ 6 tháng sau khi có `v2`.
- Plugin gửi `plugin_version`; Cloud có bảng tương thích tối thiểu, thông báo cập nhật trong WP admin khi plugin quá cũ.
- Type TypeScript cho response được viết tay trong `frontend/src/shared/api/types.ts` ở Phase 1; cân nhắc sinh từ OpenAPI (Scramble) ở phase sau.

## 8. Phase 9 — Installer và System

### 8.1 Trình cài đặt (`/install`, chỉ khi chưa cài)

Trang `GET /install` (HTML, CSP chặt, không cookie). API dưới `/install/api`, **không** session/CSRF, mọi request cần header `X-Setup-Token: <token>` (lấy bằng `php artisan sitex:install:token` qua SSH; lưu dạng sha256, giới hạn tần suất theo IP). Khi đã cài (bất kỳ tín hiệu khóa nào), mọi route trả **403 `installer_locked`**.

| Method | Path | Mô tả |
|---|---|---|
| GET | `/install/api/state` | Bước hiện tại, cờ `*_set` (không trả secret) |
| GET | `/install/api/environment` | Kiểm tra PHP, extension, quyền thư mục, hàm bị tắt |
| POST | `/install/api/database/test` | Kết nối thử, phiên bản MySQL, DB trống hay không |
| PUT | `/install/api/database` | Lưu cấu hình DB (mật khẩu mã hóa phía máy chủ) |
| PUT | `/install/api/application` | Tên ứng dụng, URL API/app/admin, ngôn ngữ, múi giờ |
| POST | `/install/api/redis/test` · PUT `/install/api/queue` | Kiểm tra Redis; chọn driver queue `redis` hoặc `database` |
| PUT | `/install/api/admin` | Super Admin: tên, email, mật khẩu ≥ 12 ký tự có hoa/thường/số/ký hiệu, không chứa email (giữ dạng mã hóa tạm đến khi cài) |
| POST | `/install/api/run` | Ghi `.env`, `key:generate`, migrate, seed hệ thống, tạo Super Admin, khóa installer |
| GET | `/install/api/templates/{supervisor-horizon\|supervisor-queue-work\|cron\|nginx-api}` | Mẫu cấu hình aaPanel theo đường dẫn thật |
| GET | `/install/api/diagnostics` | Nhật ký cài đặt đã che secret |

Mã lỗi: `invalid_setup_token` 401, `installer_locked` 403, `rate_limited` 429, `database_not_empty` 409, `database_version` 422, `database_<lý do>` 422 (`auth_failed`, `host_unreachable`…), `redis_<lý do>` 422, `installation_in_progress` 409. Chi tiết: INSTALLATION-WIZARD.md, INSTALLER-SECURITY.md.

### 8.2 System (Super Admin) — `/api/admin/v1/system`

Middleware `auth:sanctum`, staff + 2FA (`EnsurePlatformStaff`). `platform.system.view`: `GET health`, `GET alerts`. Còn lại cần `platform.system.manage` (chỉ SUPER_ADMIN).

| Method | Path | Mô tả |
|---|---|---|
| GET | `/health` | 16 mục: `{key, status: ok\|warning\|critical\|unknown, summary, details, fix}` + trạng thái tổng; không có secret |
| GET | `/alerts` | Lịch sử cảnh báo (kênh nào gửi được) |
| GET | `/setup` | 12 bước Setup Wizard, trạng thái do máy chủ tính: `NOT_STARTED\|IN_PROGRESS\|COMPLETE\|WARNING\|BLOCKED`; tổng `READY\|READY_WITH_WARNINGS\|NOT_READY` |
| PUT | `/settings/{general\|mail\|google\|alerts\|backup}` | Lưu cấu hình (secret chỉ ghi; response trả `<field>_configured`) |
| POST | `/settings/logo` | PNG/JPG/WebP ≤ 512 KB (không SVG) |
| POST | `/settings/{mail\|google\|alerts}/test` | 202, chạy trong queue, 6/phút |
| POST | `/setup/plans/draft` · `/setup/acknowledge` · `/setup/complete` | Gói nháp không giá; xác nhận bước; hoàn tất (`setup_not_ready` 422) |
| GET | `/plugin/download` | Chỉ phục vụ `sitex-post-X.Y.Z.zip` trong thư mục cấu hình |
| GET · POST | `/upgrade` | Trạng thái nâng cấp; bắt đầu (`password`, `backup_first`) → 202 |
| GET · POST | `/backups` | Danh sách; tạo bản mới (`password`) → 202 |
| POST | `/backups/{backup}/verify` | Kiểm tra một bản → 202 |

Mã lỗi: `nothing_to_upgrade` 422, `destructive_migrations` 409, `requirements_not_met` 409, `backup_required` 409, `backup_not_configured` 409, `upgrade_in_progress` 409, `backup_in_progress` 409, sai mật khẩu 422, `two_factor_required` 403.

### 8.3 Lệnh CLI Phase 9

`sitex:install:token`, `sitex:install:status`, `sitex:system:heartbeat`, `sitex:system:monitor`, `sitex:backup:{key,run,verify,decrypt,prune}`, `sitex:upgrade:check [--json] [--record]` (mã thoát 0 = sẵn sàng, 1 = không có gì, 2 = bị chặn).
