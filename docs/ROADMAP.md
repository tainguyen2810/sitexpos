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

## Phase 1 — Nền tảng: monorepo, xác thực, workspace, phân quyền, khung UI

**Mục tiêu**: Có một ứng dụng chạy được end-to-end: đăng ký → xác minh email → tạo workspace → mời thành viên → phân quyền; Super Admin đăng nhập (2FA) vào dashboard riêng. Tenant isolation được thực thi và kiểm thử. **Chưa** có AI, billing, WordPress.

### 1A. Monorepo & tooling
- [ ] Cấu trúc `backend/`, `frontend/`, `wordpress-plugin/` (chỉ placeholder README), `docs/`, `scripts/`.
- [ ] Laravel 13 với namespace gốc `SiteXPost\` (composer psr-4, `bootstrap/app.php`).
- [ ] Hạ tầng module: `ModuleServiceProvider` tự nạp routes/migrations/policies của từng module.
- [ ] Pest 4 + Larastan (level 6) + Pint; script `composer test|analyse|lint|check`.
- [ ] Vite 8 + Vue 3.5 + TS 6.0 + Tailwind 4, 2 entry (`customer`, `admin`); ESLint, vue-tsc, Vitest; `npm run check`.
- [ ] `scripts/check.sh` và `scripts/check.ps1` chạy toàn bộ kiểm tra (Linux/Windows).
- [ ] `.env.example` đầy đủ, không có giá trị thật; `.editorconfig`, `.gitignore`.
- [ ] CI GitHub Actions (MySQL 8.4 + Redis service) — kích hoạt khi có repo.

### 1B. Kernel
- [ ] `TenantContext`, middleware `ResolveWorkspace`, trait `BelongsToWorkspace` (global scope **fail-closed**), job middleware `RestoreTenantContext`.
- [ ] Error envelope + exception handler theo API-CONTRACTS §1.2; `X-Request-Id`.
- [ ] Base `FormRequest` cấm `workspace_id`/`platform_role` trong input.
- [ ] Rate limiter theo user, workspace, auth (SECURITY §11).
- [ ] Logger processor che secret.
- [ ] `SsrfGuard` (unit test với danh sách IP chặn) — dùng từ Phase 2 nhưng làm sẵn vì là Kernel.

### 1C. Identity
- [ ] Đăng ký, đăng nhập, đăng xuất, xác minh email, quên/đặt lại mật khẩu (Fortify headless + Sanctum SPA).
- [ ] 2FA TOTP (bật, xác nhận, mã khôi phục, challenge).
- [ ] Hồ sơ cá nhân, đổi mật khẩu, ngôn ngữ, múi giờ; quản lý phiên.
- [ ] Email qua mailer cấu hình `.env` (SMTP/Brevo SMTP); template email tiếng Việt.

### 1D. Workspace & phân quyền tenant
- [ ] CRUD workspace; người tạo là OWNER; đúng một OWNER.
- [ ] Thành viên: liệt kê, đổi vai trò, xóa, rời workspace; chuyển quyền OWNER (yêu cầu mật khẩu).
- [ ] Lời mời qua email (token hash, hết hạn 7 ngày, thu hồi, chấp nhận khi email khớp).
- [ ] Enum `WorkspaceRole`, `Permission`, ma trận quyền SECURITY §3.2; Policy cho mọi action.
- [ ] `GET /workspaces/{ws}` trả `my_role` + `permissions[]` để UI ẩn/hiện.
- [ ] Audit log workspace + màn hình xem audit.

### 1E. Platform (Super Admin)
- [ ] `platform_role` trên user; lệnh `php artisan sitex:make-super-admin {email}` (không có tài khoản admin mặc định).
- [ ] Bắt buộc 2FA với platform staff.
- [ ] Admin API Phase 1 (API-CONTRACTS §3 ✦): dashboard, workspaces, users, suspend/unsuspend, staff, audit logs.
- [ ] Impersonation read-only có lý do, banner rõ ràng trên UI, audit đầy đủ, tự kết thúc sau 30 phút.

### 1F. Frontend
- [ ] Shell khách hàng: sidebar + topbar, chuyển workspace, menu theo quyền, responsive (drawer mobile), light mode.
- [ ] Màn hình: đăng nhập, đăng ký, quên/đặt lại mật khẩu, xác minh email, 2FA challenge, tạo workspace đầu tiên (onboarding), dashboard (thẻ trạng thái onboarding: "Kết nối website — sắp có"), thành viên & lời mời, cài đặt workspace, hồ sơ cá nhân & bảo mật, audit log.
- [ ] Shell admin riêng (`admin.html`): dashboard số liệu thật từ DB, danh sách workspace/user, staff, audit log, impersonation.
- [ ] Bộ UI dùng chung + EmptyState/LoadingState/ErrorState; toast; xác nhận hành động nguy hiểm.
- [ ] vue-i18n với `vi` đầy đủ, `en` khung (fallback `vi`).
- [ ] Route guard theo đăng nhập/xác minh email/quyền.

### 1G. Dữ liệu demo (chỉ development)
- [ ] `DemoSeeder`: 2 workspace demo (`is_demo=1`), user demo mỗi vai trò, 1 super admin demo — **từ chối chạy** khi `APP_ENV=production` (có test).
- [ ] `DatabaseSeeder` production chỉ seed dữ liệu hệ thống bắt buộc (hiện tại: không có).

### Checklist nghiệm thu Phase 1
- [ ] Từ DB rỗng: `php artisan migrate` thành công trên MySQL 8.4.
- [ ] Đăng ký → nhận email xác minh (Mailpit/log mailer ở dev) → xác minh → tạo workspace → thấy dashboard.
- [ ] Chưa xác minh email thì không tạo được workspace (403 `email_not_verified`).
- [ ] OWNER mời email B vai trò EDITOR → B đăng ký/đăng nhập → chấp nhận → thấy workspace với vai trò EDITOR; EDITOR không thấy menu Thành viên/Cài đặt và API trả 403 khi gọi trực tiếp.
- [ ] VIEWER gọi API sửa workspace → 403. ADMIN không đổi được vai trò OWNER.
- [ ] User workspace A gọi mọi endpoint với ID workspace B / thành viên B / lời mời B → 404 (test tự động phủ toàn bộ route tenant).
- [ ] Gửi `workspace_id` trong body → 422.
- [ ] Truy vấn model tenant ngoài TenantContext → exception (unit test).
- [ ] Đăng nhập sai 6 lần/phút → 429.
- [ ] Super admin tạo bằng lệnh artisan; vào admin SPA bị buộc thiết lập 2FA; sau đó thấy dashboard với số liệu thật.
- [ ] Impersonation read-only: staff xem được workspace, mọi request ghi bị chặn (403), audit có `impersonator_id`.
- [ ] Mọi hành động trong SECURITY §12 thuộc phạm vi Phase 1 có audit log.
- [ ] `DemoSeeder` chạy được ở local; với `APP_ENV=production` thoát với lỗi.
- [ ] UI: loading/empty/error state hiện đúng; responsive 375/768/1280; tiếng Việt toàn bộ.
- [ ] `scripts/check.sh` pass toàn bộ: Pint, Larastan, Pest, ESLint, vue-tsc, Vitest, build.
- [ ] Hướng dẫn cài đặt dev (Windows + Linux) trong README được kiểm tra bằng cách cài từ đầu.

**Ngoài phạm vi Phase 1**: sites/WordPress, billing/quota, AI, research, nội dung, GSC, deploy production.

---

## Phase 2 — Kết nối WordPress
- Plugin `sitex-post` (pairing, Ed25519, chống replay, trang admin WP, ngắt kết nối, uninstall sạch).
- Module Sites: thêm site, mã ghép nối, health check định kỳ, đồng bộ taxonomy & index bài, xoay khóa, thu hồi.
- SsrfGuard áp dụng cho mọi request tới site.

**Nghiệm thu**: ghép nối thành công với WP 6.4 và bản mới nhất (PHP 7.4 và 8.3); request có chữ ký sai/timestamp lệch/nonce lặp đều bị từ chối (test cả hai phía); thu hồi từ Cloud hoặc WP làm request sau thất bại ngay; site trỏ tới IP private bị từ chối; plugin qua `WP_DEBUG` không notice, Plugin Check không lỗi bảo mật.

## Phase 3 — Gói dịch vụ, quota, credit, thanh toán
- Plans/prices/limits, subscription, trial, usage ledger + counters (reserve/commit/release), credit wallet.
- Adapter cổng thanh toán (chờ quyết định cổng), webhook an toàn, hóa đơn.
- Admin: quản lý plan, credit pack, cấp credit, xem hóa đơn.

**Nghiệm thu**: không thể vượt quota dưới tải đồng thời (test race); webhook lặp không kích hoạt hai lần; webhook sai chữ ký bị từ chối; hết hạn gói → chặn tác vụ tốn quota với 402.

## Phase 4 — AI Gateway
- Provider OpenAI/Gemini/Anthropic, task routing, structured output + JSON Schema, retry/fallback/circuit breaker, đo token & chi phí, ngân sách, prompt template có version.
- Admin: cấu hình model/route/template/ngân sách, dashboard chi phí AI.

**Nghiệm thu**: mọi lời gọi có bản ghi `ai_requests` với chi phí; vượt ngân sách → tạm dừng theo cấu hình; provider lỗi → fallback; test dùng fake provider (không gọi API thật trong CI).

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
| Q1 | Cổng thanh toán: Stripe, PayOS, VNPay, MoMo? Có xuất hóa đơn VAT điện tử? | Phase 3 |
| Q2 | Domain chính thức (`app.`, `admin.`, `api.`)? | Deploy staging |
| Q3 | Nhà cung cấp dữ liệu từ khóa/SERP (DataForSEO đề xuất) và tìm kiếm web (Brave/Tavily) | Phase 5 |
| Q4 | Giá và giới hạn từng gói | Phase 3 |
| Q5 | Chính sách lưu giữ dữ liệu, xóa tài khoản | Phase 10 |
