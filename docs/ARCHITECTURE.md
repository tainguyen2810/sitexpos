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
         Lệnh có chữ ký Ed25519 (SaaS → WP)          ┌──────────────────────┐  AI Gateway │
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
| **Sites** | Kết nối WP: pairing, khóa Ed25519, chữ ký, health check, thu hồi, client gọi plugin | Nội dung bài | Workspace, Usage |
| **BusinessProfile** | Ngành hàng, sản phẩm/dịch vụ, thị trường, đối tượng, giọng văn, từ cấm | | Workspace, Sites |
| **Research** | Thu thập nguồn web, phân tích ngành, đối thủ, SERP — lưu provenance mọi dữ liệu | Viết bài | AiGateway, Usage |
| **Keywords** | Khám phá từ khóa, ý tưởng, tiêu đề; số liệu chỉ từ nguồn thật (DataForSEO/GSC...) | Bịa search volume | Research, AiGateway |
| **Planning** | Topic cluster, pillar/cluster, content calendar | | Keywords |
| **Content** | Brief, bài viết, phiên bản (immutable), pipeline viết, trạng thái bài | Đăng bài | Research, AiGateway, Usage |
| **Verification** | Trích claim, đối chiếu nguồn, chấm điểm, cổng chặn xuất bản | | Content, Research |
| **Media** | Ảnh stock có license, ảnh AI (gắn nhãn), infographic, alt text | Dùng ảnh AI làm ảnh sản phẩm thật | AiGateway, Usage |
| **Seo** | Metadata, schema, heading, internal link, SEO checklist (không phải bảo đảm thứ hạng) | | Content, Sites |
| **Publishing** | Quy trình duyệt, lịch, auto-publish có điều kiện, đẩy lên WP, đồng bộ trạng thái | | Content, Verification, Sites |
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

## 8. AI Gateway

- Interface `AiProvider` với adapter `OpenAiProvider`, `GeminiProvider`, `AnthropicProvider`.
- **Routing theo task** (`research.summarize`, `keywords.expand`, `content.draft`, `verification.claims`, `media.image`...) cấu hình bởi Super Admin: provider chính, model, fallback, giới hạn token, nhiệt độ.
- **Structured output**: mọi task trả JSON được validate bằng JSON Schema; lỗi schema → retry có giới hạn → fail rõ ràng.
- **Đo chi phí**: mỗi lời gọi ghi `ai_requests` (provider, model, token in/out, chi phí micro-USD, độ trễ, workspace, task, job). Bảng giá model do admin quản lý.
- **Ngân sách**: giới hạn chi phí theo ngày/tháng cho từng workspace và toàn hệ thống; vượt ngưỡng → tạm dừng job mức thấp + cảnh báo admin.
- **Bền vững**: timeout, retry với backoff, circuit breaker theo provider, fallback provider.
- **Chống prompt injection**: xem SECURITY.md §8.
- API key AI chỉ nằm trong `.env`/secret store của Cloud.

## 9. Dịch vụ dữ liệu bên ngoài (adapter, cấu hình qua .env)

| Nhu cầu | Adapter dự kiến | Ghi chú |
|---|---|---|
| SERP + search volume | DataForSEO (mặc định), có thể thêm nhà cung cấp khác | Bắt buộc cho số liệu từ khóa; không có → không hiển thị số |
| Tìm kiếm web cho research | Brave Search API / Tavily (adapter) | Kết quả lưu làm `research_sources` |
| Hiệu quả thực tế | Google Search Console API (OAuth theo site) | Nguồn số liệu thứ hạng/clicks thật |
| Ảnh thật | Pexels / Unsplash (lưu license, tác giả) + ảnh do khách tải lên | |
| Ảnh AI | Provider ảnh qua AI Gateway | Luôn gắn `is_ai_generated=true` |
| Thanh toán | Adapter: Stripe (quốc tế) + cổng nội địa (PayOS/VNPay/MoMo) — **chờ quyết định** | |
| Email | SMTP hoặc Brevo (Laravel mailer) | |

## 10. Queue

| Queue | Dùng cho | Ưu tiên |
|---|---|---|
| `critical` | Webhook thanh toán, thu hồi kết nối | cao nhất |
| `default` | Tác vụ ngắn | |
| `notifications` | Email | |
| `connector` | Gọi plugin WP (health, đồng bộ taxonomy) | |
| `publishing` | Đăng/cập nhật bài lên WP | |
| `ai-interactive` | Tác vụ AI do người dùng đang chờ | |
| `ai-batch` | Pipeline nền (research, viết hàng loạt) | |
| `media` | Tải/tạo/xử lý ảnh | |
| `analytics` | Đồng bộ GSC | thấp |

Horizon cấu hình supervisor riêng cho nhóm AI để giới hạn đồng thời (tránh vượt rate limit provider). Fairness: giới hạn số job AI đồng thời **mỗi workspace** bằng `RateLimited`/`WithoutOverlapping` middleware theo key workspace.

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
- REST namespace `sitex-post/v1`. Mọi endpoint (trừ `ping` công khai tối thiểu) yêu cầu chữ ký Ed25519 từ Cloud + chống replay.
- Ghép nối (pairing) bằng mã một lần do Cloud cấp; plugin tự sinh cặp khóa; không lưu mật khẩu WP, không dùng Application Passwords.
- Bài viết được tạo dưới **tài khoản WP được chỉ định** khi ghép nối (role Author/Editor), kiểm tra capability trước mỗi thao tác.
- Trang cài đặt trong WP admin: trạng thái kết nối, website Cloud đã ghép, nút ngắt kết nối, log gần nhất.
- Chi tiết giao thức: API-CONTRACTS.md §5 và SECURITY.md §6.

## 13. Quan sát & vận hành

- Laravel Horizon (queue), log JSON theo ngày, `request_id` xuyên suốt (header `X-Request-Id`), endpoint `/up`.
- Bảng `job_runs` cho tiến độ tác vụ hiển thị lên UI.
- Cảnh báo: failed jobs tăng, queue backlog, chi phí AI vượt ngưỡng, site mất kết nối, webhook lỗi.
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
| ADR-007 | Connector WP dùng Ed25519 hai chiều + nonce/timestamp | Không chia sẻ secret đối xứng; thu hồi được từng site |
| ADR-008 | Pest 4 thay vì Pest 5 | Giữ tương thích PHP 8.3 cho môi trường dev hiện có |
| ADR-009 | TypeScript 6.0 thay vì 7.0 | `vue-tsc`/Volar chưa hỗ trợ TS 7 native |
| ADR-010 | MySQL 8.4 LTS | 8.0 đã EOL 04/2026 |
