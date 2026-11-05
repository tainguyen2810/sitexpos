# Báo cáo Phase 2 — WordPress Connector (05/11/2026)

Phạm vi: plugin WordPress **SiteX Post** (slug & text domain `sitex-post`), module **Sites** trên Cloud, giao diện quản lý website, tài liệu. Nhánh `feature/phase2-wp-connector` tách từ `main` (Phase 1). **Không** đụng nhánh `feature/demo-ui`, **không** deploy production, **không** bắt đầu Phase 3.

## 1. Kết quả kiểm tra (đã chạy thật)

| Lệnh | Kết quả |
|---|---|
| `scripts/check.sh` (đã thêm bước plugin) | **All checks passed** (exit 0) |
| ├ `vendor/bin/pint --test` | passed |
| ├ `vendor/bin/phpstan analyse` (Larastan level 6) | `[OK] No errors` |
| ├ `vendor/bin/pest` (SQLite) | **149 passed** (679 assertions) — Phase 1 là 112 |
| ├ `npm run lint` / `typecheck` | 0 lỗi |
| ├ `npm run test` (Vitest) | **22 passed** (4 file) — Phase 1 là 16 |
| ├ `npm run build` | build `dist/customer` và `dist/admin` thành công |
| └ `wordpress-plugin/scripts/build-plugin.sh` | `php -l` sạch; kiểm tra cú pháp PHP 7.4: 23 file, 0 lỗi; unit test chữ ký plugin 11/11; bản dịch 124/124; tạo `dist/sitex-post-1.0.0.zip` (56 KB) |
| `pest` với `DB_CONNECTION=mysql` (MySQL 8.0.46) | **149 passed** |
| `migrate:fresh` trên MySQL 8.0.46 | thành công (sau khi sửa một tên index dài hơn 64 ký tự) |
| `composer audit` / `npm audit --omit=dev` | không có cảnh báo / 0 lỗ hổng |
| **E2E với WordPress thật** `wordpress-plugin/tests/e2e/run-e2e.mjs` | **82/82 check đạt** trên WordPress **6.8.3** (+ WooCommerce/Yoast giả lập) và **6.4.5**, PHP 8.3.6, MySQL 8.0 |
| Smoke test giao diện `scripts/e2e/sites.js` (Vite + Cloud + WordPress thật) | chạy hết luồng, 10 ảnh chụp |
| `WP_DEBUG` (log) sau E2E | 0 notice/warning từ plugin |

E2E chạy trên app thật: Laravel (`php -S`) + queue worker + MySQL, WordPress cài từ mã nguồn, ghép nối qua **wizard wp-admin thật** bằng Playwright. Các check gồm: từ chối URL nội bộ (SSRF: `10.0.0.5`, `169.254.169.254`); wizard không có ô mật khẩu; bước xác nhận hiển thị workspace; site chuyển `connected`; phát hiện phiên bản, tác giả, WooCommerce/Yoast; đồng bộ chuyên mục/thẻ (tên tiếng Việt), bài, trang, sản phẩm có giá/SKU, danh mục sản phẩm; **đăng thử 3 lần → đúng 1 bài**, 2 ảnh tải lên một lần, ảnh đại diện, placeholder ảnh inline được thay bằng URL thật, bài nháp đúng tác giả; SEO ghi vào Yoast (6.8) hoặc thẻ meta tích hợp (6.4); gửi trực tiếp request có chữ ký: cùng idempotency key → cùng ID bài, **replay bị từ chối**, sửa body bị từ chối, timestamp cũ 15 phút bị từ chối, sai secret bị từ chối, không ký bị từ chối, header SiteX không mở được REST core `/wp/v2/posts`; XSS (script, `onerror`, `javascript:`, iframe, HTML trong tiêu đề) bị lọc; đổi khóa rồi health check bằng khóa mới; ngắt kết nối từ Cloud (site 6.8) và **từ wp-admin** (site 6.4) → Cloud hiện `revoked`, plugin xóa secret, request ký bằng khóa cũ và khóa trước đó đều bị 401, Cloud từ chối đăng, WordPress không có bài mới.

**E2E tìm ra 3 lỗi mà test giả lập không thấy, đã sửa:**
1. WordPress gọi lại `permission_callback` cho cùng request khi dựng header `Allow` → plugin "tiêu" nonce lần hai, xóa trạng thái đã xác minh, nên **mọi response bị gửi đi không có chữ ký** và Cloud từ chối hết. Sửa: plugin nhận diện request đã xác minh.
2. Ô chọn tài khoản đăng bài trống (`WP_User_Query` không AND mảng capability). Sửa: lọc từng user theo `publish_posts` + `upload_files`.
3. Index MySQL tên dài hơn 64 ký tự làm migration hỏng trên MySQL. Đã đặt tên ngắn.
Thêm: nếu mất phản hồi khi đổi khóa, Cloud thử khóa mới và chỉ chuyển khi WordPress đã nhận (tránh lệch khóa) — có test riêng; Horizon chưa lắng nghe queue `connector` (job sẽ không bao giờ chạy trên production) — đã thêm supervisor `supervisor-connector`, `retry_after` mặc định 330 s.

## 2. Quyết định và sai khác so với thiết kế Phase 0

| Chủ đề | Thiết kế Phase 0 | Phase 2 làm | Lý do / ghi chú |
|---|---|---|---|
| Chữ ký | Ed25519 hai chiều | **Khóa riêng từng site + HMAC-SHA256 `SITEX-HMAC-V1`**, ký cả response, nonce + timestamp | Theo yêu cầu Phase 2 (per-site token + HMAC). ADR-007 và SECURITY §6 đã cập nhật |
| Mã hóa secret | Envelope encryption với `SITEX_MASTER_KEY` | `Crypt` của Laravel (`APP_KEY`) | Chưa có hạ tầng master key. **Nợ kỹ thuật** — SECURITY §5 |
| Bảng index bài | `site_posts_index` | `site_content_items` (+ trang, sản phẩm) và thêm `site_publications`, `site_media`, `site_activity_logs` | DATABASE §3.6 |
| Xóa site | soft delete | xóa cứng sau khi thu hồi khóa | Bài trên WP không bị ảnh hưởng; audit log còn giữ |
| Trạng thái | có `disconnected` | chỉ `revoked` + `revoked_by` | Đơn giản hóa |
| Ghép nối | plugin nhập mã rồi ghép ngay | thêm bước **xem trước + quản trị WP xác nhận** và chọn tài khoản đăng bài | Theo yêu cầu "pairing có xác nhận" |
| Ảnh | multipart | body nhị phân thô + query (`idempotency_key`, `filename`, `alt`…) | Ký được toàn bộ body; plugin không tải URL tùy ý |
| `/connector/v1/events` | có | **chưa làm** | Không thuộc yêu cầu Phase 2; đồng bộ dùng nút thủ công |

## 3. Thay đổi chính (138 file so với `main`: 109 thêm, 28 sửa, 1 xóa)

**Backend — `backend/app/Modules/Sites/`** (module mới)
- Migration 9 bảng (DATABASE §3.6); model `Site`, `SitePairing`, `SiteCredential`, `SiteHealthCheck`, `SiteTaxonomy`, `SiteContentItem`, `SitePublication`, `SiteMedia`, `SiteActivityLog` (đều `BelongsToWorkspace`).
- `Connector/`: `Signer` (giao thức), `NonceStore`, `WordPressClient` (SsrfGuard + IP pinning, không redirect, giới hạn response, xác minh chữ ký response, ánh xạ lỗi, ghi nhật ký), `ConnectorException`.
- `Actions/`: `CreateSite`, `IssuePairingCode`, `ResolvePairing`, `CompletePairing`, `RunHealthCheck`, `SyncSite`, `UploadMedia`, `PublishPost`, `TestPublish`, `RotateCredentials`, `RevokeSite`, `DeleteSite`, `RecordHeartbeat`.
- `Jobs/` (queue `connector`, `TenantJob`, cập nhật `job_runs`), `Console/` (`sitex:sites:health`, `sitex:sites:prune`), `Policies/SitePolicy`, `Http/` (controller, request, resource, middleware `VerifyConnectorSignature`, `LimitConnectorBody`), `routes.php`, `SitesServiceProvider` (rate limiter).
- Sửa nhỏ: `Workspace::sites()`, dashboard (số website), `ApiExceptionRenderer` (`connector/*`), `config/sitex.php` (khối `connector`), `config/horizon.php`, `config/queue.php`, `routes/console.php` (scheduler), `.env.example`, `lang/{vi,en}/sites.php`.
- Test: `tests/Unit/Sites/SignerTest.php` (test vector dùng chung), `tests/Feature/Sites/*` (quản lý site, ghép nối, chữ ký, thao tác connector), `tests/Support/FakeWordPress.php`; mở rộng `CrossTenantIsolationTest` (site của tenant khác → 404 ở mọi route `{site}`) và arch test.

**Plugin — `wordpress-plugin/sitex-post/`** (PHP 7.4+, WP 6.4+): `Security/` (Signer, NonceStore, RequestVerifier, HtmlSanitizer), `Rest/RestController`, `Content/` (PostService, MediaService, IndexService), `Seo/` (Yoast, Rank Math, Native), `Admin/AdminPage` (wizard 3 bước, nhật ký, chẩn đoán), `Cloud/CloudClient`, `Support/`, `uninstall.php`, `languages/` (`.pot`, `vi.po`, `vi.mo`), `readme.txt`. Công cụ: `scripts/build-plugin.sh`, `scripts/i18n.php`, `scripts/check-php74.php`, `tests/unit/SignerTest.php`, `tests/e2e/` (install-wp, run-e2e, wp-inspect, README), `tests/stubs/`.

**Frontend**: `shared/api/sites.ts`; trang `SitesPage.vue` (danh sách + thêm website + mã kết nối), `SiteDetailPage.vue` (trạng thái, phiên bản, tính năng, cảnh báo; kiểm tra kết nối, đồng bộ, đăng thử, đổi khóa, tạo mã mới, ngắt kết nối, xóa; tab nội dung/chuyên mục/nhật ký/lịch sử kiểm tra), `PairingCodePanel.vue`; menu "Website" bật; dashboard hiển thị số website; locale vi/en; `tests/sites.spec.ts`; `scripts/e2e/sites.js`.

**Tài liệu**: ARCHITECTURE (ADR-007), SECURITY §5–6, API-CONTRACTS §2.3/§4/§5, DATABASE §3.6/§6, DEPLOYMENT §6, ROADMAP Phase 2, CLAUDE.md, mới: `WORDPRESS-PLUGIN.md` (hướng dẫn cài plugin), `PHASE2-REPORT.md`, `wordpress-plugin/tests/e2e/README.md`.

## 4. Checklist nghiệm thu

| Yêu cầu | Trạng thái | Bằng chứng |
|---|---|---|
| Kết nối được website WordPress test | ✅ | E2E: WP 6.8.3 và 6.4.5 ghép nối qua wizard thật |
| Tạo, cập nhật bài, upload ảnh, đồng bộ metadata | ✅ | E2E: đăng thử ×3, ảnh featured + inline, SEO Yoast/tích hợp; đồng bộ chuyên mục/thẻ/bài/trang/sản phẩm |
| Retry không tạo bài trùng | ✅ | E2E: 3 lần → 1 bài, 2 ảnh; request trực tiếp cùng key → cùng ID; Pest: Cloud mất response rồi gửi lại vẫn 1 bài |
| Sau khi ngắt kết nối không còn quyền ghi | ✅ | E2E: ngắt từ Cloud và từ wp-admin → 401 với khóa cũ, Cloud trả 409 |
| Có integration test và tài liệu cài plugin | ✅ | Pest Sites (35 test) + cross-tenant cho route site, E2E, `docs/WORDPRESS-PLUGIN.md`, `readme.txt` |
| Không hỏi mật khẩu admin WP / không gửi API key AI | ✅ | Wizard không có ô mật khẩu (E2E); arch test chặn Sites phụ thuộc AI |
| HTTPS, token từng site, HMAC, timestamp, nonce, chống replay, revoke/rotate | ✅ (HTTPS bắt buộc trong code, E2E chạy HTTP cục bộ qua cờ chỉ dùng cho test) | Pest + E2E |
| Kiểm tra REST API, phiên bản, khả năng plugin | ✅ | Health check: phiên bản, features, issues, quyền tác giả; tab Chẩn đoán |
| WooCommerce nếu có | ⚠️ một phần | Đồng bộ sản phẩm chạy với **WooCommerce giả lập**, chưa thử plugin WooCommerce thật |
| Yoast / Rank Math adapter | ⚠️ một phần | Yoast giả lập (chỉ hằng phiên bản) + ghi meta; **Rank Math chưa chạy thật** |
| Chống SSRF, XSS, HTML không an toàn, ghi trái phép | ✅ | E2E + Pest |
| Giao diện SaaS: danh sách, thêm, trạng thái, kiểm tra, đồng bộ thủ công, ngắt kết nối | ✅ | `scripts/e2e/sites.js` + ảnh chụp |
| ROADMAP: chạy trên PHP 7.4 | ⚠️ | Chỉ kiểm tra cú pháp tĩnh (php-parser chế độ 7.4 + danh sách hàm PHP 8); **chưa chạy PHP 7.4 thật** (máy không có PHP 7.4) |
| ROADMAP: Plugin Check không lỗi bảo mật | ❌ chưa chạy | wordpress.org bị chặn trong môi trường này |

## 5. Lỗi / hạn chế còn lại (báo cáo trung thực)

1. **Chưa chạy trên PHP 7.4 thật** và **chưa chạy Plugin Check / PHPCS WordPress** — cần làm trước khi phát hành plugin.
2. WooCommerce và Yoast trong E2E là **bản giả lập**; Rank Math chưa được thử. Cần thử với plugin thật.
3. E2E chạy qua HTTP cục bộ (bật `SITEX_SSRF_ALLOW_HTTP` và `SITEX_POST_ALLOW_INSECURE` chỉ cho test). Đường HTTPS + IP pinning trên Internet thật chưa được thử với một website công khai.
4. Secret site mã hóa bằng `APP_KEY` thay vì envelope encryption — xoay `APP_KEY` phải giữ khóa cũ (`APP_PREVIOUS_KEYS`) hoặc xoay khóa mọi site.
5. URL Cloud mặc định trong plugin là `https://api.sitexpost.com` — **tên miền thật chưa chốt (Q2)**; phải sửa trước khi phát hành.
6. Giới hạn ghép nối 10 lần/giờ/IP có thể chặn nhiều website trên cùng một hosting chia sẻ IP trong một giờ.
7. Đồng bộ nội dung chỉ chạy khi bấm "Đồng bộ ngay" (health check thì tự động 30 phút/lần); chưa có webhook `/events` từ plugin.
8. `FakeWordPress` trong Pest không mô phỏng việc WordPress gọi lại `permission_callback`; lỗi loại này chỉ E2E thật bắt được.
9. Bản dịch tiếng Anh của frontend chỉ đầy đủ cho màn hình Website (các màn hình khác vẫn fallback tiếng Việt như Phase 1).
10. `PublishPost` mới được dùng bởi "Đăng bài thử"; đăng bài thật từ nội dung AI thuộc Phase 8.

## 6. Cách tự kiểm tra lại

```bash
scripts/check.sh                                     # toàn bộ gate (backend, frontend, plugin)
wordpress-plugin/scripts/build-plugin.sh             # zip plugin ở wordpress-plugin/dist/
# E2E với WordPress thật và smoke test giao diện: wordpress-plugin/tests/e2e/README.md, scripts/e2e/README.md
```
