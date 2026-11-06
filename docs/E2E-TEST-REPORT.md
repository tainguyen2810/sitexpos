# Báo cáo kiểm thử đầu-cuối (E2E) — Phase 9

Ngày chạy: 06/11/2026, trên máy phát triển (container Linux). Mọi kết quả dưới đây là **lần chạy thật**; chỗ nào chưa chạy được thì ghi rõ lý do.

## Quy ước trạng thái

| Trạng thái | Nghĩa |
|---|---|
| **PASS** | Chạy qua trình duyệt thật (Chromium/Playwright) hoặc CLI thật, với backend thật, MySQL 8.0.46 thật, Redis thật, Horizon thật. Không giả lập phần đang được kiểm tra |
| **PARTIAL** | Một phần luồng PASS như trên, phần còn lại chỉ có test tự động hoặc bị chặn |
| **TESTED** | Có feature test tự động (Pest) chạy qua HTTP kernel + DB thật (SQLite và MySQL), nhưng hệ thống bên ngoài (AI provider, WordPress, Google, ngân hàng) là bản giả (`FakeProvider`, `FakeWordPress`, `Http::fake`). **Không** coi là PASS |
| **BLOCKED** | Cần hệ thống bên ngoài thật không có trong môi trường này |

Theo yêu cầu "không đánh dấu PASS nếu chỉ kiểm tra UI hoặc dữ liệu giả", không luồng nào dùng AI/WordPress/GSC/ngân hàng giả được ghi PASS.

## Môi trường

| Thành phần | Chi tiết |
|---|---|
| Bản cài "production" (E2E installer) | Mã nguồn sao chép vào thư mục nháp, cài bằng `/install` thật: `APP_ENV=production`, MySQL `sitex_e2e`, Redis, `QUEUE_CONNECTION=redis`, Horizon + `schedule:work` chạy nền, `MAIL_MAILER=log` |
| Bản dev có dữ liệu demo | `backend/` với `DB_DATABASE=sitex_perf` (DemoSeeder, 3 site, 365 ngày GSC demo), `APP_ENV=local`, queue sync |
| Frontend | Vite dev server cho SPA customer và admin, trỏ `VITE_API_BASE_URL` vào backend tương ứng |
| Trình duyệt | Chromium + Playwright có sẵn trong container, viewport desktop và 390×844 |

## Kết quả theo luồng

| # | Luồng | Trạng thái | Bằng chứng |
|---|---|---|---|
| 1 | Fresh Install → Super Admin → Setup Wizard | **PASS** | Trình duyệt chạy đủ 7 bước `/install` trên DB trống (kiểm tra môi trường → DB → ứng dụng → Redis → Super Admin → cài → khóa). Kết quả: 160 bảng; Super Admin lưu bcrypt; `.env` quyền 0640; secret tạm bị xóa; log không chứa mật khẩu. Sau đó đăng nhập admin → 2FA (TOTP tính thật) → dải nhắc "chưa hoàn tất thiết lập" → `/setup`: bước 1 chuyển `COMPLETE` sau khi lưu, "Tạo bản nháp gói chuẩn" chạy, nút "Xác nhận hoàn tất" bị khóa khi còn bước bắt buộc. Các trang Health/Upgrade/Backups/Alerts hiển thị không lỗi console; mobile `scrollWidth` = 390. Các bước cần dịch vụ ngoài (AI key, SMTP thật, thanh toán, Google) **chưa** đi tới `COMPLETE` vì không có tài khoản thật |
| 2 | Register → Workspace → WordPress Pairing | **PARTIAL** | `scripts/e2e/customer.js` (lần chạy này): khách bị chuyển về `/login` → đăng ký → link xác minh email lấy từ log mailer → onboarding → tạo workspace → mời thành viên → tạo workspace thứ hai, chuyển workspace → đăng xuất, đăng nhập chủ tenant khác → mở URL workspace của tenant kia → **trang 404, không lộ dữ liệu** → đăng nhập VIEWER: thấy danh sách thành viên, **không** có nút mời. 9/9 bước ✓. Lỗi console chỉ là 401 (`/me` khi chưa đăng nhập) và 404 (truy cập chéo tenant) — đúng thiết kế. **Pairing WordPress: BLOCKED** trong phiên này (proxy chặn tải WordPress: `wordpress.org`, `downloads.wordpress.org`, `github.com/WordPress` → 403). Pairing đã từng PASS với WordPress thật ở Phase 2 (82/82 check, WP 6.8.3 và 6.4.5 — `docs/PHASE2-REPORT.md`) và có `Feature/Sites/PairingTest.php` |
| 3 | Keyword Discovery → Topic Cluster → Content Plan | **TESTED** | `Feature/Research/ResearchPipelineTest.php` (khám phá, không bịa search volume, idempotent khi chạy lại, quota, AI lỗi vẫn ra ý tưởng theo luật, robots.txt). Nguồn dữ liệu thật (GSC, DataForSEO, crawl site khách) **BLOCKED** |
| 4 | Research → Outline → AI Writer → Fact-Check | **TESTED** | `Feature/Writer/WriterPipelineTest.php`, `WriterSafetyTest.php`, `WriterReviewTest.php`, `Unit/Writer/WriterRulesTest.php` với `FakeProvider`. AI thật **BLOCKED** (không có API key; quy tắc dự án cấm gọi API thật trong test) |
| 5 | Image → SEO Audit → Publish to WordPress | **TESTED** | `Feature/Phase6/{MediaTest,SeoTest,PublishingTest}.php` với `FakeWordPress`. WordPress thật cho Phase 6 **BLOCKED** (như luồng 2) |
| 6 | Subscription → Payment → Credit Settlement | **TESTED** | `Feature/Billing/PaymentFlowTest.php` (đơn chờ + VietQR không được tin là đã trả, webhook ký kích hoạt đúng một lần, webhook giả/cũ/không ký bị từ chối, sai số tiền → duyệt tay), `AiCreditsTest.php` (giữ chỗ → chốt chi phí thật → trả phần dư, không trừ hai lần), `SubscriptionLifecycleTest.php`. Cổng thanh toán/ngân hàng thật **BLOCKED** |
| 7 | Connect GSC → Sync → Analytics Dashboard | **TESTED** | `Feature/Analytics/SearchConsoleSyncTest.php` (OAuth, phân trang, checkpoint, quota backoff, idempotent) với `Http::fake`. Dashboard trên dữ liệu demo 148k dòng đã đo trong `PERFORMANCE-REPORT.md`, nhưng đó là dữ liệu demo nên **không** tính PASS. Google thật **BLOCKED** |
| 8 | SEO Agent → Recommendation → Approval → Refresh | **TESTED** | `Feature/Agent/AgentApiTest.php`, `Unit/Agent/AgentPolicyEngineTest.php`, `Feature/Analytics/ContentRefreshTest.php` (refresh qua Writer, chờ người duyệt, cổng Publisher chạy lại, không ghi đè bài đã sửa trên WP). Các màn hình Agent/Analytics đã mở được trên trình duyệt thật với dữ liệu demo (mục dưới), nhưng luồng duyệt → refresh chưa chạy qua trình duyệt |
| 9 | Provider Failure → Retry/Fallback → Correct Credit Accounting | **TESTED** | `Feature/AiGateway/AiGatewayTest.php`: rate limit → backoff → model kế tiếp; timeout không chắc tính tiền → không tự retry; circuit mở/half-open; cùng call key không chạy hai lần. `BudgetTest.php`: giữ chỗ/chốt/trả, không oversell khi đồng thời (MySQL, nhiều tiến trình), reservation hết hạn chốt theo ước tính. Provider thật **BLOCKED** |
| 10 | WordPress Offline → Retry → No Duplicate Publish | **TESTED** | `Feature/Phase6/PublishingTest.php`: "never duplicates a post when the first write times out: it reconciles with WordPress first", "retries with backoff when the site is offline and fails after max attempts without looping". Phase 2 E2E thật đã kiểm "đăng thử ×3 không tạo trùng". WordPress thật trong phiên này **BLOCKED** |
| 11 | Subscription Expired → Entitlements Enforced | **TESTED** | `SubscriptionLifecycleTest.php` (chưa trả → grace → expired, không xóa dữ liệu), `EntitlementsAdminTest.php` (enforcement `off` không đổi hành vi; bật thì backend chặn theo gói). Lưu ý: mặc định production `enforcement=off` — phải bật có chủ đích khi bán |
| 12 | Installer Locked → Reinstallation Attempt Rejected | **PASS** | Trên bản cài thật sau luồng 1: `GET /install` → 403, mọi `POST /install/api/*` → 403; khóa nhiều tín hiệu (`installed.json`, `SITEX_INSTALLED`, `system.installed_at` trong DB, hoặc đã có Super Admin — bất kỳ tín hiệu nào cũng 403); `InstallerTest` kiểm cả trường hợp xóa file khóa vẫn 403. `Feature/Installer/InstallerTest.php` (18 test) cũng kiểm |
| 13 | Upgrade Wizard → Compatibility Check → Safe Upgrade | **PARTIAL** | Thật trên MySQL (bản cài E2E): `sitex:upgrade:check` → "Nothing to upgrade", mã thoát 1; `--json` → 29/29 yêu cầu đạt, 0 migration chờ, 0 migration phá dữ liệu, bản sao lưu gần nhất đã kiểm. Trang Admin → Nâng cấp hiển thị đúng và khóa nút khi không có gì để nâng cấp. Một lần nâng cấp thật từ phiên bản cũ lên mới **chưa chạy** (chưa có bản phát hành thứ hai). Đường nâng cấp/từ chối migration phá dữ liệu: `Feature/System/SystemAdminTest.php`, `BackupTest.php` (mã thoát 0/1/2, `--record`) |
| 14 | Backup → Restore Verification | **PASS** | MySQL 8.0.46 thật: `sitex:backup:run --verify` (332 KB, mọi kiểm tra OK) → `sitex:backup:verify --restore-test=sitex_e2e_restore_test` (160 bảng nạp vào DB nháp rồi xóa DB nháp) → khôi phục tay theo `BACKUP-RESTORE.md` §4 vào DB mới: 160 bảng, Super Admin còn nguyên. Đẩy bản sao lưu lên S3/SFTP thật **BLOCKED** |

Tổng: **PASS 3** (1, 12, 14) · **PARTIAL 2** (2, 13) · **TESTED 9** (3–11) · **FAIL 0**. Mọi phần BLOCKED đều do thiếu hệ thống bên ngoài thật, không phải do lỗi đã biết.

## Kiểm tra mọi màn hình trên trình duyệt thật (Phase 9)

`scripts/e2e/screens-smoke.js` đăng nhập vào backend thật (MySQL, `DemoSeeder` — dữ liệu demo, nên **không** dùng để kết luận nghiệp vụ) và mở lần lượt từng màn hình; mỗi màn hình phải có tiêu đề, không lỗi JavaScript, không response 5xx, không kẹt trạng thái đang tải, và không tràn ngang ở 390 px.

| Ứng dụng | Kết quả lần đầu | Lỗi tìm thấy | Kết quả sau khi sửa |
|---|---|---|---|
| Khách hàng (51 màn hình, gồm toàn bộ Phase 8 Analytics/Agent) | 50/51; tràn ngang ở 390 px trên Analytics tổng quan (840 px), Agent (591 px), Decay (698 px), Refresh (490 px) | (1) Trang Thử nghiệm SEO **sập**: frontend coi `external_factors` là mảng, API trả chuỗi → `join is not a function`. (2) Lưới `grid … lg:grid-cols-N` không có cột cơ sở nên thẻ bị nội dung kéo rộng; bảng không bọc `overflow-x-auto` | **51/51**, không tràn ngang ở cả 51 màn hình |
| Admin (28 màn hình, gồm Hệ thống/Setup) | 28/28; tràn ngang ở Báo cáo billing (405 px) | Form chọn ngày không xuống dòng, biểu đồ cột 30 ngày rộng hơn màn hình | **28/28**, không tràn ngang |

Cách sửa: thêm `grid-cols-1` cho mọi lưới responsive thiếu cột cơ sở (122 chỗ) và bọc 23 bảng trong `overflow-x-auto` (79 file); sửa kiểu `external_factors: string | null`. Kịch bản `scripts/e2e/admin.js` (OWNER bị từ chối ở app admin → Super Admin buộc bật 2FA → TOTP → dashboard → chi tiết workspace → xem với tư cách hỗ trợ chỉ đọc → kết thúc → audit) chạy lại **8/8 bước ✓** (sửa kịch bản: tìm workspace bằng ô tìm kiếm vì danh sách demo có 52 workspace).

Điều này đóng khoảng trống "Phase 8 chưa có E2E trình duyệt" ở mức hiển thị; luồng nghiệp vụ Phase 8 với Google thật vẫn BLOCKED.

## Kiểm thử tự động liên quan (đã chạy)

- Toàn bộ test backend trên **MySQL 8.0.46** (4 tiến trình song song): 520/521 pass; 1 lỗi do test được thêm giữa lúc chạy, pass khi chạy lại (chi tiết `PERFORMANCE-REPORT.md` §4). Kết quả `scripts/check.sh` cuối cùng: xem `PHASE9-FINAL-AUDIT.md`.
- `Feature/Security/ThreeTenantIsolationTest.php`: 3 tenant đầy đủ dữ liệu Phase 4–8, chủ từng tenant gọi 64 route GET tenant, khẳng định không xuất hiện ULID nào của tenant khác (10.756 assertion).
- `Feature/Tenancy/CrossTenantIsolationTest.php`: mọi route tenant truy cập chéo → 404.

## Sửa trong lúc chạy E2E

- `scripts/e2e/customer.js`: chọn chính xác dòng email trong danh sách mời (toast "Đã gửi lời mời tới …" cũng chứa email nên Playwright báo trùng phần tử). Không phải lỗi sản phẩm.
- Lỗi thật tìm được nhờ chạy trên MySQL (không phải E2E nhưng cùng đợt): health "Thanh toán" dùng sai tên cột `created_at` của webhook (đúng là `received_at`), SQLite che mất; đã sửa và thêm test hồi quy.

## Cách chạy lại

```bash
# Installer + admin (cần MySQL, Redis, Chromium): xem docs/INSTALLATION-WIZARD.md §7
# Customer (backend dev có DemoSeeder + Vite customer):
SITEX_APP_URL=http://127.0.0.1:5173 SITEX_LOG=backend/storage/logs/laravel-$(date +%F).log \
  node scripts/e2e/customer.js /tmp/shots
# WordPress thật: wordpress-plugin/tests/e2e/README.md
```

## Việc cần làm trước khi bán (để chuyển TESTED → PASS)

1. Staging có WordPress thật (6.4 và bản mới nhất): chạy `wordpress-plugin/tests/e2e/run-e2e.mjs` + kịch bản xung đột/không trùng của Phase 6.
2. Một API key AI thật với ngân sách nhỏ: chạy một bài viết đầu-cuối, đối chiếu credit với hóa đơn provider.
3. Một property GSC thật: kết nối, đồng bộ, so số liệu dashboard với giao diện Search Console.
4. Tài khoản ngân hàng/cổng thanh toán thật (sandbox nếu có): một giao dịch nhỏ, xác nhận webhook ký và đối soát.
5. Một lần nâng cấp thật từ bản phát hành 1.0.0 lên bản kế tiếp trên staging.
