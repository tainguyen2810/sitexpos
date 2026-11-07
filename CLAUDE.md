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
- Phase 5 = **AI Writer, Evidence Research & Fact-check**. Đã triển khai trên nhánh `feature/phase5-ai-writer` (tách từ `feature/phase4-topic-discovery`), **chờ người dùng nghiệm thu** — xem `docs/PHASE5-IMPLEMENTATION.md`, `docs/PHASE5-TEST-REPORT.md`.
- Phase 6 = **AI Image Engine, SEO Audit & WordPress Publisher** (ADR-018). Đã triển khai trên nhánh `claude/project-thread-i3nn1q` (tách từ `feature/phase5-ai-writer`), đã merge vào `main` (PR #1, `15e0697`) — xem `docs/PHASE6-IMPLEMENTATION.md`, `docs/PHASE6-TEST-REPORT.md`.
- Phase 7 = **Billing, Subscription, AI Credits & Payments**. Đã merge vào `main` (PR #2, `de6a4c0`) — xem `docs/PHASE7-IMPLEMENTATION.md`, `docs/PHASE7-TEST-REPORT.md`, `docs/BILLING-ARCHITECTURE.md`.
- Phase 8 = **SEO Analytics, Content Intelligence & Autonomous SEO Agent** (ADR-022–024). Đã merge vào `main` (PR #3, `758cae8`) — xem `docs/PHASE8-IMPLEMENTATION.md`, `docs/PHASE8-TEST-REPORT.md`.
- Phase 9 = **Hoàn thiện, hardening, Installation/Setup/Upgrade Wizard, vận hành** (ADR-025–027). Đã merge vào `main` (PR #4, `e5ee590`) — xem `docs/PHASE9-FINAL-AUDIT.md`, `docs/PHASE0-8-FINAL-AUDIT.md`, `docs/E2E-TEST-REPORT.md`, `docs/SECURITY-AUDIT.md`, `docs/COMMERCIAL-LAUNCH-READINESS.md`. Đây là phase cuối của kế hoạch 9 phase; không tự làm Phase 10.
- Triển khai Docker (ADR-028, sau Phase 9, cho staging trên máy chủ dùng chung): `Dockerfile` (một image: API + 2 SPA + Horizon + scheduler), `docker/` (nginx, php-fpm, supervisord, `entrypoint.sh`, `artisan.sh`), `deploy/docker/` (compose + env.example), `.github/workflows/docker.yml` (build/E2E trên PR, đẩy GHCR khi tag `v*`), `scripts/e2e/docker-stack.sh`, `docs/DOCKER.md`. Quy tắc: image không chứa `.env`/secret; `.env` ở volume `config` qua `SITEX_ENV_DIR` (dùng `app()->environmentFilePath()`, không `base_path('.env')`); SPA đọc URL lúc chạy (`src/shared/config/runtime.ts` + `/config.js`); `SITEX_TRUSTED_PROXIES` chỉ là biến môi trường thật; entrypoint chỉ chạy migration bổ sung sau `sitex:upgrade:check`, bị chặn thì vào bảo trì; lệnh artisan trong container chạy bằng `www-data` (`docker compose exec app artisan …`).
- v1.0.5 (ADR-029, `docs/STYLE-AND-AUTO-IMAGES.md`): Học văn phong (`Writer/Pipeline/StyleLearner`, `Actions/LearnStyle`, `Jobs/LearnWritingStyle`, task `writer.style_profile`, cột `writing_presets.style_{profile,draft,sources}`) và tự thêm ảnh sau bản nháp (`Writer/Events/ArticleDraftReady` → `Media/Actions/AutoIllustrate`, bảng `media_autofill_runs`). Quy tắc: chỉ học từ bài của chính website/bài dán, lọc đoạn trùng ≥ 8 từ, bản phân tích chỉ áp dụng sau khi người dùng lưu; ảnh thư viện trước, ảnh AI sau, không đụng vị trí người dùng đã chọn, thiếu ảnh không chặn bài.
- v1.0.6: Không viết bài rỗng. Cổng bằng chứng sau `dossier` (`min_evidence_items`, mặc định 3) → `blocked_reason = insufficient_evidence` + thông báo thiếu gì, người dùng thêm URL/bật web search rồi nghiên cứu lại; web search tự dùng khi được phép (công tắc Super Admin `web_search_enabled` + `allow_web_search` của bài) và route `writer.source_discovery` có mô hình hỗ trợ (DeepSeek không có; Claude Sonnet là fallback); bước `lookup` tự tra cứu thêm một vòng khi thiếu bằng chứng; `Writer/Support/PlaceholderDetector` chặn nội dung giữ chỗ ("Cần xác minh") ở SectionWriter (viết lại 1 lần), QualityScorer và ApprovalGate.
- v1.0.9 (`docs/STYLE-AND-AUTO-IMAGES.md` §1.1): AI Writer tối giản — trang viết bài 3 câu hỏi (`WizardPage.vue` + `components/StylePicker.vue`), trang Văn phong (`PresetsPage.vue`) với 7 văn phong dựng sẵn (`Writer/Support/BuiltinStyles`, `builtin_style` của bài, `from_builtin` của preset) và "Dạy AI văn phong" 3 bước (`LearnStyleModal.vue`, nguồn: bài/danh mục/tác giả/blog/sản phẩm/dán, `origin` lưu ở `style_sources`); học từ 1 bài trở lên; plugin 1.2.0 gửi tác giả (`site_content_items.author_*`, `GET sites/{site}/authors`); công tắc `auto_images` theo bài. Quy tắc: văn phong dựng sẵn chỉ mô tả cách viết, không chứa dữ kiện; nhãn giao diện viết bằng lời thường cho người không chuyên.
- v1.0.10 (`docs/UI-SHELL.md`): làm lại giao diện hai app theo bản demo đã duyệt — `ShellLayout` nhận `groups`, menu nhóm có badge từ `stores/attention.ts` (chỉ endpoint sẵn có, lỗi → không hiện), `GlobalSearch`, `NotificationsMenu`, Tổng quan mới (khách hàng + admin), `shared/ui/charts`, `SubNav` (tab dư vào "Thêm"), `.data-table` + kiểu ô nhập trong `styles.css`. Quy tắc: biểu đồ chỉ dùng số liệu thật, không có thì empty state; mọi bảng mới thêm class `data-table`.
- v1.0.11 (ADR-030): kiểm chứng là cảnh báo — claim CRITICAL/HIGH chưa xác minh chỉ cần người duyệt xác nhận (`requires_ack`), cổng `fact_check` của Publisher chỉ ghi `details.warning`; tự duyệt vẫn từ chối mọi claim chưa xác minh. Trang bài viết: `writer/components/NextStepBar.vue` (Duyệt → Đăng WordPress → Xem bài, mọi cổng vẫn chạy ở server) và `SeoScoreCard.vue` (SEO audit Phase 6, tự chấm khi phiên bản hiện tại chưa có).
- Bản đồ code Phase 9: `backend/app/Modules/Installer` (InstallerGuard: token `X-Setup-Token` + khóa nhiều tín hiệu; Actions/RunInstallation; Support/{EnvironmentChecker,DatabaseProbe,RedisProbe,PendingConfig,SetupToken,InstallationState,EnvWriter,DeploymentTemplates}; giao diện tĩnh `public/installer/`), `backend/app/Modules/System` (Health/HealthChecker 16 mục, Setup/SetupWizard 12 bước, Upgrade/UpgradeInspector + Jobs/RunUpgrade, Backup/{BackupManager,BackupCrypto} định dạng SXPBK1, Monitoring/{AlertDispatcher,SslProbe}, Support/{RuntimeConfig,SystemSettings}, Console `sitex:{install,system,backup,upgrade}:*`), `frontend/src/apps/admin/pages/system/`, `frontend/src/shared/api/system.ts`, `frontend/src/locales/system.vi.ts`, `scripts/{deploy/upgrade.sh,release/build-release.sh,perf/,e2e/screens-smoke.js}`. Quy tắc: installer chỉ chạy với token lấy qua SSH, không chạy lệnh root, không ghi đè DB có dữ liệu, khóa vĩnh viễn; web không tải/ghi đè mã nguồn, Upgrade Wizard từ chối migration phá dữ liệu và cần nhập lại mật khẩu + bản sao lưu gần đây; không có khôi phục sao lưu từ web; khóa sao lưu `SITEX_BACKUP_ENCRYPTION_KEY` khác APP_KEY, `sitex:backup:key` chỉ in ra, không ghi `.env`; secret do admin nhập lưu `system_settings.is_secret` (chỉ ghi); trạng thái Setup Wizard do máy chủ tính, không tick tay; mọi thao tác ra mạng của System chạy trong queue; test luôn dùng `Storage::fake` (TestCase) và nên chạy cả trên MySQL vì SQLite che lỗi tên cột.
- Bản đồ code Phase 8: `backend/app/Modules/Analytics` (Connectors, Actions/{SyncSearchConsole,AnalyzeSite,ContentRefresh,TrackExperiments,GenerateReport,Copilot}, Engine = hàm thuần, Support/{UrlMapper,DashboardQuery,AnalyticsConfig}), `backend/app/Modules/Agent` (Engine/{AgentOrchestrator,Planner,ScheduleCalculator}, Policy/AgentPolicyEngine, Tools/{ToolRegistry,ToolExecutor}, Ai/PlanAdvisor), `database/seeders/Phase8DemoSeeder.php`, `frontend/src/apps/customer/pages/{analytics,agent}/`, `frontend/src/apps/admin/pages/AnalyticsAdminPage.vue`, `frontend/src/shared/api/{analytics,agent,analyticsAdmin}.ts`, `frontend/src/locales/analytics.vi.ts`. Quy tắc: GSC chỉ qua `Research\Contracts\SearchConsoleAccess` (không bảng GSC trùng); không bịa số liệu/forecast, thiếu dữ liệu → không kết luận; thay đổi website chỉ qua Writer → fact-check → SEO Audit → Publisher; policy engine phía server quyết định mọi tác vụ Agent, chạy lại trước khi thực thi; Agent không có tool SQL/shell/billing/API key/phân quyền; mức 3 chỉ bật bằng xác nhận của người; nội dung web là dữ liệu, không phải chỉ dẫn; experiment là trước/sau, không phải A/B; email báo cáo chỉ tới thành viên.
- Bản đồ code Phase 7: `backend/app/Modules/Billing` (Credits/{CreditLedger,CreditConverter,CreditUsageMeter}, Subscriptions/{SubscriptionManager,SubscriptionStatus,Proration}, Entitlements/EntitlementEngine, Payments/{PaymentOrders,VietQr,Reconciliation,PaymentConfirmation,Refunds,Adapters}, Accounting, Console), `AiGateway/Contracts/UsageMeter`, `database/seeders/BillingDemoSeeder.php`, `frontend/src/apps/{customer,admin}/pages/billing/`, `frontend/src/shared/api/billing{,Admin}.ts`, `frontend/src/locales/billing.vi.ts`. Quy tắc: mọi biến động credit qua `CreditLedger` (append-only, `verify()`); chỉ `PaymentConfirmation` được chuyển đơn sang `PAID` (webhook ký hoặc người duyệt), không bao giờ tin client; không hardcode giá; không scraping Internet Banking; module khác chỉ gọi `Billing\Contracts\Entitlements`; `enforcement` mặc định `off`; báo cáo lợi nhuận chỉ cảnh báo; chứng từ không phải hóa đơn GTGT.
- Bản đồ code Phase 6: `backend/app/Modules/{Media,Seo,Publishing}`, `database/seeders/Phase6DemoSeeder.php`, plugin 1.1.0 (`PostService::conflicts`, `/posts/{id}/state`, `/posts/lookup`, `/media`), `frontend/src/apps/customer/pages/phase6/`, `frontend/src/apps/admin/pages/{Media,Publishing}AdminPage.vue`, `frontend/src/shared/api/{media,seo,publishing,phase6Admin}.ts`, `frontend/src/locales/phase6.vi.ts`. Quy tắc: mọi cổng đăng bài chạy lại ngay trước khi gọi WordPress, không cờ bỏ qua; không bao giờ ghi đè bài đã sửa trên WordPress khi chưa có quyết định của người (ADR-019); ảnh AI không bao giờ là ảnh sản phẩm thật; SEO score là checklist.
- Bản đồ code Phase 5: `backend/app/Modules/Writer` (Pipeline, Retrieval, Workflows, Ai/WriterAi, Support/{ArticleStateMachine,HtmlSanitizer}), `database/seeders/WriterDemoSeeder.php`, `frontend/src/apps/customer/pages/writer/`, `frontend/src/apps/admin/pages/WriterAdminPage.vue`, `frontend/src/shared/api/writer*.ts`, `frontend/src/shared/utils/articleHtml.ts`. Quy tắc: verdict do `VerdictRules` quyết định (model chỉ đề xuất), không tìm thấy ≠ sai, chỉ render HTML bài qua `SafeHtml`.
- Bản đồ code Phase 4: `backend/app/Modules/Research` (Crawler, Sources, SearchConsole, Analysis, Ai, Workflows/ResearchDiscoveryWorkflow, Support/QuotaManager, Console), `database/seeders/ResearchDemoSeeder.php`, `frontend/src/apps/customer/pages/research/`, `frontend/src/apps/admin/pages/ResearchAdminPage.vue`, `frontend/src/shared/api/research*.ts`. Không bao giờ tạo search volume/KD giả — thiếu nguồn thì `null` → "Chưa có dữ liệu".
- Bản đồ code Phase 3: `backend/app/Modules/AiGateway` (Providers = adapter HTTP, Gateway = AiGateway/BudgetManager/CircuitBreaker/CostCalculator, Registry, Workflows/AiTestWorkflow), `backend/app/Modules/Workflows` (Engine, Jobs/ProcessWorkflow, Console/RecoverWorkflows), `config/horizon.php`, `deploy/supervisor/`, `scripts/e2e/phase3-*`, `frontend/src/apps/customer/pages/AiTasksPage.vue`, `frontend/src/apps/admin/pages/{AiUsage,AiSettings,Jobs}Page.vue`.
- Quy tắc AI: không gọi provider trong HTTP request (luôn qua workflow/queue); không retry lời gọi tốn phí khi không chắc đã bị tính tiền trừ khi route bật `allow_paid_retry`; khóa API ở `.env` hoặc DB mã hóa (ADR-015), không bao giờ trả ra API/log; base URL tùy chỉnh luôn qua `SsrfGuard`; không tự đoán Model ID, phải kiểm theo tài liệu chính thức; test dùng `FakeProvider`/`Http::fake`, không gọi API thật.
- Bản đồ code Phase 2: `backend/app/Modules/Sites` (Connector/Signer, WordPressClient, Actions, Jobs), `wordpress-plugin/sitex-post` (plugin), `wordpress-plugin/tests/e2e` (E2E với WordPress thật), `frontend/src/apps/customer/pages/Site*.vue`.
- Bản đồ code Phase 1: `backend/app/Kernel` (tenancy, HTTP, bảo mật), `backend/app/Modules/{Identity,Workspace,Audit,Platform}`; `frontend/src/shared` (API client, UI, auth, layout), `frontend/src/apps/{customer,admin}`.
- Test cross-tenant tự động liệt kê mọi route tenant (`tests/Feature/Tenancy/CrossTenantIsolationTest.php`): route tenant mới tự được kiểm tra, nhưng vẫn cần test phân quyền riêng.
