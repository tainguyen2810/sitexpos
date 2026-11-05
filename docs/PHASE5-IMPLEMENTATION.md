# Phase 5 — AI Content Writer, Evidence Research & Fact-check Engine

Nhánh: `feature/phase5-ai-writer` (tách từ `feature/phase4-topic-discovery` @ a6a9afe). Trạng thái: **chờ người dùng nghiệm thu**. Chưa merge, chưa deploy.

Tài liệu liên quan: `AI-WRITER-PIPELINE.md`, `FACT-CHECK-POLICY.md`, `CONTENT-QUALITY-RUBRIC.md`, `PHASE5-TEST-REPORT.md`.

## 1. Bản đồ code

### Backend — `backend/app/Modules/Writer`
| Thư mục | Nội dung |
|---|---|
| `Domain/` | 24 model tenant (`BelongsToWorkspace`) + `WriterSetting` |
| `Enums/` | `ArticleStatus` (13 trạng thái), `RiskLevel`, `Verdict` |
| `Actions/` | `CreateArticle`, `ArticleWorkflows` (start/resume/cancel, giới hạn đồng thời), `Editorial` (duyệt brief/dàn ý, xác minh claim, duyệt bài), `Batches` |
| `Workflows/` | `ResearchWorkflow`, `OutlineWorkflow`, `DraftWorkflow`, `RecheckWorkflow`, `RewriteWorkflow` (chạy trên engine `Workflows` của Phase 3) |
| `Pipeline/` | `ResearchEngine`, `BriefBuilder`, `OutlineBuilder`, `SectionWriter`, `ClaimExtractor`, `RuleClaims`, `FactChecker`, `VerdictRules`, `ConsistencyChecker`, `Reviser`, `QualityAuditor`, `QualityScorer`, `DuplicateDetector`, `VersionManager`, `ApprovalGate` |
| `Retrieval/` | `SourceRetriever` (SSRF, robots, captcha/paywall), `PageExtractor`, `SourceClassifier`, `InjectionScanner` |
| `Ai/` | `WriterAi` (prompt + JSON schema theo task), `AiCaller` (gọi AI Gateway, ghi chi phí, ngân sách) |
| `Support/` | `ArticleStateMachine`, `HtmlSanitizer`, `TextDiff`, `Verbatim`, `Identifiers`, `WriterQuota`, `WriterSettings` |
| `Http/` | 8 controller, 15 form request, `WriterPresenter`, `Idempotency` |
| `Console/` | `sitex:writer:sync`, `sitex:writer:autonomous` (tắt mặc định) |

Phần chạm module khác (tối thiểu, không viết lại): `AiGateway/Registry/AiRegistry` (đăng ký task writer), `Research/Contracts/{PlannerDirectory,PublicWebFetcher}` + `PlannerDirectoryService` (cho Writer đọc ý tưởng/cluster Phase 4 và dùng lại fetcher).

Seeder: `database/seeders/WriterDemoSeeder.php` (gọi từ `DemoSeeder`, từ chối chạy khi `APP_ENV=production`).

### Frontend
| Đường dẫn | Nội dung |
|---|---|
| `src/shared/api/writer.ts`, `writerAdmin.ts` | Client API có kiểu + helper (tone, verdict, tiền tệ, idempotency key) |
| `src/shared/utils/articleHtml.ts` | `sanitizeArticleHtml` (DOMPurify, allowlist giống backend), `isSafeHref`, `wordCount` |
| `src/apps/customer/pages/writer/` | `ArticleLibraryPage`, `WizardPage`, `ArticlePage` (vỏ có tab), `BulkPage`, `PresetsPage`, `HistoryPage`; `tabs/` (Overview, Research, Brief, Outline, Editor, FactCheck, Quality, Versions); `components/` (RichEditor, DiffView, WorkflowProgress, StatusBadge…) |
| `src/apps/admin/pages/WriterAdminPage.vue` | Super Admin: settings, workspace override, routing (chỉ đọc, dẫn sang AI settings), costs, runs, failures, abuse |
| `src/locales/writer.*.ts`, `writerAdmin.*.ts` | Chuỗi vi/en, đủ cặp khóa (có test) |

## 2. 12 trang khách hàng (mục 22)

| # | Trang | Route |
|---|---|---|
| 1 | Article Library | `/writer` |
| 2 | AI Writer Wizard (17 bước gom 6 nhóm) | `/writer/new` |
| 3 | Research Workspace | `/writer/articles/:id/research` |
| 4 | Content Brief Editor | `/writer/articles/:id/brief` |
| 5 | Outline Editor | `/writer/articles/:id/outline` |
| 6 | AI Article Editor | `/writer/articles/:id/editor` |
| 7 | Fact-Check Report | `/writer/articles/:id/fact-check` |
| 8 | Quality Audit | `/writer/articles/:id/quality` |
| 9 | Article Versions | `/writer/articles/:id/versions` |
| 10 | Bulk Generation | `/writer/bulk` |
| 11 | Writing Presets | `/writer/presets` |
| 12 | Writing History | `/writer/history` |

Mọi trang có trạng thái loading / empty / error, tiến trình (polling khi bài đang chạy workflow), tìm/lọc/sắp xếp (trong phạm vi API hỗ trợ — xem §4), nút theo quyền (`content.edit` sửa, `content.publish` duyệt; chế độ impersonate chỉ đọc), bố cục responsive, tiếng Việt mặc định.

## 3. Super Admin (mục 23)
Trang `/writer` trong app admin. Không tạo lại AI Gateway: routing writer chỉ hiển thị task → provider/model hiện tại và dẫn sang `/ai/settings`. Quyền: `platform.ai.manage` (settings, workspace, routing, abuse), `platform.ai.view_costs` (costs), `platform.jobs.manage` (runs, failures).

## 4. Giới hạn đã biết
- Article Library tìm theo **tiêu đề** (`articles?q=`); **chưa có API tìm kiếm toàn văn** trong nội dung bài hay trong lịch sử.
- Chưa có lời gọi AI thật và chưa tải nguồn web thật trong môi trường dev này (không có API key, mạng sandbox). Mọi test dùng `FakeProvider`/`Http::fake`.
- `allow_web_search` (cho model tìm web qua provider) mặc định tắt; khi bật vẫn chỉ coi kết quả là *gợi ý URL* để hệ thống tải.
- Larastan chưa cài trong `vendor` của sandbox nên bước phpstan của `scripts/check.sh` không chạy được ở đây.
- Không đăng WordPress, không auto-publish (thuộc phase sau).
