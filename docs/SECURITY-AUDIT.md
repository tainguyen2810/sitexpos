# Audit bảo mật — Phase 9

Ngày: 06/11/2026. Người thực hiện: Claude (rà soát mã nguồn + chạy test tự động + kiểm thử thật trên bản cài cục bộ). **Không** phải pentest độc lập; chưa chạy DAST/scanner bên ngoài, chưa có bên thứ ba đánh giá. Trước khi bán thương mại nên thuê pentest (xem §8).

Trạng thái: **ĐẠT** = có kiểm soát và có test/kiểm thử chứng minh · **MỘT PHẦN** = có kiểm soát nhưng còn khoảng trống · **MỞ** = chưa có kiểm soát.

## 1. Phạm vi và phương pháp

- Mã nguồn `main` + nhánh Phase 9 (`claude/project-thread-x8svn6`): backend Laravel (17 module), 2 SPA Vue, plugin WordPress, installer.
- Test tự động: toàn bộ Pest chạy trên MySQL 8.0.46 (520/521 pass, xem PERFORMANCE-REPORT §4) và trong CI trên MySQL 8.4; các test bảo mật nêu dưới đây.
- Kiểm thử thật: cài đặt `/install` trên MySQL + Redis + Chromium, thử cài lại, đăng nhập admin + 2FA, truy cập chéo tenant qua trình duyệt (E2E-TEST-REPORT).
- Phụ thuộc: `composer audit` → "No security vulnerability advisories found"; `npm audit --omit=dev` → "found 0 vulnerabilities" (06/11/2026).
- Tham chiếu audit trước: Phase 1 (`/mnt/project-files/phase1-audit/`), Phase 2, Phase 3 — các phát hiện còn mở được đánh giá lại ở §7.

## 2. OWASP Top 10 (2021)

| Mã | Rủi ro | Kiểm soát | Bằng chứng | Trạng thái |
|---|---|---|---|---|
| A01 | Broken Access Control | Tenant scope bắt buộc (`BelongsToWorkspace`), không nhận `workspace_id` từ request, route tenant dưới `/workspaces/{workspace}` + Policy cho mọi action; admin cần `EnsurePlatformStaff` + quyền platform + 2FA; truy cập chéo trả 404 | `CrossTenantIsolationTest` (tự liệt kê mọi route tenant), `ThreeTenantIsolationTest` (3 tenant, 64 route GET, không lộ ULID của tenant khác, 10.756 assertion), test phân quyền theo vai trò từng module, E2E trình duyệt: URL tenant khác → trang 404 | ĐẠT |
| A02 | Cryptographic Failures | bcrypt cho mật khẩu; secret trong DB mã hóa `Crypt` (APP_KEY); sao lưu AES-256-GCM theo khối với khóa riêng; HMAC-SHA256 cho connector; token installer lưu sha256; HSTS trong mẫu Nginx | `BackupTest` (sửa 1 byte/cắt file/sai khóa bị phát hiện), `ConnectorSignatureTest`, `InstallerTest` | MỘT PHẦN: chưa có envelope encryption/xoay khóa (`SITEX_MASTER_KEY` chỉ là thiết kế) — lộ APP_KEY + DB = lộ secret |
| A03 | Injection | Eloquent/bind tham số; LIKE escape `%`/`_`; không `DB::raw` với input; nội dung HTML bài qua `HtmlSanitizer` + `SafeHtml`; nội dung web/AI coi là dữ liệu (prompt injection, SECURITY §7) | `WriterSafetyTest`, `ArchitectureTest`, lint frontend cấm `v-html` ngoài SafeHtml | ĐẠT |
| A04 | Insecure Design | Pipeline bắt buộc: Writer → fact-check → SEO Audit → Publisher, cổng chạy lại ngay trước khi ghi WordPress; Agent qua policy engine phía server, không có tool SQL/shell/billing/API key/RBAC; chỉ `PaymentConfirmation` được đổi đơn sang PAID; installer không chạy lệnh root | `PublishingTest`, `AgentPolicyEngineTest`, `PaymentFlowTest`, `InstallerTest` | ĐẠT |
| A05 | Security Misconfiguration | Header `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Cache-Control: no-store` cho API; CSP chặt cho installer và file media; Setup Wizard bước 11 kiểm `APP_ENV`, `APP_DEBUG`, `APP_KEY`, HTTPS, cookie Secure/HttpOnly, installer khóa, quyền `.env`, 2FA Super Admin; SSRF dev switches bị bỏ qua ở production (**sửa trong Phase 9**) | `SystemAdminTest`, `SsrfProductionTest` | MỘT PHẦN: SPA chưa có CSP (đặt ở Nginx); `SESSION_SECURE_COOKIE` phụ thuộc `.env` (installer ghi `true` khi HTTPS, health cảnh báo nếu sai) |
| A06 | Vulnerable Components | Phiên bản ghim; Composer/npm audit sạch | Lệnh ở §1 | ĐẠT (tại thời điểm audit; cần chạy định kỳ — RELEASE-CHECKLIST) |
| A07 | Identification & Authentication | Mật khẩu ≥ 10 ký tự, chữ + số, kiểm tra lộ lọt (`uncompromised`); giới hạn đăng nhập 5/phút theo email+IP và 60/giờ theo IP; xác minh email; 2FA TOTP bắt buộc cho staff; đổi mật khẩu đăng xuất phiên khác (AuthenticateSession) và xoay remember token; impersonation kiểm lại quyền | `RegistrationAndLoginTest`, `RememberTokenRevocationTest`, `ImpersonationRevalidationTest` | MỘT PHẦN: chưa có email thông báo bảo mật (đổi mật khẩu, đăng nhập mới) |
| A08 | Software & Data Integrity | Webhook thanh toán ký + chống phát lại; request connector ký + timestamp + nonce, response cũng ký; ledger credit append-only với `verify()` hằng ngày; gói phát hành kèm SHA-256; web không tự cập nhật mã nguồn | `PaymentFlowTest`, `ConnectorSignatureTest`, `AiCreditsTest`, `sitex:billing:verify-ledger` | ĐẠT |
| A09 | Logging & Monitoring | Audit log cho hành động nhạy cảm; `SecretRedactor` che secret trong log và lỗi provider; System Health + cảnh báo email/Telegram có cooldown | `RedactSecretsTest`, `SecretRedactorTest`, `AiGatewayTest` ("never stores the API key"), E2E installer: grep mật khẩu trong log = 0 | ĐẠT (kênh cảnh báo thật chưa thử — MONITORING-RUNBOOK §6) |
| A10 | SSRF | `SsrfGuard`: chỉ https, cổng 443, chặn IP private/loopback/link-local/metadata/CGNAT/IPv6 ULA, ghim IP sau khi resolve (chống DNS rebinding), cấm credentials trong URL; mọi URL do người dùng/website cung cấp đi qua guard | `SsrfGuardTest`, `SsrfProductionTest`, `PairingTest` (REST URL khác host bị từ chối) | ĐẠT |

## 3. Đa tenant

- Ba lớp: global scope theo `TenantContext`, route model binding trong workspace, Policy. Job trong queue mang workspace và chạy lại trong ngữ cảnh đó (`TenantJobTest`).
- Kiểm thử 3 tenant (Phase 9): mỗi tenant có dữ liệu đầy đủ Phase 4–8 (research, writer, media/SEO/publishing, analytics/agent); chủ mỗi tenant gọi 64 route GET; khẳng định không có ULID nào của hai tenant còn lại xuất hiện; test yêu cầu > 80 % số route trả 200 để bảo đảm thực sự đọc được dữ liệu (không "đạt" nhờ lỗi).
- `withoutWorkspaceScope()` không được gọi ở đâu trong `backend/app` (grep 06/11/2026); `ArchitectureTest` bắt buộc mọi model tenant dùng workspace scope kiểu fail-closed (thiếu ngữ cảnh tenant → lỗi, không trả dữ liệu).

## 4. AI

- Khóa API chỉ ở `.env` hoặc DB mã hóa, không bao giờ trả về API/log; base URL tùy chỉnh qua `SsrfGuard`.
- Không gọi provider trong HTTP request; mọi lời gọi qua workflow/queue; ngân sách giữ chỗ trước, không oversell khi đồng thời (test MySQL nhiều tiến trình); cùng call key không chạy hai lần.
- **Sửa trong Phase 9 (P0 từ audit Phase 3, F15)**: provider trả kết quả nhưng không có `usage` trước đây bị tính 0 đồng (dùng AI miễn phí). Nay chốt theo ước tính đã giữ chỗ, đánh dấu `billing_uncertain`, credit settle ở trạng thái `uncertain`. Test: "charges the reservation estimate when a provider answers without usage".
- Prompt injection: nội dung website/nguồn được đóng khung là dữ liệu; verdict fact-check do `VerdictRules` quyết định; Agent không có tool nguy hiểm, mức tự động 3 chỉ bật bằng xác nhận của người.

## 5. WordPress connector và plugin

- HMAC-SHA256 `SITEX-HMAC-V1` khóa riêng từng site, timestamp ±300 giây, nonce chống replay, response ký; ghép nối cần admin WordPress xác nhận; không lưu mật khẩu WP Admin; plugin không chứa API key AI.
- Không ghi đè bài đã sửa trên WordPress khi chưa có quyết định của người (ADR-019); đăng có khóa idempotent và đối chiếu `/posts/lookup` trước khi ghi lại.
- E2E với WordPress thật đã chạy ở Phase 2 (82/82, gồm chữ ký sai/replay/timestamp cũ/không ký bị từ chối, lọc XSS). Phase 6 trên WordPress thật: **chưa chạy** (môi trường chặn tải WordPress).

## 6. Billing và installer

- Billing: không tin client; QR chỉ là hướng dẫn chuyển khoản; webhook giả/cũ/không ký bị từ chối; sai số tiền/tiền tệ → duyệt tay; refund đảo bút toán; không hardcode giá; AI không có quyền thay đổi billing.
- Installer (chi tiết INSTALLER-SECURITY.md): token 192-bit chỉ lấy được qua SSH (`sitex:install:token`), lưu dạng sha256, giới hạn tần suất; khóa nhiều tín hiệu (xóa file khóa vẫn 403); secret tạm mã hóa bằng khóa tạm 0600 và bị xóa sau khi cài; không tự ghi đè DB có dữ liệu; không chạy lệnh root; người đầu tiên mở domain **không** chiếm được Super Admin khi không có token. E2E thật: cài xong → `/install` và API trả 403.
- Upgrade Wizard: chỉ Super Admin, nhập lại mật khẩu, từ chối migration phá dữ liệu, cần bản sao lưu gần đây; không tải/ghi đè mã nguồn từ web.
- Khôi phục sao lưu: chỉ qua SSH, không có nút trên web; restore-test chỉ vào DB tên `*_restore_test` khác DB đang chạy.

## 7. Phát hiện

| # | Mức | Phát hiện | Trạng thái |
|---|---|---|---|
| SEC-1 | P0 | Provider không trả `usage` → không bị tính tiền (audit Phase 3, F15) | **Đã sửa** Phase 9 + test |
| SEC-2 | P1 | `SITEX_SSRF_ALLOW_HTTP=true` vẫn có hiệu lực ở production nếu bị đặt nhầm (audit Phase 2) | **Đã sửa** Phase 9 + test |
| SEC-3 | P2 | Secret trong DB phụ thuộc duy nhất `APP_KEY`; chưa có envelope encryption và lệnh xoay khóa | MỞ — nợ kỹ thuật đã ghi (SECURITY §5). Giảm thiểu: `.env` 0640, sao lưu mã hóa bằng khóa khác, hướng dẫn không đổi APP_KEY |
| SEC-4 | P2 | Chưa có email thông báo bảo mật (đổi mật khẩu, đăng nhập thiết bị mới, bật/tắt 2FA) (audit Phase 1) | MỞ |
| SEC-5 | P2 | Xóa workspace chỉ đánh dấu `PendingDeletion`; chưa có job xóa hẳn dữ liệu theo hạn giữ → rủi ro tuân thủ dữ liệu cá nhân (Nghị định 13/2023) | MỞ — cần trước khi bán |
| SEC-6 | P3 | SPA customer/admin chưa có Content-Security-Policy | MỞ — thêm ở Nginx khi triển khai (cần thử với Vite build) |
| SEC-7 | P3 | Plugin: khóa idempotent dùng `add_option` không nguyên tử tuyệt đối; multisite chưa khai báo (audit Phase 2) | MỞ — Cloud đã đối chiếu `/posts/lookup` trước khi ghi lại nên không tạo trùng trong các test hiện có |
| SEC-8 | Thông tin | Chưa có pentest độc lập, DAST, hay kiểm thử với AI/WordPress/GSC/ngân hàng thật | BLOCKED — cần môi trường staging và tài khoản thật |

Không có phát hiện P0/P1 nào còn mở.

## 8. Khuyến nghị trước khi bán

1. Pentest độc lập (tập trung tenant isolation, connector, billing, installer) trên staging.
2. Làm SEC-5 (job xóa dữ liệu theo hạn giữ + xuất dữ liệu khi khách yêu cầu) và SEC-4.
3. Đặt CSP cho SPA ở Nginx; bật `SESSION_SECURE_COOKIE=true`, HTTPS bắt buộc.
4. Lên kế hoạch envelope encryption + xoay khóa (SEC-3) trước khi số khách lớn.
5. Chạy `composer audit` và `npm audit` trong CI cho mỗi bản phát hành.
