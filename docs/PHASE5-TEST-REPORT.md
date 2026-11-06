# Phase 5 — Test Report

Ngày chạy: 05/11/2026, nhánh `feature/phase5-ai-writer`, môi trường sandbox (Linux, PHP 8.3, Node, SQLite cho test). Mọi kết quả dưới đây là từ lần chạy thật; mục nào không chạy được ghi rõ lý do.

## 1. Kết quả các bước của `scripts/check.sh`

| Bước | Kết quả |
|---|---|
| Backend Pint | ✅ passed |
| Backend Larastan (phpstan) | ⚠️ **không chạy được**: `vendor/bin/phpstan` không có trong sandbox (Larastan chưa cài vào vendor ở đây). Cần chạy lại trên máy dev/CI |
| Backend Pest | ✅ 341 test: 340 passed, 1 skipped, 2272 assertions (sau khi thêm tự duyệt) |
| Frontend lint (eslint) | ✅ |
| Frontend typecheck (vue-tsc) | ✅ |
| Frontend unit test (vitest) | ✅ 8 file, 61 test passed |
| Frontend build (customer + admin) | ✅ |
| `check:no-demo` | ✅ không có code demo trong `dist/` |
| WordPress plugin build | ✅ (không đổi ở Phase 5) |

Test bị skip: `AiGateway/BudgetTest` (race ngân sách) cần MySQL + pcntl, có từ Phase 3, không thuộc Writer.

## 2. Test Phase 5

### Backend (37 test, 402 assertions — `tests/Feature/Writer`, `tests/Unit/Writer`)
| File | Phạm vi |
|---|---|
| `WriterPipelineTest` (1) | Viết trọn một bài có bằng chứng ở chế độ `final_only` với `FakeProvider` + `Http::fake` |
| `WriterReviewTest` (7) | Tự duyệt: bị từ chối khi chính sách tắt, bài thủ công không bao giờ tự duyệt, đủ điều kiện thì hệ thống duyệt (user null), sửa bài làm mất duyệt, điểm dưới ngưỡng thì chờ người duyệt kèm lý do; Dừng chờ duyệt brief/dàn ý (`step_by_step`) và sửa dàn ý giữ dữ liệu; chỉ `content.publish` được duyệt, sửa làm mất duyệt; CRITICAL chặn duyệt, xác minh thủ công cần người duyệt + bằng chứng; AI rewrite chỉ là đề xuất; sanitize HTML + autosave khóa lạc quan; lịch sử có bộ lọc |
| `WriterSafetyTest` (10) | Đoạn trích không nguyên văn bị loại; không verify nhầm model code khác; sửa tự động trong giới hạn vòng; prompt injection; citation/link giả bị loại; robots.txt + captcha; AI kiểm chứng lỗi → claim giữ PENDING; dừng ở ngân sách và resume; quota tháng; id con bị giới hạn theo bài và tenant |
| `WriterBatchAdminTest` (3) | Lô viết bài dừng ở bước duyệt; hủy lô hoàn quota; Super Admin API |
| `WriterDemoSeederTest` (2) | Dữ liệu DEMO có nhãn, không verdict/nguồn giả; chặn ở production |
| `Unit/WriterRulesTest` (14) | State machine, `VerdictRules` (không bằng chứng → không VERIFIED, không tìm thấy ≠ sai, lỗi thời), mã model dễ nhầm, risk floor, nguyên văn, sanitizer + citation, tách section, diff, nhất quán, phân loại nguồn, chống injection, trùng lặp, nhồi từ khóa |

Test cách ly tenant chung (`CrossTenantIsolationTest`) tự quét mọi route `writer.*` mới.

### Frontend (18 test trong `tests/writer.spec.ts`)
Helper (tone, verdict, tiền tệ, `nextTab`), sanitizer chống XSS (script, `on*`, `javascript:` URL, class lạ), đủ cặp khóa vi/en và mọi khóa `writer.*` dùng trong code đều tồn tại, FactCheckTab (claim CRITICAL chặn duyệt, nút theo quyền), EditorTab (sanitize khi dán, khóa khi workflow đang chạy), DiffView (render text, không HTML).

## 3. Chạy thử giao diện với backend thật
`php artisan serve` + Vite, SQLite nạp `DemoSeeder` (gồm `WriterDemoSeeder`), Playwright đăng nhập tài khoản owner demo. Đã mở được mọi trang khách hàng (Library, Wizard, Bulk, Presets, History) và mọi tab bài viết (Overview, Research, Brief, Outline, Editor, Fact-check, Quality, Versions), trang Super Admin Writer, ở desktop và mobile. Lỗi console duy nhất: 401 của lần kiểm tra phiên `/me` trước khi đăng nhập (đúng thiết kế).

Lỗi tìm thấy và đã sửa trong lúc chạy thử: sửa dàn ý làm mất `required_facts`/`word_range` (validation thiếu luật), heading rỗng của phần mở bài trả 422, client gọi `presets` như danh sách phân trang.

## 4. Những gì **chưa** được kiểm
- **Không gọi provider AI thật** (không có API key trong sandbox). Chi phí AI trong quá trình test: **0 USD**. Chưa xác nhận chất lượng tiếng Việt của model thật, cũng chưa xác nhận provider nào hỗ trợ đúng structured output cho từng task writer.
- **Không tải nguồn web thật**: mọi nguồn trong test là `Http::fake`; dữ liệu DEMO là trang minh họa. Chưa xác nhận nguồn thực tế nào truy cập được qua mạng production.
- Chưa chạy trên MySQL 8 (test dùng SQLite). Migration dùng kiểu cột chuẩn, cần chạy `migrate` trên staging.
- Chưa có load test cho bulk/autosave.
- SSRF của bộ tải nguồn dùng lại `PublicFetcher` Phase 4 (đã có test ở Phase 4); Phase 5 không thêm test SSRF riêng.
- Larastan (xem §1).
