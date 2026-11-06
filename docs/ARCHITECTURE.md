# SiteX Post — Kiến trúc hệ thống

> Trạng thái: **Phase 0 — Thiết kế**. Tài liệu này là nguồn sự thật về ranh giới module và các quyết định kiến trúc. Mọi thay đổi lớn phải cập nhật tài liệu này và ghi vào mục ADR ở cuối.

Liên quan: [DATABASE.md](DATABASE.md) · [SECURITY.md](SECURITY.md) · [API-CONTRACTS.md](API-CONTRACTS.md) · [ROADMAP.md](ROADMAP.md) · [DEPLOYMENT.md](DEPLOYMENT.md)

---

## 1. Tổng quan sản phẩm

| Thành phần | Tên | Mô tả |
|---|---|---|
| Thương hiệu | **SiteX Post** | |
| SaaS | **SiteX Post Cloud** | Backend Laravel + 2 SPA Vue (khách hàng, Super Admin) |
| Plugin WordPress | **SiteX Post** (`sitex-post`) | Connector cài trên website khách hàng, text domain `sitex-post` |
| Namespace PHP backend | `SiteXPost\` | Thay cho `App\` mặc định của Laravel |
| Namespace PHP plugin | `SiteXPost\WP\` | Prefix hàm/hằng số: `sitex_post_` / `SITEX_POST_` |

SiteX Post Cloud cung cấp toàn bộ AI. Khách hàng **không** nhập API key. Plugin WordPress **không** chứa API key AI và **không** gọi AI; nó chỉ là connector nhận lệnh có chữ ký từ Cloud.

## 2. Phiên bản công nghệ đã xác minh (05/11/2026)

Xác minh trực tiếp từ Packagist/npm ngày 05/11/2026 và bằng cài thử trong môi trường sạch (xem mục 2.1).

| Thành phần | Chọn | Ghi chú |
|---|---|---|
| PHP | **8.4** (production), tối thiểu **8.3** | Laravel 13 yêu cầu `^8.3`. Môi trường khảo sát có PHP 8.3.6 |
| Laravel | **13.x** (13.40 tại thời điểm viết) | Phát hành 17/03/2026, hỗ trợ bảo mật đến ~Q1/2028 |
| Laravel Sanctum | 4.3.x | Xác thực SPA bằng cookie phiên + CSRF |
| Laravel Fortify | 1.41.x | Backend headless cho đăng ký/đăng nhập/2FA/reset mật khẩu |
| Laravel Horizon | 5.50.x | Hỗ trợ Laravel 13. **Cần `pcntl`/`posix` → chỉ chạy trên Linux/WSL** |
| Larastan (PHPStan) | 3.14.x | Static analysis, mức khởi điểm 6, tăng dần |
| Pest | **4.7.x** | Pest 5 yêu cầu PHP 8.4; chọn 4.x để giữ tương thích PHP 8.3 |
| Laravel Pint | theo Laravel 13 | Code style |
| MySQL | **8.4 LTS** | MySQL 8.0 đã hết hỗ trợ (04/2026). Code chỉ dùng tính năng có ở 8.0+ |
| Redis | 7.x | Queue, cache, rate limit, nonce chống replay |
| Node.js | **24 LTS** (tối thiểu 22.12) | Chỉ dùng để build frontend, không chạy trên production |
| Vue | 3.5.x | Composition API + `<script setup lang="ts">` |
| TypeScript | **6.0.x** | TS 7 (bản native) chưa tương thích công cụ Volar/`vue-tsc`; ghim 6.0 |
| vue-tsc | 3.3.x | Type-check SFC |
| Vite | 8.x | |
| Tailwind CSS | 4.x (`@tailwindcss/vite`) | |
| Vue Router | 5.x | |
| Pinia | 4.x | |
| vue-i18n | 11.x | `vi` mặc định, sẵn sàng `en` |
| Vitest | mới nhất tương thích Vite 8 | Unit test frontend |
| WordPress (plugin) | Tối thiểu WP 6.4, PHP 7.4+ | Plugin phải chạy trên hosting cũ của khách → không dùng cú pháp PHP 8 |

Không dùng `stancl/tenancy` hay `spatie/laravel-permission`: mô hình tenant và vai trò của SiteX Post đơn giản và cố định, tự cài đặt sẽ minh bạch và dễ kiểm thử isolation hơn (xem ADR-003, ADR-004).

### 2.1 Kết quả cài thử
Xem `docs/VERIFICATION.md` (log cài thử composer/npm trong môi trường sạch).

## 3. Kiểu kiến trúc

- **Modular monolith**: một ứng dụng Laravel, chia module theo domain, ranh giới rõ ràng, có thể tách service sau.
- **Multi-tenant shared database**: mọi bảng dữ liệu khách hàng có cột `workspace_id`; isolation được thực thi tự động (global scope) và kiểm thử bắt buộc.
- **API-first**: SPA khách hàng, SPA admin và plugin WordPress đều chỉ giao tiếp qua API có version.
- **Async-first cho AI**: mọi tác vụ AI, crawl, tạo ảnh, đăng bài chạy trong queue. HTTP request chỉ tạo job và trả `202 Accepted` + `job_run_id`.
- **Worker tách được**: web và worker chia sẻ MySQL, Redis và storage S3-compatible; worker có thể chạy trên server riêng chỉ bằng cấu hình `.env`.

```
                    ┌───────────────────────────── SiteX Post Cloud ─────────────────────────────┐
 Trình duyệt        │  Nginx                                                                       │
 ┌───────────┐ HTTPS│  ├─ app.<domain>    → SPA khách hàng (static)                                │
 │ Khách hàng├──────┼─►├─ admin.<domain>  → SPA Super Admin (static)                               │
 └───────────┘      │  └─ api.<domain>    → PHP-FPM → Laravel (API, webhooks, connector API)       │
 ┌───────────┐      │                          │                                                    │
 │ Super Admin├─────┼─────────────────────────►│        ┌─────────┐   ┌────────┐   ┌──────────────┐ │
 └───────────┘      │                          ├───────►│ MySQL 8 │   │ Redis  │◄─►│ Horizon      │ │
                    │                          └───────►└─────────┘   └────────┘   │ workers      │ │
                    │                                                              │ (tách server │ │
                    │   Storage: local (1 server) / S3-compatible (nhiều server)   │  được)       │ │
                    └──────────────────────────────────────────────────────────────┴──────┬───────┘ │
                                                                                          │
         Lệnh có chữ ký HMAC (SaaS → WP)             ┌──────────────────────┐  AI Gateway │
   ┌─────────────────────────────────────────────────┤ Website WordPress    │  ┌──────────▼───────┐
   │  /wp-json/sitex-post/v1/*                       │ + plugin sitex-post  │  │ OpenAI / Gemini /│
   └────────────────────────────────────────────────►└──────────────────────┘  │ Claude, SERP,    │
                                                                               │ GSC, ảnh stock   │
                                                                               └──────────────────┘
```

## 4. Cấu trúc thư mục (monorepo)

```
sitex-post/
├── backend/                         # Laravel 13 — SiteX Post Cloud API + workers
│   ├── app/                         # namespace SiteXPost\
│   │   ├── Kernel/                  # Shared kernel: tenancy, audit, money, ids, http helpers
│   │   │   ├── Tenancy/             # TenantContext, BelongsToWorkspace, middleware
│   │   │   ├── Security/            # SsrfGuard, Signature, Encryption casts
│   │   │   ├── Http/                # ApiResponse, error envelope, base FormRequest
│   │   │   └── Support/
│   │   ├── Modules/
│   │   │   ├── Identity/            # User, đăng nhập, 2FA, phiên
│   │   │   ├── Workspace/           # Workspace, membership, lời mời, vai trò tenant
│   │   │   ├── Platform/            # Vai trò platform, dashboard admin, impersonation
│   │   │   ├── Audit/               # Audit log
│   │   │   ├── Billing/             # Plan, subscription, invoice, cổng thanh toán
│   │   │   ├── Usage/               # Quota, usage ledger, credit wallet
│   │   │   ├── Sites/               # Kết nối WordPress, connector protocol, health
│   │   │   ├── BusinessProfile/     # Ngành hàng, sản phẩm, dịch vụ, thị trường
│   │   │   ├── Research/            # Nghiên cứu ngành, đối thủ, nguồn
│   │   │   ├── Keywords/            # Từ khóa, ý tưởng, tiêu đề
│   │   │   ├── Planning/            # Topic cluster, content calendar
│   │   │   ├── Content/             # Brief, bài viết, phiên bản, pipeline viết
│   │   │   ├── Verification/        # Trích xuất claim, kiểm chứng, trích dẫn
│   │   │   ├── Media/               # Ảnh thật, ảnh AI, infographic
│   │   │   ├── Seo/                 # Tối ưu on-page, metadata, internal link
│   │   │   ├── Publishing/          # Duyệt, lịch đăng, đăng lên WP
│   │   │   ├── Analytics/           # Google Search Console, đề xuất cập nhật bài
│   │   │   ├── AiGateway/           # Provider, routing, chi phí, prompt template
│   │   │   └── Notifications/       # Email SMTP/Brevo, thông báo trong app
│   │   └── Providers/
│   ├── bootstrap/ config/ database/ routes/ lang/{vi,en}/ tests/
│   └── phpstan.neon, pint.json, phpunit.xml
├── frontend/                        # Vue 3 + TS + Tailwind, một project Vite, 2 entry
│   ├── src/
│   │   ├── apps/customer/           # SPA khách hàng (app.<domain>)
│   │   ├── apps/admin/              # SPA Super Admin (admin.<domain>)
│   │   ├── shared/                  # api client, ui components, composables, i18n, stores dùng chung
│   │   └── locales/{vi,en}/
│   ├── customer.html, admin.html
│   └── vite.config.ts, tsconfig.json, eslint.config.js
├── wordpress-plugin/
│   └── sitex-post/                  # Plugin, đóng gói zip để phát hành
│       ├── sitex-post.php
│       ├── includes/                # namespace SiteXPost\WP\
│       ├── languages/sitex-post.pot
│       └── readme.txt
├── docs/                            # Tài liệu kiến trúc
├── scripts/                         # Script kiểm tra (check.sh / check.ps1), đóng gói plugin
├── .github/workflows/               # CI (khi có repository GitHub)
├── CLAUDE.md
└── README.md
```

### 4.1 Cấu trúc bên trong một module

```
Modules/Sites/
├── Domain/          # Model Eloquent, enum, value object, domain event
├── Actions/         # Use case (một class = một hành động nghiệp vụ), gọi từ controller/job/console
├── Http/
│   ├── Controllers/
│   ├── Requests/    # FormRequest: validate + authorize
│   └── Resources/   # API Resource (định dạng response)
├── Jobs/            # Queue jobs
├── Policies/
├── Events/ Listeners/
├── Contracts/       # Interface module khác được phép dùng (public API của module)
├── Database/
│   ├── Migrations/
│   ├── Factories/
│   └── Seeders/     # Chỉ seeder demo (dev), seeder dữ liệu hệ thống bắt buộc tách riêng
├── routes.php       # Được nạp bởi ModuleServiceProvider
└── SitesServiceProvider.php
```

Test đặt trong `backend/tests/{Feature,Unit}/<Module>/` để chạy chung bằng Pest.

### 4.2 Quy tắc ranh giới module

1. Module khác chỉ được dùng `Contracts/`, `Actions/` công khai, domain event và Model ở chế độ **đọc** của module khác. Không ghi trực tiếp vào bảng của module khác.
2. Giao tiếp bất đồng bộ giữa module qua **domain event** (ví dụ `ArticleApproved` → Publishing lên lịch).
3. Không có foreign key "vòng" giữa module; FK chỉ trỏ về `workspaces`, `users`, hoặc bảng trong cùng module, trừ các tham chiếu được ghi rõ trong DATABASE.md.
4. Kiến trúc được kiểm tra tự động bằng Pest Arch tests (`arch()->expect('SiteXPost\Modules\Content')->not->toUse(...)`).

## 5. Ranh giới module chi tiết

| Module | Trách nhiệm | Không làm | Phụ thuộc |
|---|---|---|---|
| **Kernel** | TenantContext, global scope, ULID, audit helper, SsrfGuard, chữ ký, error envelope | Logic nghiệp vụ | — |
| **Identity** | User, đăng ký, đăng nhập, xác minh email, reset mật khẩu, 2FA, quản lý phiên | Vai trò tenant | Kernel |
| **Workspace** | Workspace (tenant), thành viên, lời mời, vai trò OWNER/ADMIN/EDITOR/VIEWER, chuyển workspace | Gói dịch vụ | Identity |
| **Platform** | Vai trò SUPER_ADMIN/SUPPORT/BILLING_ADMIN, dashboard admin, quản lý tenant, impersonation có audit | Thao tác dữ liệu tenant không qua Action | Identity, Workspace, Audit |
| **Audit** | Ghi log bất biến mọi hành động nhạy cảm | | Kernel |
| **Billing** | Plan, giá, subscription, hóa đơn, cổng thanh toán (adapter), webhook thanh toán | Đếm usage | Workspace |
| **Usage** | Định nghĩa quota theo plan, usage ledger, credit wallet, reserve/commit/release | Thu tiền | Billing (đọc entitlement) |
| **Sites** | Kết nối WP: pairing, khóa HMAC từng site, chữ ký, health check, thu hồi, client gọi plugin | Nội dung bài | Workspace, Usage |
| **BusinessProfile** | Ngành hàng, sản phẩm/dịch vụ, thị trường, đối tượng, giọng văn, từ cấm | | Workspace, Sites |
| **Research** | Thu thập nguồn web, phân tích ngành, đối thủ, SERP — lưu provenance mọi dữ liệu | Viết bài | AiGateway, Usage |
| **Keywords** | Khám phá từ khóa, ý tưởng, tiêu đề; số liệu chỉ từ nguồn thật (DataForSEO/GSC...) | Bịa search volume | Research, AiGateway |
| **Planning** | Topic cluster, pillar/cluster, content calendar | | Keywords |
| **Content** | Brief, bài viết, phiên bản (immutable), pipeline viết, trạng thái bài | Đăng bài | Research, AiGateway, Usage |
| **Verification** | Trích claim, đối chiếu nguồn, chấm điểm, cổng chặn xuất bản | | Content, Research |
| **Media** ✦ Phase 6 | Nguồn ảnh (WordPress/WooCommerce/upload), ảnh AI (gắn nhãn), xử lý ảnh, infographic, vị trí ảnh, alt text | Dùng ảnh AI làm ảnh sản phẩm thật | AiGateway (registry/route, chỉ đọc), Sites/Writer/Research qua Contracts |
| **Seo** ✦ Phase 6 | Metadata, schema, internal link, SEO audit có giải thích (không phải bảo đảm thứ hạng), bản render để đăng | Đăng WordPress | Writer, Sites, Research, Media qua Contracts |
| **Publishing** ✦ Phase 6 | Job đăng bài (state machine + cổng), lịch theo múi giờ site, auto-publish có điều kiện, đăng idempotent, xung đột, phục hồi, bulk | Ghi đè sửa tay trên WP | Writer, Sites (WordPressGateway), Seo (SeoGate), Media (MediaLibrary) qua Contracts |
| **Analytics** | OAuth Google Search Console, đồng bộ số liệu, phát hiện bài suy giảm, đề xuất cập nhật | | Sites, Content |
| **AiGateway** | Provider OpenAI/Gemini/Claude, routing theo task, retry/fallback, đo token & chi phí, ngân sách, prompt template có version, lọc output | Logic nghiệp vụ | Usage, Kernel |
| **Notifications** | Email (SMTP/Brevo), thông báo trong app, digest | | Identity |

## 6. Multi-tenancy

- **Tenant = Workspace.** Một user có thể thuộc nhiều workspace với vai trò khác nhau.
- **Xác định tenant**: từ route `/api/v1/workspaces/{workspace}/...` (ULID). Middleware `ResolveWorkspace`:
  1. Tìm workspace theo ULID trong URL.
  2. Kiểm tra user hiện tại là thành viên **đang hoạt động** (hoặc platform staff đang impersonate có audit).
  3. Nạp `TenantContext` (workspace + membership + role) cho request.
  4. Mọi `workspace_id` trong body/query **bị bỏ qua**; FormRequest loại bỏ trường này.
- **Global scope** `BelongsToWorkspace`: tự thêm `where workspace_id = ?` và tự gán `workspace_id` khi tạo. Truy vấn model tenant khi **không có** TenantContext sẽ **ném exception** (fail-closed), trừ khi gọi tường minh `withoutWorkspaceScope()` trong code platform có audit.
- **Queue job** mang `workspace_id`; middleware job `RestoreTenantContext` dựng lại context trước khi `handle()`.
- **Route model binding** tenant-aware: model con (site, article...) chỉ resolve trong workspace hiện tại → ID của tenant khác trả `404` (không phải 403, tránh lộ sự tồn tại).
- **Cache key, rate limit key, storage path** luôn có tiền tố `ws:{workspace_id}`.
- **Kiểm thử bắt buộc**: mỗi endpoint tenant có test "user workspace A không đọc/sửa/xóa được tài nguyên workspace B".

## 7. Pipeline AI nội dung (tổng quan)

> Sơ đồ dưới là thiết kế ban đầu. Phần idea → brief → sourcing → drafting → verification → duyệt đã được triển khai ở Phase 5 với trạng thái riêng — xem §8c và `AI-WRITER-PIPELINE.md`. Media, lịch đăng và đăng bài vẫn là phase sau.

Mỗi bước là một job idempotent, ghi `job_runs`, đặt trước credit (reserve) và chốt (commit) khi thành công.

```
Business profile ─► Research ngành/đối thủ ─► Keyword discovery ─► Topic clusters ─► Calendar
                                                                                         │
   ┌─────────────────────────────────────────────────────────────────────────────────────┘
   ▼
 idea ─► brief ─► sourcing (tìm & tải nguồn) ─► drafting ─► verification ─┬─► (fail) needs_revision ─► drafting
                                                                          ▼ (pass)
                          optimizing (SEO, internal link) ─► media ─► ready_for_review
                                                                          │
                                ┌──── approval_mode = manual ─────────────┤
                                ▼                                          ▼ approval_mode = auto
                          awaiting_approval ─► approved ──────────► scheduled ─► publishing ─► published
                                                                                     └► publish_failed (retry)
```

Quy tắc cứng:
- Không chuyển sang `scheduled` nếu `verification_status != passed`, kể cả ở chế độ auto.
- Mỗi lần AI hoặc người sửa nội dung tạo **phiên bản mới** (`content_versions`, immutable).
- Nguồn trích dẫn chỉ được lấy từ `research_sources` đã thực sự tải về; URL không có trong danh sách nguồn bị loại.
- Số liệu SEO (search volume, KD, thứ hạng) chỉ hiển thị khi có nguồn dữ liệu thật; nếu không có → hiển thị "Chưa có dữ liệu" kèm lý do.

## 8. AI Gateway (Phase 3 — đã triển khai, module `app/Modules/AiGateway`)

- **Adapter** (`Providers/`): `OpenAiProvider` (Responses API `POST /v1/responses`, ảnh qua `/v1/images/generations`), `GeminiProvider` (`models/{model}:generateContent`), `AnthropicProvider` (Messages API `POST /v1/messages`), `DeepSeekProvider` (`POST /chat/completions`, chế độ JSON `json_object`, `thinking` + `reasoning_effort`), `OpenAiCompatibleProvider` cho provider tùy chỉnh theo chuẩn OpenAI Chat Completions, `FakeProvider` cho test/dev. Adapter được chọn theo cột `ai_providers.driver` (`ProviderRegistry::for($provider)`), nên nhiều provider tùy chỉnh dùng chung một driver. Mỗi adapter có `listModels()` (gọi danh sách model miễn phí) cho Test Connection. Gọi bằng HTTP thuần của Laravel (ADR-011), không dùng SDK. Lỗi được phân loại trong `ProviderException`: an toàn để retry (429, 5xx, không kết nối được), **không chắc đã bị tính phí** (`response_timeout`), lỗi vĩnh viễn (4xx, `refused`).
- **Registry** (`ai_providers`, `ai_models`, `ai_model_prices`): model có `kind`, `tier`, `capabilities` (structured_output, web_search, vision, image_output, reasoning, context_window...). Giá có version, bất biến khi đã hiệu lực (ADR-014). `sitex:ai:sync-registry` chỉ nạp (insert) danh mục mặc định, không ghi đè thay đổi của admin; mọi model/giá/route quản lý trong DB qua trang Cấu hình AI (thêm, sửa Model ID, bật/tắt, xóa khi chưa dùng). Giá có thể có **khung giờ cao điểm** (`peak_windows`, UTC) và giá thấp điểm `offpeak_*`; chi phí tính theo thời điểm gọi, còn giữ chỗ ngân sách luôn dùng giá cao điểm.
- **Routing theo task** (`ai_task_routes`): `task_key` → chuỗi model (chính + fallback), queue, timeout, `max_attempts`, `allow_paid_retry`, giới hạn token. Model bị tắt, thiếu capability, hết giá hoặc circuit đang mở sẽ bị bỏ qua.
- **Structured output**: schema JSON gửi theo cách riêng của từng provider; kết quả luôn được kiểm tra lại bằng `JsonSchemaValidator` → `invalid_output` nếu sai.
- **Đo chi phí**: `CostCalculator` tính micro-USD từ input/output/cached/reasoning token, ảnh, lượt tìm kiếm theo version giá hiệu lực; mỗi lần gọi ghi một dòng `ai_requests` (kèm `price_id`, `call_key`, `prompt_hash`, độ trễ, mã lỗi đã lọc secret).
- **Ngân sách** (`BudgetManager`): `ai_budgets` theo `global` hoặc từng workspace, theo ngày/tháng. Trước mỗi lời gọi **giữ chỗ** chi phí ước tính tối đa trong transaction có khóa hàng (`ai_budget_counters` + `ai_budget_reservations`), sau đó commit chi phí thật hoặc release. Reservation quá hạn được `sitex:ai:expire-reservations` giải phóng. Vượt giới hạn → `budget_exceeded` (hành động `alert`/`pause_batch`/`pause_all`).
- **Bền vững**: timeout theo route/provider; deadline toàn lời gọi 240 s (< timeout job 300 s); retry trong provider chỉ với lỗi an toàn; `CircuitBreaker` theo provider+model (Redis/cache); fallback sang model kế tiếp.
- **Chống gọi trùng có tính phí**: mỗi lời gọi có `call_key` (run + step + cycle + attempt). Nếu reservation cho `call_key` đã tồn tại (job bị chạy lại) → `duplicate_call`, không gọi provider.
- **Chống prompt injection**: xem SECURITY.md §8. API key AI: nhập từ trang admin (mã hóa bằng APP_KEY, ADR-015) hoặc đặt trong `.env`; khóa trong DB được ưu tiên (SECURITY §5).
- **Test Connection / Test Model** (Phase 3E): Test Connection gọi danh sách model (miễn phí) và báo model nào đã cấu hình có tồn tại upstream; Test Model chạy một lời gọi thật nhỏ (tối đa 200 token ra) qua gateway với `AiTask(modelId, platform: true)`: chỉ áp ngân sách global, ghi `ai_requests` với workspace null, được cả với model đang tắt. Cả hai giới hạn 10 lần/phút/admin và được ghi audit.

## 8b. SEO Planner (Phase 4 — module `app/Modules/Research`, xem `PHASE4-REPORT.md`)

- **Workflow** `research.discovery` (12 bước) chạy trên workflow engine Phase 3: prepare → collect_website → collect_search_console → collect_competitors → analyze_profile → extract_keywords → expand_keywords → enrich_metrics → analyze → generate_ideas → plan_calendar → finalize. Mỗi bước idempotent (upsert theo fingerprint), retry/replay không tạo trùng.
- **Crawler** (`Crawler/PublicFetcher`): SsrfGuard + robots.txt (RFC 9309, cache, crawl-delay) + giới hạn tốc độ theo host + timeout + giới hạn byte + kiểm tra từng redirect. Đối thủ chỉ lưu title/heading/URL/thời điểm, không lưu thân bài.
- **Phân tích** thuần PHP, không cần AI: `KeywordExtractor` (n-gram tiếng Việt có dấu, từ điển từ ghép + liên kết đồng xuất hiện), `IntentClassifier`, `ResearchAnalyzer` (độ phủ/cannibalization/gap/cụm), `OpportunityScorer` (minh bạch, thành phần thiếu dữ liệu không tính), `IdeaPlanner`, `InternalLinkPlanner`, `CalendarPlanner`.
- **AI** (`Ai/ResearchAi`) gọi AI Gateway với route `research.profile`, `keywords.expand`, `topics.ideas` — model do registry/Super Admin cấu hình, có trần chi phí mỗi lần chạy.
- **Ranh giới**: Research đọc dữ liệu WordPress đã đồng bộ qua contract `Sites\Contracts\SiteDirectory` (read-only), không dùng `Sites\Domain` hay `Sites\Connector`, không gọi trực tiếp provider AI (arch test).

## 8c. AI Writer (Phase 5 — module `app/Modules/Writer`, xem `PHASE5-IMPLEMENTATION.md`)

- **Pipeline** (chi tiết `AI-WRITER-PIPELINE.md`): ResearchWorkflow → (duyệt brief) → OutlineWorkflow → (duyệt dàn ý) → DraftWorkflow (write → claims → factcheck → revise ≤ N vòng → quality) → biên tập → RecheckWorkflow → ApprovalGate. Trạng thái do `ArticleStateMachine` quản lý.
- **AI**: 11 task key (`writer.*`, `content.draft`, `verification.claims`) đăng ký trong AI Gateway; Super Admin chọn model. Không model ID trong code.
- **Bằng chứng**: chỉ nguồn do hệ thống tải (`SourceRetriever` dùng `Research\Contracts\PublicWebFetcher`), đoạn trích phải khớp nguyên văn; verdict quyết định bằng quy tắc (`VerdictRules`, `FACT-CHECK-POLICY.md`).
- **Ranh giới**: Writer đọc dữ liệu Phase 4 qua `Research\Contracts\PlannerDirectory`, dùng engine `Workflows`, gọi AI qua `AiGateway`. Không đăng WordPress (do Publishing Phase 6 đảm nhận qua `Writer\Contracts\ArticleCatalog`).
- **Bảo mật nội dung**: HTML sanitize ở backend (`HtmlSanitizer`) và frontend (`sanitizeArticleHtml`, chỉ render qua `SafeHtml`).

## 8d. Media, SEO & WordPress Publisher (Phase 6 — `app/Modules/{Media,Seo,Publishing}`, xem `PHASE6-IMPLEMENTATION.md`)

- **Media** (`MEDIA-ENGINE.md`): ảnh AI qua AI Gateway task `media.image`, chỉ model có capability `image_generation`; xử lý ảnh GD (kiểm tra MIME thật, re-encode, WebP/AVIF, dHash); lưu trữ riêng tư, chỉ phục vụ qua URL ký có hạn; infographic xác định từ dữ liệu có nguồn; `PlacementEngine` không cho ảnh AI vào ô ảnh sản phẩm.
- **Seo** (`SEO-AUDIT-RULES.md`): `PublishableRenderer` dựng đúng HTML sẽ đăng; `SeoRules` 19 quy tắc PASS/WARNING/ERROR/BLOCKED; metadata xác định (không AI); schema tắt mặc định và không trùng Yoast/Rank Math; link nội bộ chỉ tới URL thật.
- **Publishing** (`WORDPRESS-PUBLISHER.md`, `PUBLISHING-RECOVERY.md`): state machine 9 trạng thái, 8 cổng chạy lại ngay trước khi gọi connector, mode A–E (mặc định bản nháp), claim nguyên tử, mapping bài ⇄ post với khóa idempotency, đối soát sau timeout, phát hiện xung đột hai lớp (Cloud + plugin 409) — sửa P0 Phase 2 (cập nhật ghi đè sửa tay).
- **Ranh giới** (kiểm bằng `ArchitectureTest`): ba module không chạm Domain/Actions của module khác; chỉ dùng `Writer\Contracts\ArticleCatalog`, `Sites\Contracts\{SiteDirectory,WordPressGateway}`, `Research\Contracts\{PlannerDirectory,PublicWebFetcher}`, `Seo\Contracts\SeoGate`, `Media\Contracts\MediaLibrary` cùng vài tiện ích thuần (`Research\Support\Text`, `Writer\Support\{ArticleDocument,HtmlSanitizer}`). Chỉ Publishing gọi WordPress để đăng; Seo không phụ thuộc Publishing.
- **Scheduler**: `sitex:publishing:dispatch` mỗi phút (lịch đến hạn, lỡ lịch, job kẹt), `sitex:publishing:auto` mỗi giờ.
- **Queue**: `media` (xử lý ảnh, ảnh AI, đồng bộ Media Library), `default` (đăng bài, kiểm tra link, kiểm tra đồng bộ).

## 8e. Billing, credit & thanh toán (Phase 7 — `app/Modules/Billing`, xem `BILLING-ARCHITECTURE.md`, `PHASE7-IMPLEMENTATION.md`)

- **Catalog có version**: `plans` → `plan_versions` (bất biến sau publish) → `plan_entitlements`; `credit_packages`, `credit_policies` (versioned). Không hardcode giá gói, giá credit hay giá AI (giá vốn vẫn ở `ai_model_prices` Phase 3).
- **Subscription**: 8 trạng thái với bảng chuyển hợp lệ, mỗi chuyển ghi `subscription_events`; trial chống lạm dụng theo người chủ; nâng cấp áp ngay (proration tùy chọn), hạ cấp cuối kỳ; không auto-debit (`SUBSCRIPTION-LIFECYCLE.md`).
- **Entitlement**: `Billing\Contracts\Entitlements` được Sites, Workspace, Research, Writer, Media, Publishing gọi trước tác vụ tốn tài nguyên. `enforcement = off` mặc định → chỉ ghi nhận, không đổi hành vi Phase 1–6.
- **Credit AI** (`CREDIT-LEDGER.md`): sổ cái append-only, ví là projection; AI Gateway gọi `AiGateway\Contracts\UsageMeter` (Billing bind `CreditUsageMeter`) reserve → settle/release cùng khóa idempotency với `BudgetManager`; timeout không chắc chắn tính theo ước tính + cần duyệt.
- **Thanh toán** (`PAYMENT-RECONCILIATION.md`): đơn có mã `SXP…`, VietQR EMVCo; xác nhận chỉ qua webhook ký HMAC (chống replay) hoặc Billing Admin duyệt; `PaymentConfirmation` là nơi duy nhất chuyển `PAID`. Không scraping Internet Banking.
- **Kế toán**: biên nhận nội bộ (không phải hóa đơn GTGT); doanh thu tiền mặt/ghi nhận/hoãn lại; `cost_records` gộp từ `ai_requests`; báo cáo lợi nhuận chỉ cảnh báo.
- **Ranh giới** (kiểm bằng `ArchitectureTest`): `Billing\{Credits,Payments,Subscriptions,Entitlements}` chỉ dùng trong Billing (và seeder demo); module khác chỉ dùng `Billing\Contracts`.
- **Scheduler**: `sitex:billing:lifecycle` (15'), `sitex:billing:expire-credits` (5'), `sitex:billing:aggregate-costs --days=2` (1h), `sitex:billing:verify-ledger` (03:15).

## 8f. SEO Analytics & Autonomous SEO Agent (Phase 8 — `app/Modules/{Analytics,Agent}`, xem `PHASE8-IMPLEMENTATION.md`)
- **Analytics** 3 lớp: thu thập (GSC qua `Research\Contracts\SearchConsoleAccess`, không bảng GSC trùng) → phân tích (engine thuần: phân loại, decay, 15 quy tắc cơ hội, đề xuất, health, experiment) → hành động (làm mới nội dung qua Writer/fact-check/SEO Audit/Publisher, báo cáo, Copilot chỉ đọc). Chi tiết `ANALYTICS-ARCHITECTURE.md`, `GSC-INTEGRATION.md`, `SEO-OPPORTUNITY-RULES.md`.
- **Agent**: pipeline cố định 8 bước, registry 7 tool, policy engine phía server chạy lại ngay trước khi thực thi, mức tự động 0–3 (mặc định 1, mức 3 chỉ bật bằng xác nhận của người), dừng khẩn cấp + kill switch nền tảng. Chi tiết `AUTONOMOUS-SEO-AGENT.md`, `AGENT-POLICY-ENGINE.md`.
- **Contract mới**: `Analytics\Contracts\SeoInsights` (Agent đọc), `Research\Contracts\SearchConsoleAccess`, `Writer\Contracts\ArticleRefresh`, `Publishing\Contracts\{PublicationDirectory,PublishRequests}`; mở rộng `Sites\Contracts\SiteDirectory`, `Writer\Contracts\ArticleCatalog`.
- **Ranh giới** (`ArchitectureTest`): Analytics/Agent chỉ dùng contract của module khác; Agent không dùng nội bộ Analytics, không `Process`/shell; `ToolExecutor` chỉ dùng trong `Agent\Engine`.
- **Queue** `analytics` (`supervisor-analytics`). **Scheduler**: `sitex:analytics:sync` (1h), `sitex:analytics:refresh-tick` (5'), `sitex:analytics:prune` (03:40), `sitex:agent:schedule` (1'), `sitex:agent:recover` (5').

## 8g. Cài đặt, vận hành và nâng cấp (Phase 9 — `app/Modules/{Installer,System}`)
- **Installer**: chạy được khi chưa có `.env`/DB. `InstallerGuard` (token `X-Setup-Token`, khóa nhiều tín hiệu, rate limit) → `InstallerController` → `RunInstallation` (ghi `.env` qua `EnvWriter`, migrate, seed hệ thống, tạo Super Admin, khóa). `Support/{EnvironmentChecker,DatabaseProbe,RedisProbe,PendingConfig,SetupToken,InstallationState,DeploymentTemplates}`. Giao diện là HTML + JS tĩnh (`public/installer/`), không phụ thuộc SPA.
- **System**: `Health/HealthChecker` (16 mục), `Setup/SetupWizard` (12 bước, trạng thái tính từ cấu hình thật), `Upgrade/UpgradeInspector` + job `RunUpgrade`, `Backup/{BackupManager,BackupCrypto}` + job `RunBackup`/`VerifyBackup`, `Monitoring/{AlertDispatcher,SslProbe}` + lệnh `Monitor`/`Heartbeat`, `Support/{RuntimeConfig,SystemSettings}` (giá trị admin ghi đè `.env` lúc chạy).
- **Ranh giới**: System đọc AI và Billing qua contract `AiGateway\Contracts\AiReadiness`, `Billing\Contracts\BillingReadiness`. Ngoại lệ có chủ đích: health đọc **đếm tổng hợp chỉ-đọc** trực tiếp từ `sites`, `publishing_jobs`, `search_console_connections` (toàn nền tảng, không ghi, không trả dữ liệu tenant) — nên chuyển thành contract khi các module đó có API readiness. Mọi thao tác ra mạng (gửi mail/Telegram thử, Google OAuth thử, sao lưu) chạy trong queue.
- **Scheduler**: `sitex:system:heartbeat` (1'), `sitex:system:monitor` (5'), `sitex:backup:run --scheduled --verify` (15'), `sitex:backup:prune` (04:30).

## 9. Dịch vụ dữ liệu bên ngoài (adapter, cấu hình qua .env)

| Nhu cầu | Adapter dự kiến | Ghi chú |
|---|---|---|
| SERP + search volume | DataForSEO (mặc định), có thể thêm nhà cung cấp khác | Bắt buộc cho số liệu từ khóa; không có → không hiển thị số |
| Tìm kiếm web cho research | Brave Search API / Tavily (adapter) | Kết quả lưu làm `research_sources` |
| Hiệu quả thực tế | Google Search Console API (OAuth theo site) | Nguồn số liệu thứ hạng/clicks thật |
| Ảnh thật | Pexels / Unsplash (lưu license, tác giả) + ảnh do khách tải lên | |
| Ảnh AI | Provider ảnh qua AI Gateway | Luôn gắn `is_ai_generated=true` |
| Thanh toán | Phase 7: adapter `manual_bank` (VietQR + đối soát thủ công) và `signed_webhook` (dịch vụ báo giao dịch ký HMAC). Cổng cụ thể (PayOS/Casso/SePay/Stripe…) **chờ quyết định** | Không scraping Internet Banking |
| Email | SMTP hoặc Brevo (Laravel mailer) | |

## 10. Queue (Phase 3)

Redis + Horizon (`config/horizon.php`), tên queue tập trung ở `SiteXPost\Modules\Workflows\Support\Queues`.

| Queue | Dùng cho | Supervisor Horizon |
|---|---|---|
| `critical` | Thu hồi kết nối, webhook (Phase 4) | `supervisor-core` |
| `default` | Tác vụ ngắn | `supervisor-core` |
| `notifications` | Email | `supervisor-core` |
| `billing` | Ghi nhận sử dụng/thanh toán (Phase 4) | `supervisor-core` |
| `connector` | Gọi plugin WP (health, đồng bộ) | `supervisor-connector` |
| `publishing` | Đăng/cập nhật bài lên WP | `supervisor-publishing` |
| `writing`, `research`, `factcheck` | Workflow AI (tác vụ thử Phase 3 chạy ở `writing`) | `supervisor-ai` (tối đa 3 process, timeout 300 s) |
| `media` | Tải/tạo/xử lý ảnh | `supervisor-media` |
| `analytics` | Đồng bộ GSC | `supervisor-analytics` |

Bất biến thời gian: timeout job (300 s) < `retry_after` của Redis (330 s) < lease workflow (360 s). Không có Horizon (Windows/dev) → `php artisan queue:work --queue=critical,default,...` (xem DEPLOYMENT §6).

### 10.1 Workflow engine (module `app/Modules/Workflows`, ADR-013)

- **Trạng thái lưu DB**: `workflow_runs` (`pending → running → retry_scheduled/succeeded/failed/canceled`), `workflow_steps` (checkpoint output từng bước), `workflow_events` (lịch sử). Mọi chuyển trạng thái qua `WorkflowStateMachine` và cập nhật compare-and-set theo `version`.
- **Idempotency**: UNIQUE(`workspace_id`, `idempotency_key`) + hash đầu vào; cùng key khác nội dung → 409.
- **Job** `ProcessWorkflow` chỉ mang `run_id`; worker lấy **lease** (`locked_by`, `locked_until`), chạy từng bước chưa xong, lưu checkpoint sau mỗi bước.
- **Retry**: exponential backoff có jitter (`backoff_base` 15 s, tối đa 900 s), giới hạn `max_attempts`. Bước **tốn phí** (`isPaidStep`) bị gián đoạn hoặc timeout không chắc chắn → `failed` với `interrupted_paid_call` (không gọi lại), trừ khi route bật `allow_paid_retry`.
- **Đồng thời theo tenant**: `ConcurrencyLimiter` (tối đa `SITEX_WORKFLOW_TENANT_CONCURRENCY` run đang chạy/workspace, mặc định 2); vượt → hoãn `defer_seconds`.
- **Hủy**: đặt `cancel_requested`; worker dừng ở ranh giới bước.
- **Khôi phục**: `sitex:workflows:recover` mỗi phút lấy lại run có lease hết hạn hoặc `pending` quá lâu và dispatch lại.
- **Dead letter**: job thất bại hết lượt (`RecordFailedJob`) và run `failed` được ghi vào `dead_letters`; admin retry (mở cycle mới) hoặc discard.
- Engine không phụ thuộc AiGateway/Sites (kiểm bằng ArchitectureTest); workflow cụ thể (vd. `AiTestWorkflow`) đăng ký qua `WorkflowRegistry`.

## 11. Frontend

- Một project Vite, 2 entry: `customer.html` → `src/apps/customer`, `admin.html` → `src/apps/admin`. Build ra `dist/customer` và `dist/admin`, Nginx phục vụ static.
- Layout: sidebar + topbar, light mode mặc định (có sẵn token cho dark mode), responsive desktop/tablet/mobile (sidebar thành drawer trên mobile).
- `shared/ui`: Button, Input, Select, Modal, Table, EmptyState, LoadingState (skeleton), ErrorState, Toast, Badge, Pagination.
- API client typed (`fetch` wrapper) xử lý CSRF cookie Sanctum, envelope lỗi, 401/403/419/429.
- i18n: `vue-i18n`, khóa theo module (`sites.connect.title`), `vi` mặc định, không hardcode chuỗi hiển thị trong component.
- State: Pinia cho phiên/workspace hiện tại; dữ liệu server lấy qua composable có trạng thái `loading | error | empty | ready`.
- Workspace hiện tại nằm trong URL: `/w/{workspaceId}/...` để mở nhiều tab nhiều workspace.

## 12. Plugin WordPress

- Tối thiểu WP 6.4, PHP 7.4. Không phụ thuộc Composer khi chạy (vendor được bundle hoặc không dùng).
- REST namespace `sitex-post/v1`. Mọi endpoint (trừ `ping` công khai tối thiểu) yêu cầu chữ ký HMAC-SHA256 từ Cloud + chống replay (SECURITY §6.2).
- Ghép nối (pairing) bằng mã một lần do Cloud cấp; plugin tự sinh cặp khóa; không lưu mật khẩu WP, không dùng Application Passwords.
- Bài viết được tạo dưới **tài khoản WP được chỉ định** khi ghép nối (role Author/Editor), kiểm tra capability trước mỗi thao tác.
- Trang cài đặt trong WP admin: trạng thái kết nối, website Cloud đã ghép, nút ngắt kết nối, log gần nhất.
- Chi tiết giao thức: API-CONTRACTS.md §5 và SECURITY.md §6.

## 13. Quan sát & vận hành

- Laravel Horizon (queue), log JSON theo ngày, `request_id` xuyên suốt (header `X-Request-Id`), endpoint `/up`.
- Bảng `job_runs` cho tiến độ tác vụ hiển thị lên UI.
- Cảnh báo (Phase 9): System Health 16 mục, `sitex:system:monitor` gửi email/Telegram khi trạng thái xấu đi/phục hồi, có cooldown — `MONITORING-RUNBOOK.md`.
- Tùy chọn: Laravel Pulse; Sentry-compatible error tracking qua `.env` (không bắt buộc).

## 14. Cấu hình & môi trường

- Mọi đường dẫn, domain, secret qua `.env`; dùng `base_path()`, `storage_path()` — không hardcode `/www/wwwroot/...`.
- Dev trên Windows: PHP + Composer + Node chạy native; Horizon không chạy trên Windows → dùng `php artisan queue:work` hoặc WSL2. Docker không bắt buộc.
- `APP_ENV=production` khóa: seeder demo, route debug, `APP_DEBUG`.

## 15. ADR (Architecture Decision Records)

| # | Quyết định | Lý do |
|---|---|---|
| ADR-001 | Modular monolith Laravel 13 | Tốc độ phát triển, một codebase, tách dần khi cần |
| ADR-002 | Shared DB + `workspace_id` + global scope fail-closed | Chi phí vận hành thấp; an toàn nhờ fail-closed + test isolation |
| ADR-003 | Tự cài tenancy thay vì `stancl/tenancy` | Mô hình single-DB đơn giản; tránh "phép màu" khó audit |
| ADR-004 | Vai trò cố định bằng enum + Policy, không dùng `spatie/laravel-permission` | 3 vai trò platform + 4 vai trò tenant cố định; ma trận quyền nằm trong code, dễ test |
| ADR-005 | ULID làm khóa chính | Không đoán được ID, sắp xếp theo thời gian, an toàn khi lộ URL |
| ADR-006 | Sanctum SPA cookie auth (không lưu token trong localStorage) | Giảm rủi ro XSS đánh cắp token |
| ADR-007 | ~~Connector WP dùng Ed25519 hai chiều~~ → **Phase 2: khóa riêng từng site + HMAC-SHA256 (`SITEX-HMAC-V1`) hai chiều, ký cả response, nonce/timestamp** | Theo yêu cầu Phase 2 (per-site token + HMAC); chạy trên mọi host PHP 7.4, test vector chung; thu hồi/xoay từng site. Đánh đổi: Cloud giữ secret (mã hóa at-rest) — xem SECURITY §6 |
| ADR-008 | Pest 4 thay vì Pest 5 | Giữ tương thích PHP 8.3 cho môi trường dev hiện có |
| ADR-009 | TypeScript 6.0 thay vì 7.0 | `vue-tsc`/Volar chưa hỗ trợ TS 7 native |
| ADR-010 | MySQL 8.4 LTS | 8.0 đã EOL 04/2026 |
| ADR-011 | AI provider gọi bằng HTTP thuần (Laravel `Http`), không dùng SDK chính thức | SDK tự retry/timeout ngầm sẽ phá chính sách "không retry lời gọi tốn phí khi không chắc đã bị tính tiền"; HTTP thuần cho phép phân loại lỗi chính xác và test bằng `Http::fake`. Đánh đổi: tự cập nhật khi API đổi |
| ADR-012 | Đổi thứ tự phase: Phase 3 = AI Gateway & Queue, gói/thanh toán chuyển sang Phase 4 | Theo yêu cầu Phase 3 của chủ dự án; cơ chế reservation nguyên tử của ngân sách AI sẽ được tái dùng cho quota/credit |
| ADR-013 | Workflow engine tự viết trên DB (state machine + lease + checkpoint) thay vì chỉ dựa vào queue | Queue không đảm bảo trạng thái sau khi worker chết; DB là nguồn sự thật, job chỉ là tín hiệu "hãy chạy run X". Không dùng Laravel Workflow/Temporal để giữ hạ tầng đơn giản |
| ADR-014 | Tiền là số nguyên micro-USD, bảng giá có version bất biến | Tránh sai số float; hóa đơn/chi phí cũ giữ nguyên giá đã áp dụng (`ai_requests.price_id`) |
| ADR-015 | API key AI có thể lưu trong DB, mã hóa bằng APP_KEY (cast `encrypted`), ghi-một-chiều | Super Admin cần thêm provider tùy chỉnh và đổi khóa không cần SSH. Thay quy tắc "chỉ `.env`" của Phase 3. Khóa không bao giờ trả qua API (chỉ `has_api_key`, 4 ký tự cuối, nguồn), không vào audit/log. Đánh đổi: lộ APP_KEY + DB thì lộ khóa, nên xoay APP_KEY phải nhập lại khóa |
| ADR-016 | Đổi thứ tự phase lần 2: Phase 4 = SEO Planner (Topic Discovery), gói/thanh toán lùi sang sau | Theo yêu cầu Phase 4 của chủ dự án. Quota/ngân sách riêng cho research (`research_limits`, `research_usage_counters`) dùng cùng cơ chế reservation nguyên tử, sẽ gộp vào hệ thống gói khi làm billing |
| ADR-017 | Ý tưởng chủ đề sinh từ bằng chứng bằng quy tắc; AI chỉ mở rộng gợi ý từ khóa và diễn đạt tiêu đề, có kiểm tra lạc đề | Đảm bảo hệ thống hữu ích cả khi AI lỗi/tắt, mọi ý tưởng truy được nguồn, và không có số liệu do AI bịa. Đánh đổi: tiêu đề theo quy tắc kém tự nhiên hơn |
| ADR-018 | Phase 6 = Media + SEO Audit + WordPress Publisher (gộp "Media & SEO" và "Duyệt & xuất bản" của kế hoạch ban đầu) | Theo yêu cầu Phase 6 của chủ dự án; cần sửa P0 Phase 2 (ghi đè sửa tay) trước khi cho phép cập nhật bài. Phase 7–9 do chủ dự án xác định lại |
| ADR-019 | Không bao giờ tự ghi đè bài đã bị sửa trên WordPress: Cloud kiểm tra state có ký trước khi ghi, plugin kiểm tra lại nguyên tử (409), ghi đè chỉ sau quyết định của người kèm `expected.content_hash` | Kiểm tra một phía có race (sửa giữa lúc kiểm tra và lúc ghi). Đánh đổi: bài ghi trước plugin 1.1.0 chỉ so được theo thời điểm sửa |
| ADR-020 | Phase 7 Billing: credit đo bằng milli-credit trên sổ cái append-only; AI Gateway chỉ biết contract `UsageMeter`; xác nhận thanh toán chỉ qua webhook ký hoặc người duyệt; `enforcement` tắt mặc định | Không làm tròn mất tiền ở lời gọi nhỏ, không trừ hai lần khi retry (khóa chung với `BudgetManager`), không tin client; bật cưỡng chế là quyết định vận hành để không đổi hành vi khách đang dùng. Đánh đổi: khi `off` khách dùng AI không bị trừ credit |
| ADR-021 | Audit billing dùng chung `audit_logs` (action `billing.*`), chứng từ là biên nhận nội bộ (không phải hóa đơn GTGT), không auto-debit | Tránh bảng audit trùng; chưa có tích hợp hóa đơn điện tử; QR không hỗ trợ trừ tiền tự động. Đánh đổi: gia hạn cần khách thao tác, có ân hạn và nhắc trước |
| ADR-022 | Phase 8 gồm Search Console Analytics + SEO Agent (trước đây dự kiến cho Phase 9) theo yêu cầu của chủ dự án; dùng lại kết nối OAuth GSC của Phase 4 qua contract `SearchConsoleAccess`, không tạo bảng GSC thứ hai | Tránh hai nguồn token/kết nối cho cùng một site. Đánh đổi: Research và Analytics cùng phụ thuộc bảng `search_console_connections`, mọi thay đổi OAuth phải giữ tương thích cả hai |
| ADR-023 | SEO Agent là bộ điều phối có quy tắc: server dựng tác vụ từ dữ liệu tenant, AI chỉ được xếp hạng/bỏ bớt; policy engine phía server quyết định từng tác vụ; mức 3 không bao giờ tự bật | Chống prompt injection từ nội dung web, giữ mọi thay đổi website trong quy trình có kiểm soát (Writer → fact-check → SEO Audit → Publisher, ADR-019). Đánh đổi: Agent kém “linh hoạt” hơn một agent tự do, nhưng mọi hành động đều giải thích và kiểm toán được |
| ADR-024 | Quy tắc liên kết (bài mồ côi, điểm liên kết nội bộ) chỉ kết luận khi SiteX giữ nội dung của ≥ 80% bài | Không có nội dung thì không biết bài nào liên kết tới đâu; trước đây mọi bài bị gắn “mồ côi”. Đánh đổi: website ít bài do SiteX viết sẽ không có kết luận về liên kết |
| ADR-025 | Phase 9 Installer: web installer chỉ hoạt động với setup token lấy qua SSH và khóa vĩnh viễn bằng nhiều tín hiệu; không chạy lệnh root, không ghi đè DB có dữ liệu | Người đầu tiên truy cập domain không chiếm được Super Admin; xóa một file không mở lại installer. Đánh đổi: người cài phải có SSH một lần để lấy token |
| ADR-026 | Nâng cấp tách hai phần: mã nguồn do người vận hành cập nhật (SSH/`upgrade.sh`), web chỉ chạy migration bổ sung đã kiểm tra; khôi phục sao lưu chỉ qua SSH | Web không trở thành công cụ chạy lệnh/ghi đè mã tùy ý; thao tác phá dữ liệu luôn có người làm. Đánh đổi: không có "nâng cấp một nút" |
| ADR-027 | Sao lưu tích hợp mã hóa bằng khóa riêng `SITEX_BACKUP_ENCRYPTION_KEY` (không dùng APP_KEY), định dạng `SXPBK1` AES-256-GCM theo khối | Lộ file sao lưu không đủ để đọc; mất máy chủ không mất khóa nếu đã lưu riêng. Đánh đổi: thêm một khóa phải giữ cẩn thận |
