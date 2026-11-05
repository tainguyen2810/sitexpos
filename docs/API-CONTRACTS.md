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

## 4. Connector API trên Cloud (`/connector/v1`) — plugin gọi (Phase 2) ✦

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

Ảnh nội dung: Cloud tải ảnh lên trước, rồi thay placeholder `{{sitex-image:KEY}}` (URL) và `{{sitex-image-id:KEY}}` (ID) trong `content_html`.

SEO meta: Yoast (`_yoast_wpseo_*`), Rank Math (`rank_math_*`) nếu đang bật; nếu không, plugin in thẻ `<title>`/description/robots/canonical qua `wp_head`. Plugin luôn lưu bản sao `_sitex_post_seo_*`.

Idempotency: Cloud lưu `site_publications (site_id, idempotency_key) → wp_post_id`; plugin lưu `_sitex_post_idempotency_key` + khóa tạm, nên kể cả khi Cloud mất response và gửi lại, WordPress vẫn chỉ có một bài.

## 6. Webhooks (`/webhooks/{provider}`) — Phase 3

`POST /webhooks/stripe`, `/webhooks/payos`, ... Luôn trả `200` nhanh sau khi xác minh chữ ký và lưu `webhook_events`; xử lý trong queue. Chữ ký sai → `400`, không lưu payload.

## 7. Versioning & tương thích

- Breaking change → `v2` song song; `v1` duy trì ≥ 6 tháng sau khi có `v2`.
- Plugin gửi `plugin_version`; Cloud có bảng tương thích tối thiểu, thông báo cập nhật trong WP admin khi plugin quá cũ.
- Type TypeScript cho response được viết tay trong `frontend/src/shared/api/types.ts` ở Phase 1; cân nhắc sinh từ OpenAPI (Scramble) ở phase sau.
