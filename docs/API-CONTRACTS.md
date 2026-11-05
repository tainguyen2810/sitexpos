# SiteX Post — API Contracts

Phiên bản: `v1`. Mọi API trả JSON UTF-8. Thời gian ISO-8601 UTC (`2026-11-05T05:23:12Z`). ID là ULID.

| Bề mặt | Base URL | Xác thực |
|---|---|---|
| Customer API | `https://api.<domain>/api/v1` | Sanctum SPA cookie + CSRF |
| Admin API | `https://api.<domain>/api/admin/v1` | Sanctum SPA cookie + CSRF + `platform_role` + 2FA |
| Connector API (Cloud, plugin gọi) | `https://api.<domain>/connector/v1` | Chữ ký Ed25519 (SECURITY.md §6.2) |
| Plugin API (WP, Cloud gọi) | `https://<site>/wp-json/sitex-post/v1` | Chữ ký Ed25519 |
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
| 402 | `quota_exceeded`, `subscription_inactive`, `insufficient_credits` | Hết quota/gói |
| 419 | `csrf_mismatch` | CSRF hết hạn |
| 422 | `validation_failed` | Validate thất bại |
| 429 | `rate_limited` | Có header `Retry-After` |
| 500 | `server_error` | Không lộ chi tiết |
| 503 | `maintenance`, `provider_unavailable` | |

`message` dịch theo `Accept-Language` (`vi` mặc định).

### 1.3 Header
- Request: `Accept: application/json`, `Accept-Language: vi`, `X-XSRF-TOKEN`, `X-Requested-With: XMLHttpRequest`, tùy chọn `X-Request-Id` (chỉ nhận ULID/UUID/chuỗi an toàn, nếu không sẽ sinh mới). `Idempotency-Key` sẽ bắt buộc với endpoint tiêu tốn quota/credit từ Phase 3 (Phase 1 chưa có endpoint như vậy).
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

### 2.3 Sites (Phase 2)
| Method | Path | Quyền |
|---|---|---|
| GET | `/workspaces/{ws}/sites` | OAEV |
| POST | `/workspaces/{ws}/sites` | OA — `{name, url, content_language}` → site `pending_pairing` + `pairing_code`, `expires_at` (chỉ trả một lần) |
| GET/PATCH | `/workspaces/{ws}/sites/{site}` | OAEV / OA — `{name, approval_mode, settings}` |
| POST | `/workspaces/{ws}/sites/{site}/pairing-code` | OA — cấp lại mã |
| POST | `/workspaces/{ws}/sites/{site}/health-check` | OAE → 202 |
| POST | `/workspaces/{ws}/sites/{site}/rotate-keys` | OA |
| POST | `/workspaces/{ws}/sites/{site}/revoke` | OA |
| DELETE | `/workspaces/{ws}/sites/{site}` | OA |
| GET | `/workspaces/{ws}/sites/{site}/taxonomies` | OAEV |

### 2.4 Billing & Usage (Phase 3)
| Method | Path | Quyền |
|---|---|---|
| GET | `/plans` | công khai |
| GET | `/workspaces/{ws}/subscription` | OA |
| POST | `/workspaces/{ws}/subscription/checkout` | O — `{plan_price_id, gateway}` → `{checkout_url}` |
| POST | `/workspaces/{ws}/subscription/cancel` | O |
| GET | `/workspaces/{ws}/invoices` · `/invoices/{id}/pdf` | O |
| GET | `/workspaces/{ws}/usage` | OAEV — quota theo metric: `{metric, limit, used, reserved, period_end}` |
| POST | `/workspaces/{ws}/credits/checkout` | O — `{credit_pack_id}` |
| GET | `/workspaces/{ws}/credits/ledger` | O |

### 2.5 Business profile, Research, Keywords, Planning (Phase 5)
| Method | Path |
|---|---|
| GET/PUT | `/workspaces/{ws}/sites/{site}/business-profile` |
| CRUD | `/workspaces/{ws}/sites/{site}/offerings` |
| POST | `/workspaces/{ws}/sites/{site}/research-runs` `{type}` → 202 |
| GET | `/workspaces/{ws}/research-runs/{id}` (gồm `sources[]` với `url`, `fetched_at`, `provider`) |
| GET/POST | `/workspaces/{ws}/sites/{site}/competitors` |
| POST | `/workspaces/{ws}/sites/{site}/keyword-discovery` → 202 |
| GET | `/workspaces/{ws}/sites/{site}/keywords` — mỗi keyword có `metrics: null` hoặc `{search_volume, provider, fetched_at}` |
| PATCH | `/workspaces/{ws}/keywords/{kw}` `{status}` |
| CRUD | `/workspaces/{ws}/sites/{site}/topic-clusters` |
| GET/POST/PATCH | `/workspaces/{ws}/sites/{site}/calendar` |

### 2.6 Content, Verification, Media, SEO, Publishing (Phase 6–8)
| Method | Path |
|---|---|
| GET/POST | `/workspaces/{ws}/sites/{site}/articles` |
| GET/PATCH/DELETE | `/workspaces/{ws}/articles/{article}` |
| POST | `/workspaces/{ws}/articles/{article}/generate` `{from_step?}` → 202 (Idempotency-Key bắt buộc) |
| GET | `/workspaces/{ws}/articles/{article}/versions` · `/versions/{v}` · `/versions/{a}/diff/{b}` |
| POST | `/workspaces/{ws}/articles/{article}/versions` — lưu chỉnh sửa thủ công → phiên bản mới |
| POST | `/workspaces/{ws}/articles/{article}/versions/{v}/restore` |
| GET | `/workspaces/{ws}/articles/{article}/verification` — claims + evidence |
| POST | `/workspaces/{ws}/articles/{article}/verification/override` — OA, `{reason}` (audit) |
| GET/POST | `/workspaces/{ws}/articles/{article}/media` |
| POST | `/workspaces/{ws}/media/uploads` (multipart) |
| GET/PATCH | `/workspaces/{ws}/articles/{article}/internal-links` |
| POST | `/workspaces/{ws}/articles/{article}/submit-for-approval` |
| POST | `/workspaces/{ws}/articles/{article}/approve` · `/request-changes` · `/reject` |
| POST | `/workspaces/{ws}/articles/{article}/schedule` `{run_at, wp_status}` — 409 nếu chưa qua kiểm chứng |
| POST | `/workspaces/{ws}/articles/{article}/publish-now` |

### 2.7 Analytics (Phase 9)
| Method | Path |
|---|---|
| GET | `/workspaces/{ws}/sites/{site}/gsc/connect` → URL OAuth Google |
| GET | `/oauth/google/callback` (state ký, gắn workspace + site) |
| DELETE | `/workspaces/{ws}/sites/{site}/gsc` |
| GET | `/workspaces/{ws}/sites/{site}/performance?from&to&dimension=page|query` |
| GET/PATCH | `/workspaces/{ws}/sites/{site}/refresh-suggestions` |

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
| CRUD | `/plans`, `/plans/{id}/prices`, `/plans/{id}/limits`, `/credit-packs` | SUPER_ADMIN, BILLING_ADMIN (Phase 3) |
| GET | `/invoices`, `/payments` · POST `/workspaces/{ws}/credits/grant` | SUPER_ADMIN, BILLING_ADMIN (Phase 3) |
| CRUD | `/ai/models`, `/ai/task-routes`, `/ai/prompt-templates`, `/ai/budgets` | SUPER_ADMIN (Phase 4) |
| GET | `/ai/costs?group_by=workspace|task|model&from&to` | SUPER_ADMIN, BILLING_ADMIN (Phase 4) |
| GET | `/system/health` | SUPER_ADMIN — queue backlog, failed jobs, provider status |

---

## 4. Connector API trên Cloud (`/connector/v1`) — plugin gọi (Phase 2)

| Method | Path | Xác thực | Mô tả |
|---|---|---|---|
| POST | `/pair` | Mã ghép nối (không chữ ký) | Ghép nối, trả khóa Cloud |
| POST | `/heartbeat` | Chữ ký plugin | `{wp_version, php_version, plugin_version, site_url}` |
| POST | `/revoke` | Chữ ký plugin | Plugin chủ động ngắt |
| POST | `/events` | Chữ ký plugin | `{type: "post.updated"|"post.deleted", wp_post_id, ...}` |

`POST /pair` request:
```json
{
  "pairing_code": "K7Q2-M9XA",
  "site_url": "https://example.com",
  "plugin_public_key": "base64...",
  "publish_author": { "wp_user_id": 3, "display_name": "Biên tập viên" },
  "environment": { "wp_version": "6.8.3", "php_version": "8.2.12", "plugin_version": "1.0.0", "locale": "vi" }
}
```
Response `200`:
```json
{
  "data": {
    "site_id": "01JE...", "key_id": "01JE...", "cloud_public_key": "base64...",
    "cloud_api_base": "https://api.<domain>/connector/v1", "signature_version": "SITEX-V1"
  }
}
```
Lỗi: `422 invalid_pairing_code`, `409 site_url_mismatch`, `429 rate_limited`.

## 5. Plugin API trên WordPress (`/wp-json/sitex-post/v1`) — Cloud gọi (Phase 2, 8)

| Method | Path | Mô tả |
|---|---|---|
| GET | `/ping` | Công khai, chỉ trả `{plugin: "sitex-post", version, paired: bool}` |
| GET | `/health` | Có chữ ký: trạng thái, phiên bản, quyền user đăng bài |
| GET | `/site-info` | Tên site, ngôn ngữ, timezone, post types, permalink structure |
| GET | `/taxonomies?type=category|post_tag` | Danh sách term |
| GET | `/posts?per_page&page&modified_after` | Index bài (id, url, title, excerpt, dates) cho internal link |
| POST | `/posts` | Tạo bài: `{idempotency_key, title, content_html, excerpt, slug, status, date_gmt, categories[], tags[], featured_media_id, meta: {seo_title, seo_description, focus_keyword}, sitex: {article_id, version_id}}` |
| PUT | `/posts/{id}` | Cập nhật bài do SiteX quản lý |
| POST | `/media` | multipart: file + `{alt, caption, title, idempotency_key}` → `{media_id, url}` |
| POST | `/disconnect` | Xóa khóa cục bộ |

SEO meta: plugin ghi vào Yoast/Rank Math nếu có cài, nếu không lưu post meta `_sitex_seo_*` và in thẻ meta qua `wp_head`.

Idempotency: plugin lưu `idempotency_key` trong post meta; request lặp trả lại bài đã tạo.

## 6. Webhooks (`/webhooks/{provider}`) — Phase 3

`POST /webhooks/stripe`, `/webhooks/payos`, ... Luôn trả `200` nhanh sau khi xác minh chữ ký và lưu `webhook_events`; xử lý trong queue. Chữ ký sai → `400`, không lưu payload.

## 7. Versioning & tương thích

- Breaking change → `v2` song song; `v1` duy trì ≥ 6 tháng sau khi có `v2`.
- Plugin gửi `plugin_version`; Cloud có bảng tương thích tối thiểu, thông báo cập nhật trong WP admin khi plugin quá cũ.
- Type TypeScript cho response được viết tay trong `frontend/src/shared/api/types.ts` ở Phase 1; cân nhắc sinh từ OpenAPI (Scramble) ở phase sau.
