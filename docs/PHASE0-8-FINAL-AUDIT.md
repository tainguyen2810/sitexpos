# Audit cuối Phase 0–8

Ngày: 06/11/2026, trên `main` (758cae8, đủ Phase 1–8) + nhánh Phase 9. Nguồn bằng chứng: mã nguồn, test tự động (chạy lại toàn bộ trên MySQL 8.0.46 trong Phase 9), các báo cáo phase trong `docs/`, audit độc lập Phase 1–3 (`/mnt/project-files/phase1-audit/`, `phase2/`, `phase3/`), kiểm thử trình duyệt Phase 9 (E2E-TEST-REPORT).

## Quy ước

| Trạng thái | Nghĩa |
|---|---|
| COMPLETE | Có mã + test tự động; nếu cần hệ thống ngoài thì đã chạy với hệ thống thật hoặc phần đó không phải điều kiện chính |
| PARTIAL | Có nhưng thiếu một phần đáng kể so với yêu cầu |
| MISSING | Chưa có |
| DEMO_ONLY | Chỉ có với dữ liệu demo / giao diện mẫu |
| UNVERIFIED | Có mã + test với bản giả, **chưa** chạy với hệ thống thật (AI, WordPress, Google, ngân hàng) |
| BLOCKED | Không thể kiểm trong môi trường hiện có |

## Tổng quan

| Phase | Nội dung | Trạng thái | Ghi chú chính |
|---|---|---|---|
| 0 | Thiết kế: ARCHITECTURE, DATABASE, SECURITY, API-CONTRACTS, ROADMAP, ADR | COMPLETE | Tài liệu được cập nhật theo từng phase; lệch đã biết ghi trong tài liệu (envelope encryption chưa làm) |
| 1 | SaaS Core: xác thực, workspace, RBAC, audit, Super Admin | COMPLETE | 3 lỗi audit Phase 1 đã sửa (3d0871d); còn mở: email thông báo bảo mật, job xóa workspace |
| 2 | WordPress Connector + plugin | COMPLETE (Cloud) / UNVERIFIED (plugin trên PHP 7.4 thật, Yoast/RankMath/Woo thật) | E2E WordPress thật 82/82 ở Phase 2; P0 của audit (ghi đè bài đã sửa) đã giải quyết ở Phase 6 (ADR-019) |
| 3 | AI Gateway & Queue | COMPLETE / UNVERIFIED với provider thật | P0 F15 (thiếu usage = miễn phí) **sửa trong Phase 9** |
| 4 | SEO Planner | COMPLETE / UNVERIFIED với GSC, DataForSEO, crawl thật | Không bịa search volume: kiểm bằng test |
| 5 | AI Writer, Evidence, Fact-check | COMPLETE / UNVERIFIED với AI thật | Chất lượng tiếng Việt của model thật chưa đánh giá |
| 6 | Ảnh AI, SEO Audit, Publisher | COMPLETE / UNVERIFIED với WordPress thật cho kịch bản xung đột | N+1 thư viện media sửa trong Phase 9 |
| 7 | Billing, Credits, Payments | COMPLETE / UNVERIFIED với ngân hàng/cổng thật | `enforcement` mặc định `off`; giá thật chưa nhập (Q4) |
| 8 | Analytics, Content Intelligence, SEO Agent | COMPLETE / UNVERIFIED với GSC thật | Trang Thử nghiệm sập ở trình duyệt — **sửa trong Phase 9**; hiệu năng dashboard ~0,7 s p50 với 148k dòng (PERF-1) |

Không còn lỗi P0 hay P1 nào đang mở trong mã nguồn. Phần chưa xác minh đều cần hệ thống bên ngoài thật (xem §10).

## Phase 0 — Thiết kế

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Kiến trúc module, ranh giới, tenancy, ADR-001…024 | COMPLETE | `ARCHITECTURE.md`; `ArchitectureTest` kiểm ranh giới (Kernel không phụ thuộc controller module, chỉ AI Gateway gọi adapter, connector không chạm khóa AI…) |
| Schema và quy ước (ULID, `*_minor`, UTC) | COMPLETE | `DATABASE.md`; migration theo module |
| Ma trận quyền, connector, SSRF, prompt injection | COMPLETE | `SECURITY.md` |
| Envelope encryption `SITEX_MASTER_KEY` | MISSING (có chủ đích) | SECURITY §5 ghi là nợ kỹ thuật; dùng `Crypt`/APP_KEY |

## Phase 1 — SaaS Core

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Đăng ký, xác minh email, đăng nhập, quên mật khẩu, 2FA | COMPLETE | `RegistrationAndLoginTest`, `EmailVerificationAndPasswordTest`; E2E trình duyệt Phase 9 (đăng ký → xác minh qua link → onboarding) |
| Workspace, mời thành viên, chuyển workspace, chuyển quyền sở hữu | COMPLETE | `WorkspaceTest`, `InvitationTest`, `MemberTest`, `MembershipConcurrencyTest` (MySQL); E2E trình duyệt |
| Cô lập tenant | COMPLETE | `CrossTenantIsolationTest` (mọi route), `ThreeTenantIsolationTest` (Phase 9), E2E: URL tenant khác → 404 |
| Super Admin, impersonation chỉ đọc, audit | COMPLETE | `AdminApiTest`, `ImpersonationRevalidationTest`; `admin.js` 8/8 (Phase 9) |
| Thu hồi phiên/remember token, race chuyển chủ, kiểm lại quyền impersonation | COMPLETE | Sửa sau audit Phase 1 (3d0871d), có test |
| Giới hạn tần suất lời mời | COMPLETE | `throttle:invitation-public` |
| Email thông báo bảo mật | MISSING | SECURITY-AUDIT SEC-4 |
| Job xóa vĩnh viễn workspace sau hạn giữ | MISSING | `RequestWorkspaceDeletion` chỉ đổi trạng thái; SEC-5 |
| Bản dịch tiếng Anh | PARTIAL | Khung `en`, thiếu chuỗi → fallback tiếng Việt |
| Gửi email qua SMTP thật | UNVERIFIED | Chỉ `MAIL_MAILER=log`; Setup Wizard bước 4 có nút gửi thử |

## Phase 2 — WordPress Connector

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Ghép nối có xác nhận của admin WP, khóa HMAC riêng, chữ ký request/response, nonce, timestamp | COMPLETE | `PairingTest`, `ConnectorSignatureTest`, `SignerTest`; E2E WordPress 6.8.3 + 6.4.5 thật 82/82 (Phase 2) |
| Đồng bộ chuyên mục/thẻ/bài/trang/sản phẩm, đăng thử không trùng | COMPLETE | E2E Phase 2; `ConnectorOperationsTest` |
| Phát hiện bài bị sửa trên WP (P0 audit Phase 2) | COMPLETE | Giải quyết ở Phase 6: `/posts/{id}/state`, ADR-019, `PublishingTest` |
| `SITEX_SSRF_ALLOW_HTTP` bị bỏ qua ở production (P1 audit Phase 2) | COMPLETE | Sửa Phase 9: `SsrfGuard::fromConfig`, `SsrfProductionTest` |
| Retry/backoff khi site offline | COMPLETE | Phase 6 `PublishingTest` |
| Plugin trên PHP 7.4 thật, Plugin Check/PHPCS WP | UNVERIFIED | `build-plugin.sh` có kiểm cú pháp PHP 7.4 (lint), chưa chạy runtime 7.4 |
| Yoast/RankMath/WooCommerce thật | UNVERIFIED | E2E dùng bản giả lập |
| Khóa idempotent bằng `add_option`, multisite | PARTIAL | SEC-7; Cloud đối chiếu `/posts/lookup` trước khi ghi lại |
| Domain Cloud mặc định trong plugin | BLOCKED | Phụ thuộc Q2 (domain chính thức) |

## Phase 3 — AI Gateway & Queue

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Adapter OpenAI, Gemini, Anthropic, DeepSeek, OpenAI-compatible tùy chỉnh | UNVERIFIED | `ProviderAdaptersTest`, `OpenAiCompatibleAdaptersTest` với `Http::fake` theo tài liệu; chưa gọi API thật |
| Fallback, retry có backoff, circuit breaker, không retry khi không chắc đã tính tiền | COMPLETE | `AiGatewayTest` |
| Ngân sách giữ chỗ, không oversell đồng thời, không tính hai lần | COMPLETE | `BudgetTest` (MySQL nhiều tiến trình) |
| Provider không trả usage (P0 F15) | COMPLETE | Sửa Phase 9: chốt theo ước tính, cờ `billing_uncertain`, test mới |
| Workflow engine, khôi phục worker chết | COMPLETE | `WorkflowEngineTest`, `sitex:workflows:recover` mỗi phút, E2E restart worker (Phase 3) |
| Horizon dưới Supervisor | PARTIAL | Horizon chạy thật trên bản cài E2E Phase 9; chưa chạy dưới Supervisor (môi trường không có) |
| Tách server worker AI (`HORIZON_ROLE`) | MISSING | Dùng file `queue:work` mẫu (DEPLOYMENT §8) |
| Prompt template có version | MISSING | PHASE3-REPORT §5 |

## Phase 4 — SEO Planner

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Khám phá từ khóa/chủ đề từ dữ liệu website, cụm, kế hoạch nội dung | COMPLETE | `ResearchPipelineTest` |
| Không bịa search volume/KD; chỉ lưu số liệu DataForSEO có nguồn | COMPLETE | Test "never fabricates…", "stores verified DataForSEO metrics…" |
| Quota, idempotency, AI lỗi vẫn ra ý tưởng theo luật, robots.txt | COMPLETE | `ResearchPipelineTest` |
| GSC/DataForSEO/crawl thật | UNVERIFIED | Không có credentials |

## Phase 5 — AI Writer

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Pipeline nghiên cứu bằng chứng → dàn ý → viết → kiểm chứng; verdict do `VerdictRules` | COMPLETE | `WriterPipelineTest`, `WriterRulesTest` |
| An toàn HTML, prompt injection, lịch sử phiên bản | COMPLETE | `WriterSafetyTest`, `WriterReviewTest` |
| Chạy trên MySQL | COMPLETE | Phase 9: toàn bộ Pest trên MySQL 8.0.46 |
| Model thật, nguồn web thật | UNVERIFIED | Không có API key; nguồn `Http::fake` |
| Load test bulk/autosave | MISSING | Chưa có staging |

## Phase 6 — Ảnh, SEO Audit, Publisher

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Upload/kiểm tra/tối ưu ảnh, chặn file giả dạng; ảnh AI gắn nhãn, không làm ảnh sản phẩm | COMPLETE | `MediaTest` |
| SEO Audit checklist, canonical/noindex an toàn, schema, link nội bộ | COMPLETE | `SeoTest` |
| Publisher: cổng chạy lại, không trùng khi timeout, retry khi offline, không ghi đè bài đã sửa | COMPLETE (giả lập WP) | `PublishingTest` |
| WordPress thật cho kịch bản xung đột | BLOCKED | Proxy chặn tải WordPress (Phase 6 và Phase 9) |
| N+1 thư viện media | COMPLETE | Sửa Phase 9 + test |

## Phase 7 — Billing

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Gói, subscription, grace/expired, proration | COMPLETE | `SubscriptionLifecycleTest`, `BillingMathTest` |
| Ledger credit append-only, giữ chỗ/chốt/trả, `verify()` | COMPLETE | `AiCreditsTest`, `sitex:billing:verify-ledger` hằng ngày |
| VietQR là hướng dẫn, webhook ký, chống replay, duyệt tay khi lệch | COMPLETE | `PaymentFlowTest` |
| Entitlements (`enforcement` mặc định `off`) | COMPLETE | `EntitlementsAdminTest`; cần bật khi bán |
| Đồng thời ví/đơn trên MySQL | COMPLETE | Phase 9 chạy toàn bộ Pest trên MySQL; CI MySQL 8.4 |
| Ngân hàng/cổng thanh toán thật, hóa đơn VAT | BLOCKED | Q1 chưa chốt |
| Giá thật | BLOCKED | Q4: Super Admin nhập (không hardcode) |

## Phase 8 — Analytics & Agent

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Đồng bộ GSC: OAuth, phân trang, checkpoint, backoff quota, idempotent | COMPLETE (giả lập Google) | `SearchConsoleSyncTest` |
| Dashboard, decay, 15 quy tắc cơ hội có bằng chứng, health score | COMPLETE | `AnalysisApiTest`, `AnalyticsEngineTest` |
| Refresh qua Writer → fact-check → Audit → Publisher, trước/sau không phải A/B | COMPLETE | `ContentRefreshTest` |
| SEO Agent: policy engine, mức 0–3, dừng khẩn cấp, không trùng run | COMPLETE | `AgentApiTest`, `AgentPolicyEngineTest` |
| Màn hình trên trình duyệt | COMPLETE (hiển thị, dữ liệu demo) | Phase 9 `screens-smoke.js`: phát hiện và sửa trang Thử nghiệm sập + tràn ngang mobile |
| Số liệu khớp GSC thật | BLOCKED | Cần property thật |
| Hiệu năng với dữ liệu lớn | PARTIAL | PERF-1: 0,7 s p50 / 1 s p95 với 148k dòng; đề xuất index + bảng tổng hợp |

## 9. Lỗi đã sửa trong Phase 9 (từ audit này)

| Mức | Lỗi | Commit/test |
|---|---|---|
| P0 | Provider không trả usage → AI miễn phí | `AiGatewayTest` "charges the reservation estimate…" |
| P1 | SSRF dev switches có hiệu lực ở production | `SsrfProductionTest` |
| P1 | Trang Thử nghiệm SEO sập (`external_factors.join`) | `screens-smoke.js` |
| P2 | Health "Thanh toán" sai cột (`created_at` → `received_at`), SQLite che mất | `SystemAdminTest` |
| P2 | Tràn ngang ở 390 px trên 5 màn hình (lưới/bảng) | `screens-smoke.js` 79/79 |
| P3 | N+1 thư viện media | `MediaTest` |
| P3 | Test làm rơi 3.361 file vào storage thật | `tests/TestCase.php` |
| Tài liệu | DEPLOYMENT.md nói tới `sitex:keys:generate` / `SITEX_MASTER_KEY` không tồn tại | Đã sửa |

## 10. Việc còn lại, theo ưu tiên

| Ưu tiên | Việc | Cần |
|---|---|---|
| Trước khi bán | Chạy thật với AI provider, WordPress (6.4 + mới nhất, Yoast/RankMath/Woo thật), GSC, cổng thanh toán | Credentials + staging |
| Trước khi bán | Job xóa dữ liệu workspace theo hạn giữ + xuất dữ liệu (SEC-5), chính sách quyền riêng tư (Q5) | Quyết định chính sách |
| Trước khi bán | Nhập giá thật, bật `enforcement` | Q4 |
| Trước khi bán | Domain chính thức, URL mặc định trong plugin | Q2 |
| Sớm | Email thông báo bảo mật (SEC-4), CSP cho SPA (SEC-6) | — |
| Sớm | PERF-1: index + bảng tổng hợp/cache cho analytics | Đo trên staging |
| Sau | Envelope encryption + xoay khóa (SEC-3), `HORIZON_ROLE`, prompt template có version, bản dịch EN | — |
