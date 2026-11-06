# Phase 8: SEO Analytics, Content Intelligence & Autonomous SEO Agent

- **Nhánh**: `claude/project-thread-pkiwke`, tách từ `main` `de6a4c0` (Merge PR #2: Phase 7). Không merge, không deploy, không bắt đầu Phase 9.
- **Kiểm thử**: xem `PHASE8-TEST-REPORT.md` (kết quả thật đã chạy).

## 0. Dependency audit (làm trước khi code)
Kiểm tra trên `main` sau khi Phase 7 merge (`de6a4c0`, toàn bộ Phase 1–7 có trên `main`). Bản audit đầy đủ: `/mnt/project-files/phase8/PHASE8-DEPENDENCY-AUDIT.md`.

| Phụ thuộc | Có sẵn | Phase 8 làm gì |
|---|---|---|
| GSC OAuth (Phase 4, `Research`) | OAuth, `search_console_connections` (token mã hóa), chọn property, chỉ chiều `query`, không phân trang | **Mở rộng**, không viết lại: contract `SearchConsoleAccess`, kiểm tra scope trả về, thu hồi token tại Google khi ngắt kết nối, Search Analytics nhiều chiều + phân trang |
| WordPress inventory (Phase 2, `Sites`) | `SiteDirectory::content()` | Thêm `contentIndex()` (url, wp_post_id, status, modified_at) cho ánh xạ URL và quy tắc liên kết |
| Writer + fact-check (Phase 5) | Phiên bản bài, fact-check, `VerdictRules` | Contract `ArticleRefresh`: phiên bản mới từ bài đã duyệt, viết lại từng section qua AI Gateway, fact-check lại |
| SEO Audit + Publisher (Phase 6) | Cổng đăng bài, phát hiện sửa trên WordPress (ADR-019) | Contract `PublicationDirectory` (mapping URL ↔ bài, kiểm tra trạng thái WordPress) và `PublishRequests` (yêu cầu đăng qua Publisher, mọi cổng chạy lại) |
| AI Gateway + Workflows (Phase 3) | Workflow, budget, `FakeProvider` | Copilot diễn đạt + PlanAdvisor của Agent chạy qua workflow/queue, không gọi provider trong HTTP request |
| Billing (Phase 7) | `Entitlements`, credit ledger | Policy engine kiểm tra quyền theo gói; refresh kiểm tra `ai_writer`; chi phí AI qua Gateway như cũ (không trừ credit hai lần) |

Không tạo bảng trùng Phase 4 (không có `gsc_connections` / `analytics_connections` mới).

## 1. Module & mã nguồn
| Khu vực | Đường dẫn |
|---|---|
| Analytics | `backend/app/Modules/Analytics/{Actions,Connectors,Console,Contracts,Database,Domain,Engine,Http,Jobs,Notifications,Policies,Queries,Support}` |
| Agent | `backend/app/Modules/Agent/{Actions,Ai,Console,Contracts,Database,Domain,Engine,Http,Jobs,Policies,Policy,Queries,Support,Tools}` |
| Contract mới ở module khác | `Research/Contracts/SearchConsoleAccess`, `Writer/Contracts/ArticleRefresh`, `Publishing/Contracts/{PublicationDirectory,PublishRequests}`; mở rộng `Sites/Contracts/SiteDirectory`, `Writer/Contracts/ArticleCatalog` |
| Demo | `backend/database/seeders/Phase8DemoSeeder.php` (gọi từ `DemoSeeder`) |
| Frontend khách hàng | `frontend/src/apps/customer/pages/analytics/` (17 trang + component dùng chung), `frontend/src/apps/customer/pages/agent/` (6 trang) |
| Frontend Super Admin | `frontend/src/apps/admin/pages/AnalyticsAdminPage.vue` |
| API client | `frontend/src/shared/api/{analytics,agent,analyticsAdmin}.ts` |
| i18n | `frontend/src/locales/analytics.vi.ts` (`an`, `ag`, `anAdmin`) |

## 2. Migration
- `2026_11_13_000100_create_analytics_tables.php`: 18 bảng (`analytics_settings`, `analytics_properties`, `analytics_sync_runs`, `search_performance_daily`, `search_query_metrics`, `search_page_metrics`, `search_query_page_metrics`, `analytics_url_mappings`, `content_performance_snapshots`, `seo_opportunities`, `content_decay_events`, `seo_recommendations`, `seo_experiments`, `experiment_observations`, `content_refresh_jobs`, `seo_health_scores`, `seo_reports`, `copilot_queries`).
- `2026_11_13_000200_create_agent_tables.php`: 7 bảng (`agent_policies`, `agent_configs`, `automation_schedules`, `agent_runs`, `agent_tasks`, `agent_action_logs`, `agent_memories`).
- Chi tiết cột/khóa: `DATABASE.md §3.13`. Migration chỉ thêm bảng, không sửa bảng Phase 1–7.

## 3. API
64 endpoint tenant (`/api/v1/workspaces/{ws}/analytics/*`, `/agent/*`) + 4 endpoint Super Admin. Bảng đầy đủ: `API-CONTRACTS.md §2.7, §2.8, §3`. Tác vụ > 2 giây (đồng bộ, phân tích, refresh, báo cáo, Copilot AI, run Agent) trả 202 và chạy ở queue `analytics`. Mọi thao tác ghi gọi `authorize` + `Audit::record` (`analytics.*`, `agent.*`).

## 4. Màn hình
**Khách hàng (23)** — Analytics: Tổng quan, Kết nối dữ liệu (GSC), Truy vấn, Trang, Bài viết, Chi tiết bài viết, Phân loại nội dung, Cơ hội SEO, Suy giảm, Đề xuất, Làm mới nội dung, Chi tiết làm mới (diff theo section, fact-check, SEO audit, kiểm tra WordPress), Theo dõi thay đổi, Sức khỏe SEO, Báo cáo, SEO Copilot, Cài đặt. Agent: Cấu hình (mức tự động, tool, giới hạn, dừng khẩn cấp, xác nhận mức 3), Chờ duyệt, Lượt chạy, Chi tiết lượt chạy (8 bước, tác vụ, nhật ký), Lịch chạy, Nhật ký & bộ nhớ.
**Super Admin (1)**: Analytics & Agent — tổng quan đồng bộ/lỗi/chi phí, tổng quan Agent, chính sách nền tảng + kill switch.
Mọi màn hình có loading / empty / error state, chuỗi qua `vue-i18n`, không `v-html`, ẩn/hiện theo `permissions[]` (backend vẫn kiểm tra).

## 5. Trạng thái tích hợp GSC
- Code hoàn chỉnh, test bằng `Http::fake` (OAuth, scope thiếu, refresh token, phân trang, quota/backoff, checkpoint, dedupe, thu hồi token).
- **Chưa chạy với Google thật** (không có `GOOGLE_CLIENT_ID/SECRET` trong phiên này). Cần đối chiếu mẫu số liệu với giao diện GSC khi có tài khoản thật.
- GA4: adapter giữ chỗ, UI ghi “chưa hỗ trợ”.

## 6. Agent: tool & mức tự động
7 tool (`read_analytics`, `read_wp_content`, `analyze_site`, `run_seo_audit`, `create_proposal`, `create_refresh_brief`, `publish_via_workflow`). Mức 0 chỉ đọc · 1 đề xuất (mặc định) · 2 tự chuẩn bị · 3 đăng có kiểm soát (chỉ bật bằng xác nhận của người có `sites.manage`, nền tảng có thể giới hạn). Không có tool SQL/shell/HTTP tùy ý/billing/API key/phân quyền.

## 7. Dữ liệu demo (chỉ development)
`php artisan migrate:fresh --seed --seeder=DemoSeeder` — workspace “Cửa hàng A (Demo)”: 3 site (.test), 12 tháng số liệu GSC **tổng hợp** (mọi hàng `is_demo = true`, property `sc-domain:*.test`), 100 bài đã duyệt có mapping, 509 truy vấn, phân tích bằng engine thật (≈ 200 cơ hội, ≈ 200 đề xuất, decay, health 12 tháng), 20 lượt Agent, 10 refresh job, 5 experiment, 6 báo cáo tháng. Từ chối chạy trong production; idempotent. Seeder chạy ≈ 15 giây trên sqlite.

## 8. Thay đổi so với thiết kế
- Phần “Search Console & làm mới nội dung” của Phase 9 cũ làm trong Phase 8 (ADR-022).
- Quy tắc `orphan_content` và thành phần health `internal_linking` chỉ kết luận khi SiteX giữ nội dung ≥ 80% bài (ADR-024) — phát hiện khi chạy dữ liệu demo: trước đó mọi bài không có nội dung đều bị gắn “mồ côi”.

## 9. Giới hạn đã biết
- Chưa kiểm thử với Google Search Console thật, chưa kiểm thử AI provider thật (dùng `FakeProvider`).
- Copilot hiểu câu hỏi bằng quy tắc theo ý định (intent); câu hỏi ngoài các ý định đã biết trả lời chung chung kèm dữ liệu tổng quan.
- `content_missing_info`, `outdated_info`, `internal_link_opportunity`, `duplicate_title_meta`, `broken_internal_links` cần nội dung/danh mục WordPress đã đồng bộ; site chưa ghép đôi sẽ không có kết luận ở các quy tắc này.
- Search Console chỉ có ~16 tháng dữ liệu; so sánh cùng kỳ năm trước chỉ có khi đã backfill đủ.
- Chưa có kiểm thử E2E trình duyệt cho các màn hình Phase 8 (có typecheck, lint, Vitest và build).
