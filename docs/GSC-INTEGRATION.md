# Google Search Console Integration (Phase 8)

## 1. Tái sử dụng OAuth của Phase 4
Không tạo bảng/kết nối GSC thứ hai. Kết nối vẫn nằm ở `search_console_connections` (module Research, token mã hóa bằng cast `encrypted`, ADR-015). Phase 8 mở rộng qua contract `Research\Contracts\SearchConsoleAccess` (`Research/Queries/SearchConsoleAccessQuery`):

| Hàm | Ý nghĩa |
|---|---|
| `status(site)` | `connected`, `status` (`not_connected`, `needs_property`, `active`, `reauth_required`, `disconnected`), `property_url`, `google_email`, lỗi gần nhất |
| `authorizationUrl(site, user)` | state mã hóa, dùng một lần, gắn user + workspace + site; Google quay về trang Kết nối dữ liệu |
| `properties(site)` | property mà tài khoản Google **thực sự** truy cập được |
| `mapProperty(site, property)` | chỉ chấp nhận property có trong danh sách trên (`property_unknown` nếu không) |
| `query(site, body)` | Search Analytics API; refresh token khi hết hạn |
| `disconnect(site)` | thu hồi token tại Google (`oauth2.googleapis.com/revoke`) rồi xóa token cục bộ |

SiteX Post **không bao giờ** hỏi mật khẩu Google. Scope duy nhất: `https://www.googleapis.com/auth/webmasters.readonly`.

## 2. Kiểm tra scope
Sau khi đổi `code` lấy token, `GoogleSearchConsole::hasScope()` kiểm tra scope Google thực sự cấp. Người dùng bỏ tick quyền → token bị thu hồi ngay và callback trả `?gsc=scope_missing` (trước Phase 8: kết nối “thành công” rồi lỗi khi đồng bộ).

## 3. Kết quả callback (`?gsc=`)
`connected` (tự khớp property theo domain/URL prefix), `needs_property`, `denied`, `invalid_state`, `scope_missing`, `oauth_failed`, `not_configured`, `unreachable`. UI hiển thị thông điệp tiếng Việt tương ứng.

## 4. Đồng bộ (`Actions/SyncSearchConsole`)
- **Chiều dữ liệu**: theo ngày × loại tìm kiếm: `device,country` → `search_performance_daily`; `query` → `search_query_metrics`; `page` → `search_page_metrics` (gắn `article_id` theo `UrlMapper`); `query,page` cho cửa sổ gần nhất → `search_query_page_metrics` (phát hiện ăn thịt từ khóa).
- **Incremental**: từ checkpoint (`analytics_properties.last_complete_date`) lùi 2 ngày (hàng trễ) tới hôm nay − `lag_days` (3).
- **Backfill**: tới `backfill_days` (mặc định 90, tối đa 480 ≈ 16 tháng GSC giữ).
- **Giới hạn mỗi lần chạy**: `max_days_per_run` = 31; checkpoint cho phép lần sau chạy tiếp, worker khởi động lại không chạy lại từ đầu.
- **Phân trang**: `rowLimit` 25 000 + `startRow`, tối đa `max_pages_per_request` trang.
- **Dedupe**: upsert theo khóa tự nhiên (site, ngày, loại, chiều, hash) → chạy lại không nhân đôi.
- **Rate limit / quota / mạng**: `quota_exceeded`, `unreachable`, 5xx là lỗi có thể thử lại → `next_retry_at` theo backoff 5 / 15 / 60 / 180 phút, run `partial`. Lỗi không thử lại (`reauth_required`, `scope_missing`, `forbidden`, `property_revoked`) → property `error`, cần người xử lý.
- **Một lần đồng bộ một lúc** cho mỗi site (`sync_in_progress`, 409). Yêu cầu `POST /sync` có `Idempotency-Key`.
- **Độ tươi**: `latest_date`, `last_sync_at`, `last_sync_status` trả về UI; nhắc độ trễ 2–3 ngày.
- Sau khi đồng bộ: `AnalyzeSiteJob` tính lại phân tích.

## 5. Bảo mật
- Token không bao giờ trả ra API hoặc log (`SecretRedactor`).
- Mọi endpoint tenant dưới `/api/v1/workspaces/{ws}/analytics`, có test cross-tenant 404 và phân quyền (`sites.manage` cho kết nối/đồng bộ/cài đặt).
- Audit: `research.search_console.connected` (callback Phase 4), `analytics.property_selected`, `analytics.sync_started`, `analytics.sync_failed`, `analytics.gsc.property_set`, `analytics.gsc.disconnected` (kèm `revoked_at_google`).

## 6. GA4
`Connectors/Ga4Connector` là adapter giữ chỗ, luôn trả `available: false`. UI ghi “chưa hỗ trợ”. Không có dữ liệu GA4 giả.

## 7. Cần để chạy thật
`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, redirect URI `https://<api>/api/v1/oauth/google/callback` đăng ký trong Google Cloud Console, bật Search Console API. Chưa được kiểm thử với Google thật trong phiên này (không có credentials) — test dùng `Http::fake`.
