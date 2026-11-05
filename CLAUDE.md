# CLAUDE.md — Hướng dẫn cho Claude (và lập trình viên) khi làm việc trên SiteX Post

Đọc file này trước mọi thay đổi. Chi tiết nằm trong `docs/`.

## Sản phẩm
SiteX Post — SaaS AI SEO tự động cho WordPress. SaaS: **SiteX Post Cloud**. Plugin: **SiteX Post** (slug & text domain `sitex-post`). Namespace backend `SiteXPost\`, plugin `SiteXPost\WP\`.

## Tài liệu nguồn sự thật
- `docs/ARCHITECTURE.md` — module, ranh giới, tenancy, pipeline, ADR
- `docs/DATABASE.md` — schema, quy ước
- `docs/SECURITY.md` — ma trận quyền, connector, SSRF, prompt injection
- `docs/API-CONTRACTS.md` — endpoint, envelope, mã lỗi
- `docs/ROADMAP.md` — phase, Definition of Done, checklist nghiệm thu
- `docs/DEPLOYMENT.md` — cài đặt, nâng cấp, vận hành

Nếu code cần lệch thiết kế: cập nhật tài liệu trong cùng thay đổi và ghi lý do.

## Quy tắc làm việc (bắt buộc)
1. **Chỉ thực hiện phase được yêu cầu.** Không tự chuyển phase, không làm trước tính năng của phase sau.
2. Không phá code đang hoạt động; chạy toàn bộ test trước và sau khi sửa.
3. **Không tự deploy production**, không chạy lệnh trên server production.
4. Mỗi phase giao đủ: migration + API + UI + tests.
5. Chạy `scripts/check.sh` (hoặc `check.ps1`) trước khi báo hoàn thành. **Không bao giờ nói test pass nếu chưa chạy**; dán kết quả thật.
6. Báo cáo cuối phase: đã xong / chưa xong / rủi ro / lệnh đã chạy.
7. Trả lời người dùng bằng **tiếng Việt**.

## Lệnh thường dùng
```bash
# Backend (thư mục backend/)
composer test        # Pest
composer analyse     # Larastan
composer lint        # Pint --test   (composer fix để tự sửa)
php artisan migrate:fresh --seed --seeder=DemoSeeder   # chỉ local

# Frontend (thư mục frontend/)
npm run dev | build | typecheck | lint | test
npm run demo | demo:admin   # UI demo dữ liệu mẫu, không cần backend (docs/DEMO-UI.md)

# Toàn bộ
scripts/check.sh
```

## Quy tắc code backend
- Code mới đặt trong `app/Modules/<Module>/` theo cấu trúc ở ARCHITECTURE.md §4.1. Không thêm logic nghiệp vụ vào `app/Kernel`.
- Logic nghiệp vụ nằm trong **Action** (`Actions/`), controller mỏng: validate (FormRequest) → authorize (Policy) → gọi Action → trả Resource.
- Module khác chỉ dùng `Contracts/`, Action công khai, event. Không ghi vào bảng của module khác.
- PK là ULID (`HasUlids`). Tiền là số nguyên `*_minor`. Thời gian UTC.
- Model tenant **phải** dùng trait `BelongsToWorkspace`. Không bao giờ nhận `workspace_id` từ request. Không dùng `withoutWorkspaceScope()` ngoài module Platform và luôn kèm audit.
- Route tenant nằm dưới `/api/v1/workspaces/{workspace}`; mọi action gọi `authorize`.
- Tác vụ > ~2 giây (AI, HTTP ra ngoài, ảnh) **phải** chạy trong queue, trả 202 + `job_run`.
- Request ra ngoài tới URL do người dùng/website cung cấp **phải** qua `SsrfGuard`.
- Không `DB::raw`/`whereRaw` với input người dùng. Không log secret.
- Hành động nhạy cảm gọi `Audit::record(...)`.
- Mỗi endpoint tenant mới phải có test cross-tenant (trả 404) và test phân quyền theo vai trò.

## Quy tắc code frontend
- Hai SPA từ một project Vite: `SITEX_APP=customer|admin`; build ra `dist/<app>/index.html`.
- Vue 3 `<script setup lang="ts">`, TypeScript strict, Tailwind. TypeScript ghim **6.0.x** (TS 7 làm hỏng vue-tsc — đã kiểm chứng).
- Mọi chuỗi hiển thị qua `vue-i18n` (`t('module.key')`), file `src/locales/vi/*.json`. Ngoại lệ duy nhất: nguyên mẫu `src/demo/` (xem docs/DEMO-UI.md); không import gì từ `src/demo/` vào app thật.
- Mỗi màn hình dữ liệu có đủ loading / empty / error state (dùng component dùng chung).
- Không `v-html` trừ component `SafeHtml`. Không lưu token trong localStorage.
- Ẩn/hiện UI theo `permissions[]` từ API, nhưng **backend luôn là nơi kiểm tra quyền**.

## Plugin WordPress
- Tương thích WP ≥ 6.4, PHP ≥ 7.4 (không dùng cú pháp PHP 8). Prefix `sitex_post_`, text domain `sitex-post`.
- Mọi endpoint REST xác minh chữ ký HMAC-SHA256 `SITEX-HMAC-V1` (khóa riêng từng site) + timestamp + nonce chống replay; response cũng được ký. Xem SECURITY §6. Sanitize đầu vào, escape đầu ra. Không chứa API key AI.

## Quy tắc chất lượng nội dung (không được vi phạm)
- Không bịa search volume, thứ hạng, nguồn trích dẫn. Không có dữ liệu → hiển thị "Chưa có dữ liệu".
- Không tự xuất bản nội dung chưa qua kiểm chứng.
- SEO score là checklist, không phải bảo đảm thứ hạng.
- Không sao chép bài đối thủ; ảnh AI luôn gắn nhãn và không dùng làm ảnh sản phẩm thật.
- Nội dung đã tạo luôn có lịch sử phiên bản.
- Dữ liệu demo chỉ ở development (`DemoSeeder` từ chối chạy trong production; UI demo `npm run demo` bị loại khỏi build, kiểm bằng `npm run check:no-demo`).

## Môi trường
- Dev có thể trên Windows (Horizon không chạy được → `php artisan queue:work`). Không hardcode đường dẫn; dùng `.env`, `base_path()`, `storage_path()`.
- Production: Ubuntu + aaPanel, Nginx, PHP-FPM 8.4, MySQL 8.4, Redis, Supervisor.

## Trạng thái hiện tại
- Phase 0 (thiết kế) và Phase 1 (SaaS Core) hoàn thành — xem `docs/PHASE1-REPORT.md`.
- Phase 2 (WordPress Connector) đã triển khai trên nhánh `feature/phase2-wp-connector`, **chờ người dùng nghiệm thu** — xem `docs/PHASE2-REPORT.md`.
- Thứ tự phase đã đổi (ADR-012): Phase 3 = AI Gateway & Queue, gói/thanh toán là Phase 4. Phase 3 đã triển khai trên nhánh `feature/phase3-ai-gateway` (tách từ `feature/phase2-wp-connector`), **chờ người dùng nghiệm thu** — xem `docs/PHASE3-REPORT.md`. 
- Phase 4 = **SEO Planner (Topic Discovery)** theo ADR-016 (billing dời lại). Đã triển khai trên nhánh `feature/phase4-topic-discovery` (tách từ `feature/phase3-ai-gateway`), **chờ người dùng nghiệm thu** — xem `docs/PHASE4-REPORT.md`.
- Phase 5 = **AI Writer, Evidence Research & Fact-check**. Đã triển khai trên nhánh `feature/phase5-ai-writer` (tách từ `feature/phase4-topic-discovery`), **chờ người dùng nghiệm thu** — xem `docs/PHASE5-IMPLEMENTATION.md`, `docs/PHASE5-TEST-REPORT.md`. **Không tự bắt đầu Phase 6.** Không đăng WordPress hàng loạt.
- Bản đồ code Phase 5: `backend/app/Modules/Writer` (Pipeline, Retrieval, Workflows, Ai/WriterAi, Support/{ArticleStateMachine,HtmlSanitizer}), `database/seeders/WriterDemoSeeder.php`, `frontend/src/apps/customer/pages/writer/`, `frontend/src/apps/admin/pages/WriterAdminPage.vue`, `frontend/src/shared/api/writer*.ts`, `frontend/src/shared/utils/articleHtml.ts`. Quy tắc: verdict do `VerdictRules` quyết định (model chỉ đề xuất), không tìm thấy ≠ sai, chỉ render HTML bài qua `SafeHtml`.
- Bản đồ code Phase 4: `backend/app/Modules/Research` (Crawler, Sources, SearchConsole, Analysis, Ai, Workflows/ResearchDiscoveryWorkflow, Support/QuotaManager, Console), `database/seeders/ResearchDemoSeeder.php`, `frontend/src/apps/customer/pages/research/`, `frontend/src/apps/admin/pages/ResearchAdminPage.vue`, `frontend/src/shared/api/research*.ts`. Không bao giờ tạo search volume/KD giả — thiếu nguồn thì `null` → "Chưa có dữ liệu".
- Bản đồ code Phase 3: `backend/app/Modules/AiGateway` (Providers = adapter HTTP, Gateway = AiGateway/BudgetManager/CircuitBreaker/CostCalculator, Registry, Workflows/AiTestWorkflow), `backend/app/Modules/Workflows` (Engine, Jobs/ProcessWorkflow, Console/RecoverWorkflows), `config/horizon.php`, `deploy/supervisor/`, `scripts/e2e/phase3-*`, `frontend/src/apps/customer/pages/AiTasksPage.vue`, `frontend/src/apps/admin/pages/{AiUsage,AiSettings,Jobs}Page.vue`.
- Quy tắc AI: không gọi provider trong HTTP request (luôn qua workflow/queue); không retry lời gọi tốn phí khi không chắc đã bị tính tiền trừ khi route bật `allow_paid_retry`; khóa API ở `.env` hoặc DB mã hóa (ADR-015), không bao giờ trả ra API/log; base URL tùy chỉnh luôn qua `SsrfGuard`; không tự đoán Model ID, phải kiểm theo tài liệu chính thức; test dùng `FakeProvider`/`Http::fake`, không gọi API thật.
- Bản đồ code Phase 2: `backend/app/Modules/Sites` (Connector/Signer, WordPressClient, Actions, Jobs), `wordpress-plugin/sitex-post` (plugin), `wordpress-plugin/tests/e2e` (E2E với WordPress thật), `frontend/src/apps/customer/pages/Site*.vue`.
- Bản đồ code Phase 1: `backend/app/Kernel` (tenancy, HTTP, bảo mật), `backend/app/Modules/{Identity,Workspace,Audit,Platform}`; `frontend/src/shared` (API client, UI, auth, layout), `frontend/src/apps/{customer,admin}`.
- Test cross-tenant tự động liệt kê mọi route tenant (`tests/Feature/Tenancy/CrossTenantIsolationTest.php`): route tenant mới tự được kiểm tra, nhưng vẫn cần test phân quyền riêng.
