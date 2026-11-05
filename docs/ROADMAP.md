# SiteX Post — Roadmap & Checklist nghiệm thu

Nguyên tắc: **chỉ làm phase được yêu cầu**, không tự chuyển phase. Mỗi phase giao đủ **migration + API + UI + tests**, chạy test và static analysis, báo cáo phần xong / chưa xong. Không tuyên bố test pass khi chưa chạy.

## Definition of Done (áp dụng mọi phase)

- [ ] Migration chạy được trên MySQL 8.4 từ DB rỗng và `migrate:rollback` cho migration mới của phase.
- [ ] Mọi endpoint mới có Feature test: happy path, validate, phân quyền theo vai trò, **cross-tenant trả 404**.
- [ ] `composer test` (Pest) pass, `composer analyse` (Larastan) pass ở level đã cấu hình, `composer lint` (Pint --test) pass.
- [ ] Frontend: `npm run typecheck` (vue-tsc), `npm run lint` (ESLint), `npm run test` (Vitest), `npm run build` đều pass.
- [ ] UI có loading / empty / error state và hiển thị đúng ở 375px, 768px, 1280px.
- [ ] Chuỗi hiển thị qua i18n (`vi`), không hardcode.
- [ ] Hành động nhạy cảm có audit log.
- [ ] Cập nhật tài liệu liên quan (API-CONTRACTS, DATABASE...) nếu lệch thiết kế.
- [ ] Báo cáo phase: đã làm, chưa làm, rủi ro, lệnh test đã chạy kèm kết quả thật.

---

## Phase 0 — Khảo sát & thiết kế ✅ (tài liệu này)

- [x] Khảo sát môi trường, xác minh phiên bản dependencies
- [x] ARCHITECTURE, DATABASE, SECURITY, API-CONTRACTS, ROADMAP, DEPLOYMENT, CLAUDE.md
- [ ] Repository GitHub được tạo và đẩy code lên (chờ người dùng tạo repo)

---

## Phase 1 — Nền tảng: monorepo, xác thực, workspace, phân quyền, khung UI ✅ (05/11/2026)

> Trạng thái chi tiết và lệnh đã chạy: xem `docs/PHASE1-REPORT.md`. Ô `[ ]` = chưa làm/chưa kiểm chứng được, có ghi lý do.

**Mục tiêu**: Có một ứng dụng chạy được end-to-end: đăng ký → xác minh email → tạo workspace → mời thành viên → phân quyền; Super Admin đăng nhập (2FA) vào dashboard riêng. Tenant isolation được thực thi và kiểm thử. **Chưa** có AI, billing, WordPress.

### 1A. Monorepo & tooling
- [x] Cấu trúc `backend/`, `frontend/`, `wordpress-plugin/` (chỉ placeholder README), `docs/`, `scripts/`.
- [x] Laravel 13 với namespace gốc `SiteXPost\` (composer psr-4, `bootstrap/app.php`).
- [x] Hạ tầng module: `ModuleServiceProvider` tự nạp routes/migrations/policies của từng module.
- [x] Pest 4 + Larastan (level 6) + Pint; script `composer test|analyse|lint|check`.
- [x] Vite 8 + Vue 3.5 + TS 6.0 + Tailwind 4, 2 entry (`customer`, `admin`); ESLint, vue-tsc, Vitest; `npm run check`.
- [x] `scripts/check.sh` và `scripts/check.ps1` chạy toàn bộ kiểm tra (Linux/Windows).
- [x] `.env.example` đầy đủ, không có giá trị thật; `.editorconfig`, `.gitignore`.
- [ ] CI GitHub Actions (MySQL 8.4 + Redis service) — đã viết `.github/workflows/ci.yml` nhưng **chưa chạy** vì chưa có repo GitHub.

### 1B. Kernel
- [x] `TenantContext`, middleware `ResolveWorkspace`, trait `BelongsToWorkspace` (global scope **fail-closed**), job middleware `RestoreTenantContext`.
- [x] Error envelope + exception handler theo API-CONTRACTS §1.2; `X-Request-Id`.
- [x] Base `FormRequest` cấm `workspace_id`/`platform_role` trong input.
- [x] Rate limiter theo user, workspace, auth (SECURITY §11).
- [x] Logger processor che secret.
- [x] `SsrfGuard` (unit test với danh sách IP chặn) — dùng từ Phase 2 nhưng làm sẵn vì là Kernel.

### 1C. Identity
- [x] Đăng ký, đăng nhập, đăng xuất, xác minh email, quên/đặt lại mật khẩu (Fortify headless + Sanctum SPA).
- [x] 2FA TOTP (bật, xác nhận, mã khôi phục, challenge).
- [x] Hồ sơ cá nhân, đổi mật khẩu, ngôn ngữ, múi giờ; quản lý phiên.
- [x] Email qua mailer cấu hình `.env` (SMTP/Brevo SMTP); template email tiếng Việt. *(Đã kiểm tra với `MAIL_MAILER=log`; chưa gửi thử qua SMTP thật.)*

### 1D. Workspace & phân quyền tenant
- [x] CRUD workspace; người tạo là OWNER; đúng một OWNER.
- [x] Thành viên: liệt kê, đổi vai trò, xóa, rời workspace; chuyển quyền OWNER (yêu cầu mật khẩu).
- [x] Lời mời qua email (token hash, hết hạn 7 ngày, thu hồi, chấp nhận khi email khớp).
- [x] Enum `WorkspaceRole`, `Permission`, ma trận quyền SECURITY §3.2; Policy cho mọi action.
- [x] `GET /workspaces/{ws}` trả `my_role` + `permissions[]` để UI ẩn/hiện.
- [x] Audit log workspace + màn hình xem audit.

### 1E. Platform (Super Admin)
- [x] `platform_role` trên user; lệnh `php artisan sitex:make-super-admin {email}` (không có tài khoản admin mặc định).
- [x] Bắt buộc 2FA với platform staff.
- [x] Admin API Phase 1 (API-CONTRACTS §3 ✦): dashboard, workspaces, users, suspend/unsuspend, staff, audit logs.
- [x] Impersonation read-only có lý do, banner rõ ràng trên UI, audit đầy đủ, tự kết thúc sau 30 phút.

### 1F. Frontend
- [x] Shell khách hàng: sidebar + topbar, chuyển workspace, menu theo quyền, responsive (drawer mobile), light mode.
- [x] Màn hình: đăng nhập, đăng ký, quên/đặt lại mật khẩu, xác minh email, 2FA challenge, tạo workspace đầu tiên (onboarding), dashboard (thẻ trạng thái onboarding: "Kết nối website — sắp có"), thành viên & lời mời, cài đặt workspace, hồ sơ cá nhân & bảo mật, audit log.
- [x] Shell admin riêng (`admin.html`): dashboard số liệu thật từ DB, danh sách workspace/user, staff, audit log, impersonation.
- [x] Bộ UI dùng chung + EmptyState/LoadingState/ErrorState; toast; xác nhận hành động nguy hiểm.
- [x] vue-i18n với `vi` đầy đủ, `en` khung (fallback `vi`).
- [x] Route guard theo đăng nhập/xác minh email/quyền.

### 1G. Dữ liệu demo (chỉ development)
- [x] `DemoSeeder`: 2 workspace demo (`is_demo=1`), user demo mỗi vai trò, 1 super admin demo — **từ chối chạy** khi `APP_ENV=production` (có test).
- [x] `DatabaseSeeder` production chỉ seed dữ liệu hệ thống bắt buộc (hiện tại: không có).

### Checklist nghiệm thu Phase 1
- [ ] Từ DB rỗng: `php artisan migrate` thành công trên MySQL 8.4. *(Đã chạy migrate + rollback + migrate trên **MySQL 8.0.46** và SQLite; môi trường cloud không tải được MySQL 8.4 — cần kiểm tra trên server/máy dev.)*
- [x] Đăng ký → nhận email xác minh (Mailpit/log mailer ở dev) → xác minh → tạo workspace → thấy dashboard.
- [x] Chưa xác minh email thì không tạo được workspace (403 `email_not_verified`).
- [x] OWNER mời email B vai trò EDITOR → B đăng ký/đăng nhập → chấp nhận → thấy workspace với vai trò EDITOR; EDITOR xem được danh sách thành viên (quyền `members.view` theo SECURITY §3.2) nhưng không có nút mời/đổi vai trò/xóa, không thấy menu Cài đặt và Nhật ký; API trả 403 khi gọi trực tiếp.
- [x] VIEWER gọi API sửa workspace → 403. ADMIN không đổi được vai trò OWNER.
- [x] User workspace A gọi mọi endpoint với ID workspace B / thành viên B / lời mời B → 404 (test tự động phủ toàn bộ route tenant).
- [x] Gửi `workspace_id` trong body → 422.
- [x] Truy vấn model tenant ngoài TenantContext → exception (unit test).
- [x] Đăng nhập sai 6 lần/phút → 429.
- [x] Super admin tạo bằng lệnh artisan; vào admin SPA bị buộc thiết lập 2FA; sau đó thấy dashboard với số liệu thật.
- [x] Impersonation read-only: staff xem được workspace, mọi request ghi bị chặn (403), audit có `impersonator_id`.
- [x] Mọi hành động trong SECURITY §12 thuộc phạm vi Phase 1 có audit log.
- [x] `DemoSeeder` chạy được ở local; với `APP_ENV=production` thoát với lỗi.
- [x] UI: loading/empty/error state hiện đúng; responsive 375/768/1280; tiếng Việt toàn bộ.
- [x] `scripts/check.sh` pass toàn bộ: Pint, Larastan, Pest, ESLint, vue-tsc, Vitest, build.
- [ ] Hướng dẫn cài đặt dev (Windows + Linux) trong README được kiểm tra bằng cách cài từ đầu. *(Đã kiểm tra trên Linux; chưa kiểm tra trên Windows.)*

**Ngoài phạm vi Phase 1**: sites/WordPress, billing/quota, AI, research, nội dung, GSC, deploy production.

### Giao diện demo (05/11/2026)
Nguyên mẫu UI cho mọi màn hình của các phase sau (18 trang khách hàng, 12 trang Super Admin), chạy bằng dữ liệu mẫu trong trình duyệt: `npm run demo` / `npm run demo:admin`. Chỉ dùng để duyệt giao diện và luồng; mỗi phase vẫn phải xây màn hình thật nối API. Xem [DEMO-UI.md](DEMO-UI.md).

---

## Phase 2 — Kết nối WordPress ✦ (đã triển khai, chờ nghiệm thu — xem `PHASE2-REPORT.md`)

### 2A. Bảo mật kết nối
- [x] Ghép nối bằng mã dùng một lần + quản trị WP xem trước và xác nhận (không hỏi mật khẩu WP).
- [x] Khóa riêng từng site + HMAC-SHA256 `SITEX-HMAC-V1` hai chiều, ký cả response (thay Ed25519 — ADR-007 sửa đổi).
- [x] Timestamp ±300 s, nonce dùng một lần (Cloud cache, plugin bảng riêng), xoay khóa có grace 10 phút, thu hồi từ cả hai phía.
- [x] SsrfGuard + IP pinning cho mọi request tới site; giới hạn kích thước response; không theo redirect.
- [x] Không gửi API key AI tới WordPress (arch test).

### 2B. Module Sites (Cloud)
- [x] Thêm/sửa/xóa site, mã ghép nối, health check (thủ công + định kỳ 30 phút), đồng bộ chuyên mục/thẻ/bài/trang/sản phẩm WooCommerce, chỉ mục nội dung + map ID bài WP.
- [x] Tải ảnh (featured + inline), tạo/cập nhật bài idempotent, SEO metadata, đăng bài thử.
- [x] Nhật ký kết nối, lịch sử kiểm tra, trạng thái lỗi; scheduler `sitex:sites:health`, `sitex:sites:prune`; queue `connector`.

### 2C. Plugin `sitex-post`
- [x] Wizard 3 bước, trang đã kết nối, nhật ký, chẩn đoán; ngắt kết nối; uninstall sạch.
- [x] REST `sitex-post/v1` có chữ ký; fallback `?rest_route=`; sanitize HTML; kiểm tra MIME ảnh thật.
- [x] Adapter SEO: Yoast, Rank Math, thẻ meta tích hợp.
- [x] Bản dịch tiếng Việt, readme.txt, script build zip, kiểm tra cú pháp PHP 7.4.

### 2D. Giao diện SaaS
- [x] Danh sách website, thêm website (mã + hướng dẫn), chi tiết (trạng thái, phiên bản, tính năng, cảnh báo), kiểm tra kết nối, đồng bộ thủ công, đăng thử, đổi khóa, ngắt kết nối, xóa; tab nội dung/chuyên mục/nhật ký/lịch sử.

**Nghiệm thu**: ghép nối thành công với WP 6.4 và bản mới nhất (PHP 7.4 và 8.3); request có chữ ký sai/timestamp lệch/nonce lặp đều bị từ chối (test cả hai phía); thu hồi từ Cloud hoặc WP làm request sau thất bại ngay; site trỏ tới IP private bị từ chối; plugin qua `WP_DEBUG` không notice, Plugin Check không lỗi bảo mật.
Kết quả thực tế: xem `PHASE2-REPORT.md` (đã chạy trên WP 6.4.5 và 6.8.3 với PHP 8.3; **chưa** chạy trên PHP 7.4 thật và chưa chạy Plugin Check).

## Phase 3 — AI Gateway & Queue ✦ (đã triển khai, chờ nghiệm thu — xem `PHASE3-REPORT.md`)
> Thứ tự phase đã đổi (ADR-012): AI Gateway & hàng đợi làm trước, gói dịch vụ/thanh toán chuyển sang Phase 4.

### 3A. AI Gateway
- [x] Adapter OpenAI (Responses API), Gemini (`generateContent`), Anthropic (Messages API) bằng HTTP thuần (ADR-011) + fake provider cho test/dev.
- [x] Model registry + capabilities, bảng giá có version (micro-USD / 1M token, ADR-014), tính chi phí theo giá hiệu lực tại thời điểm gọi.
- [x] Routing theo task type (`ai_task_routes`) + chuỗi fallback, chặn theo ngân sách.
- [x] Đo usage: input/output/cached/reasoning token, ảnh, lượt tìm kiếm.
- [x] Timeout, retry có kiểm soát, circuit breaker theo provider/model, fallback.
- [x] Log request (`ai_requests`) không chứa secret/prompt thô (chỉ hash), `SecretRedactor` cho thông báo lỗi.
- [x] Ngân sách theo workspace và toàn hệ thống: reserve → commit/release nguyên tử, cảnh báo ngưỡng, hành động `pause_all`/`block_paid`.
- [x] Super Admin quản lý provider, model, giá, route, ngân sách.

### 3B. Hàng đợi
- [x] Redis + Horizon, các queue: `research`, `writing`, `factcheck`, `media`, `publishing`, `analytics`, `billing`, `notifications` (+ `critical`, `default`, `connector` có sẵn).
- [x] Cấu hình Supervisor mẫu (`deploy/supervisor/`).

### 3C. Workflow engine (ADR-013)
- [x] State machine lưu DB (`workflow_runs/steps/events`), compare-and-set theo `version`.
- [x] Idempotency key, exponential backoff, giới hạn đồng thời theo tenant.
- [x] Hủy, khôi phục sau khi worker chết (lease + `workflows:recover` mỗi phút).
- [x] Failed jobs, dead letter, retry thủ công.
- [x] Không retry lời gọi tốn phí khi không chắc đã bị tính tiền (trừ khi route cho phép).

### 3D. Giao diện
- [x] Khách hàng: trang "Tác vụ AI" (gửi tác vụ thử, xem kết quả, chi phí, hủy/retry).
- [x] Admin: dashboard sử dụng & chi phí AI, cấu hình AI (provider/model/giá/route/ngân sách), theo dõi job (queue, workflow, dead letter).

**Nghiệm thu**: gửi được tác vụ AI thử; token và chi phí được ghi nhận; job nền không chặn HTTP; restart worker không mất workflow; có test đồng thời và test lỗi.
Kết quả thực tế: xem `PHASE3-REPORT.md` (đã chạy với fake provider; **chưa** gọi API thật do chưa có khóa).

### 3E. DeepSeek & quản lý model linh hoạt (bổ sung sau Phase 3, ADR-015)
- [x] Provider DeepSeek (`deepseek-flash`, `deepseek-v4-pro`, kiểm theo api-docs.deepseek.com ngày 05/11/2026), seed ở trạng thái tắt.
- [x] Provider tùy chỉnh theo chuẩn OpenAI-compatible (base URL qua SsrfGuard, chế độ JSON chọn được).
- [x] API key nhập từ admin, mã hóa trong DB, ghi-một-chiều; `.env` vẫn dùng được.
- [x] Sửa Model ID, xóa model/provider chưa dùng, mức suy luận (reasoning effort).
- [x] Giá cao điểm/thấp điểm theo khung giờ UTC.
- [x] Test Connection (danh sách model, miễn phí) và Test Model (lời gọi thật nhỏ, có tính phí).
- [x] Fallback sang provider khác khi một provider lỗi (đã có từ 3A, có thêm test DeepSeek → model kế tiếp).

**Nghiệm thu 3E**: gọi DeepSeek thật bằng khóa của chủ dự án; Test Connection báo đúng model; chi phí khớp bảng giá DeepSeek theo giờ. Kết quả thực tế: xem mục 3E trong `PHASE3-REPORT.md` (**chưa** gọi DeepSeek thật do chưa có khóa và mạng sandbox chặn).

## Phase 4 — Gói dịch vụ, quota, credit, thanh toán
- Plans/prices/limits, subscription, trial, usage ledger + counters (reserve/commit/release), credit wallet — tái sử dụng cơ chế reservation của AI Gateway.
- Adapter cổng thanh toán (chờ quyết định cổng), webhook an toàn, hóa đơn.
- Admin: quản lý plan, credit pack, cấp credit, xem hóa đơn.

**Nghiệm thu**: không thể vượt quota dưới tải đồng thời (test race); webhook lặp không kích hoạt hai lần; webhook sai chữ ký bị từ chối; hết hạn gói → chặn tác vụ tốn quota với 402.

## Phase 5 — Hồ sơ doanh nghiệp, Research, Keywords, Topic Cluster, Calendar
- Business profile & offerings; research ngành/đối thủ với nguồn thật (provenance); keyword discovery với số liệu từ DataForSEO (hoặc "Chưa có dữ liệu"); topic cluster; content calendar.

**Nghiệm thu**: không có search volume nào hiển thị mà không có `provider` + `fetched_at`; mọi nhận định research liên kết nguồn đã tải; nội dung độc hại trong trang đối thủ (prompt injection test fixtures) không thay đổi hành vi hệ thống.

## Phase 6 — Pipeline viết bài & kiểm chứng
- Brief → sourcing → drafting → verification; phiên bản nội dung; editor chỉnh sửa; diff/khôi phục phiên bản; kiểm tra trùng lặp với nguồn.

**Nghiệm thu**: citation chỉ trỏ nguồn đã tải; claim severity cao không có evidence → `failed`; mỗi chỉnh sửa tạo phiên bản mới; job idempotent khi retry.

## Phase 7 — Media & SEO
- Ảnh stock (license), ảnh AI (gắn nhãn), infographic, alt text; metadata, schema, internal link, SEO checklist.

**Nghiệm thu**: ảnh AI không thể gắn làm ảnh sản phẩm thật; mọi ảnh stock có license/attribution; nhãn "không bảo đảm thứ hạng" hiển thị.

## Phase 8 — Duyệt & xuất bản
- Approval workflow, lịch đăng, auto-publish có điều kiện, đẩy bài + ảnh + SEO meta lên WP, đồng bộ trạng thái, retry.

**Nghiệm thu**: bài chưa qua kiểm chứng không thể lên lịch (cả UI và API); đăng lặp không tạo bài trùng (idempotency); lỗi WP hiển thị rõ và retry được.

## Phase 9 — Search Console & làm mới nội dung
- OAuth GSC theo site, đồng bộ số liệu, dashboard hiệu quả, phát hiện bài suy giảm, đề xuất cập nhật.

**Nghiệm thu**: số liệu khớp GSC (đối chiếu mẫu); đề xuất có bằng chứng số liệu; thu hồi kết nối Google xóa token.

## Phase 10 — Agency & sẵn sàng thương mại
- Workspace agency, quyền theo site, white-label báo cáo, client portal duyệt bài.
- Hardening: pentest nội bộ, load test, backup/restore drill, tài liệu vận hành, điều khoản & chính sách quyền riêng tư.

---

## Quyết định còn mở (không chặn Phase 1)

| # | Câu hỏi | Cần trước |
|---|---|---|
| Q1 | Cổng thanh toán: Stripe, PayOS, VNPay, MoMo? Có xuất hóa đơn VAT điện tử? | Phase 4 |
| Q2 | Domain chính thức (`app.`, `admin.`, `api.`)? | Deploy staging |
| Q3 | Nhà cung cấp dữ liệu từ khóa/SERP (DataForSEO đề xuất) và tìm kiếm web (Brave/Tavily) | Phase 5 |
| Q4 | Giá và giới hạn từng gói | Phase 4 |
| Q5 | Chính sách lưu giữ dữ liệu, xóa tài khoản | Phase 10 |
