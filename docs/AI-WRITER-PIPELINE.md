# AI Writer Pipeline (Phase 5)

Module: `backend/app/Modules/Writer`. Mọi lời gọi AI đi qua AI Gateway (Phase 3) bằng **task key**, không có model ID trong code. Super Admin chọn provider/model cho từng task ở `/ai/settings` (Admin → AI routing). Không lời gọi provider nào chạy trong HTTP request: mọi bước AI chạy trong workflow (module `Workflows`) trên queue.

## 1. Luồng tổng thể

```
Wizard (17 bước, 10 loại nội dung)
  → CreateArticle  (IDEA_SELECTED)
  → ResearchWorkflow   RESEARCHING → RESEARCH_COMPLETE → BRIEF_READY
        câu hỏi nghiên cứu → tìm nguồn → tải nguồn (SourceRetriever) → trích đoạn nguyên văn → brief
  → [duyệt brief nếu review_mode = step_by_step | brief_only]
  → OutlineWorkflow    OUTLINING
  → [duyệt dàn ý nếu step_by_step]
  → DraftWorkflow      WRITING → FACT_CHECKING → (REVISING ⟲ tối đa max_revision_rounds) → QUALITY_REVIEW → NEEDS_HUMAN_REVIEW
        prepare → write (từng section) → assemble → claims → factcheck → revise → quality → finalize
  → Editor (người sửa) → RecheckWorkflow khi section đã đổi
  → Duyệt (ApprovalGate) → APPROVED
```

Trạng thái và chuyển trạng thái hợp lệ: `Support/ArticleStateMachine.php`. `FAILED` lưu `status_before_failure` để `workflows/resume` tiếp tục đúng bước. `CANCELLED` là trạng thái cuối.

## 2. Task AI (khóa route trong AI Gateway)

| Task key | Bước | Ghi chú |
|---|---|---|
| `writer.research_questions` | Câu hỏi nghiên cứu | |
| `writer.source_discovery` | Gợi ý nguồn | Chỉ là *gợi ý URL*; hệ thống tự tải, model không bao giờ được coi là đã truy cập trang |
| `writer.source_analysis` | Trích bằng chứng từ nguồn đã tải | Chỉ nhận nội dung trong `<data>`; đoạn trích phải khớp nguyên văn (`Support/Verbatim`) |
| `writer.brief` | Content brief | |
| `writer.outline` | Dàn ý | `required_facts` phải trỏ `evidence_refs` có trong hồ sơ |
| `content.draft` | Viết từng section | |
| `writer.claims` | Tách khẳng định | Kết hợp `RuleClaims` (risk floor theo loại) |
| `verification.claims` | Kiểm chứng | Model đề xuất, `VerdictRules` quyết định |
| `writer.rewrite` | Viết lại theo yêu cầu / sửa tự động | Tạo *đề xuất* (revision), người dùng áp dụng hoặc bỏ |
| `writer.seo_review`, `writer.final_review` | Rà soát | |

Mọi câu trả lời được kiểm theo JSON schema (`Ai/WriterAi.php`); nội dung tải về luôn bọc trong `<data>` và được quét prompt injection (`Retrieval/InjectionScanner`).

## 3. Nghiên cứu dựa trên bằng chứng

- Nguồn: URL người dùng cung cấp, website khách hàng (site đã kết nối), gợi ý của AI. Tất cả đều do **hệ thống** tải qua `Retrieval/SourceRetriever` (dùng `PublicWebFetcher` của Phase 4).
- An toàn khi tải: SSRF guard cho mọi URL và mọi bước redirect + ghim IP, chặn mạng nội bộ, tôn trọng robots.txt, giới hạn tốc độ theo host, timeout, giới hạn dung lượng, cache `source_cache_hours`.
- Captcha / paywall / trang đăng nhập được ghi nhận là `blocked_*`, **không vượt qua**.
- Độ tin cậy nguồn tính theo quy tắc (`SourceClassifier`): trang chính thức, tài liệu nhà sản xuất, báo chí, diễn đàn…; không dùng ý kiến model.
- Số nguồn tối đa: `max_sources_basic|standard|deep` (5/10/20). Không có nguồn thật → brief ghi `missing_information`, không bịa.
- Không bịa URL, tác giả, ngày xuất bản, trích dẫn: citation trong bài chỉ được chèn từ `evidence_items` đã lưu (HTML sanitizer loại bỏ citation không khớp bằng chứng).

## 4. Viết, kiểm chứng, sửa

- Viết từng section theo dàn ý đã duyệt; mỗi section có `section_key` để theo dõi thay đổi.
- Claim extraction → risk level (xem `FACT-CHECK-POLICY.md`).
- Consistency checker: mâu thuẫn số liệu/tên giữa các section.
- Sửa tự động có giới hạn: tối đa `max_revision_rounds` (mặc định 2) vòng, mỗi vòng lưu diff; hết vòng → `NEEDS_HUMAN_REVIEW`.
- Quality rubric: `CONTENT-QUALITY-RUBRIC.md`. Duplicate/cannibalization: so với bài trong workspace và ý tưởng Phase 4.

## 5. Biên tập và phiên bản

- `PUT autosave` (giới hạn 30/phút), `POST versions` (lưu phiên bản), diff, xem, khôi phục.
- `VersionManager` so sánh section: section đổi → claim của section đó quay về `PENDING`, cần kiểm chứng lại; mọi phê duyệt trước đó bị **vô hiệu** (`editorial_approvals.invalidated_at`) và bài `APPROVED` trở về `NEEDS_HUMAN_REVIEW`.
- HTML luôn qua `Support/HtmlSanitizer` (backend) và `sanitizeArticleHtml` (frontend, allowlist giống backend). Chỉ component `SafeHtml` được dùng `v-html`.

## 6. Bulk, chi phí, quota

- Lô viết bài: tối đa `max_batch_size` (20) bài, chạy song song `max_batch_concurrency` (3); pause/resume/cancel/retry.
- Mỗi bài có ngân sách (`budget_limit_micro_usd`, mặc định 1 USD, tối đa 10 USD). Hết ngân sách → workflow dừng với `BudgetBlocked`, người dùng có thể tăng ngân sách rồi resume.
- Quota tháng `max_articles_per_month` và `max_concurrent_workflows` theo workspace (`writer_usage_counters`).
- Chi phí từng lời gọi lưu ở `article_ai_usage` (từ ledger của AI Gateway).
- Lệnh định kỳ: `sitex:writer:sync` (đồng bộ trạng thái workflow → bài viết), `sitex:writer:autonomous` (tự tạo bài từ lịch — **tắt mặc định**, `autonomous_enabled=false`).
- Phase 5 **không** đăng lên WordPress.
