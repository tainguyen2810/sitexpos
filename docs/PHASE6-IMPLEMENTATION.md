# Phase 6 — AI Image Engine, SEO Audit & WordPress Publisher: báo cáo triển khai

Nhánh: `claude/project-thread-i3nn1q` (tách từ `feature/phase5-ai-writer`). Trạng thái: **đã triển khai, chờ người dùng nghiệm thu**. Không triển khai Phase 7/8/9, không merge, không deploy.

Tài liệu chi tiết: `MEDIA-ENGINE.md`, `SEO-AUDIT-RULES.md`, `WORDPRESS-PUBLISHER.md`, `PUBLISHING-RECOVERY.md`, kết quả kiểm thử `PHASE6-TEST-REPORT.md`. Đã cập nhật `ARCHITECTURE.md` (§5, §8d, ADR-018/019), `DATABASE.md` (§3.11–3.12), `API-CONTRACTS.md` (§2.6, admin, plugin), `ROADMAP.md`, `CLAUDE.md`.

## 1. Kiểm tra trước khi code

Đã xác nhận Phase 1–5 có mặt và chạy được trên nhánh gốc: tenancy (`BelongsToWorkspace`, `TenantContext`), connector WordPress có ký HMAC (Phase 2), AI Gateway với registry capability (`image_generation`) và adapter ảnh OpenAI (Phase 3), danh mục nội dung/cluster (Phase 4), Writer với bài `APPROVED` + `approved_version_id` + thống kê claim (Phase 5). Phát hiện P0 từ Phase 2: **cập nhật bài có thể ghi đè nội dung sửa tay trên WordPress** → đã sửa trong Phase 6 (ADR-019) trước khi bật chế độ cập nhật.

## 2. Đã xong

| Hạng mục | Nội dung |
|---|---|
| Media | Nguồn WordPress / WooCommerce (tham chiếu) / upload; ảnh AI qua AI Gateway (`media.image`, chỉ model `image_generation`, xem trước prompt, ước tính chi phí, trần ngân sách, `call_key` chống trả tiền hai lần, nhãn AI); xử lý ảnh GD (MIME thật, chặn polyglot/file thực thi ngụy trang, re-encode bỏ EXIF, WebP/AVIF/thumbnail, sha256 + dHash); infographic xác định từ dữ liệu có nguồn; placement engine; alt text theo quy tắc; URL ký có hạn; giới hạn workspace/nền tảng |
| SEO | Metadata xác định (giữ trường sửa tay, optimistic lock); canonical khác domain / noindex cần chính sách website; audit 19 quy tắc PASS/WARNING/ERROR/BLOCKED có giải thích, điểm checklist; kiểm tra link ngoài trong queue qua SsrfGuard; schema tắt mặc định, không trùng Yoast/Rank Math, chỉ cho nội dung đủ điều kiện; link nội bộ chỉ tới URL thật |
| Publishing | State machine 9 trạng thái; 8 cổng chạy lúc tạo / duyệt / ngay trước gửi; mode A–E (mặc định bản nháp WP); một job hoạt động / bài / site; lịch theo múi giờ site + khung giờ + hạn mức; lỡ lịch không tự đăng muộn; claim nguyên tử; mapping + khóa idempotency; đối soát sau timeout; retry backoff có giới hạn; xung đột hai lớp + 5 cách giải quyết; preview mô phỏng; bulk có tạm dừng/tiếp tục/hủy; auto-publish chỉ khi bật có xác nhận + nền tảng cho phép |
| Plugin 1.1.0 | `GET /posts/{id}/state`, `GET /posts/lookup`, `GET /media`; kiểm tra xung đột nguyên tử (409 `sitex_conflict`), `_sitex_post_written_hash`, `expected`/`force`; schema JSON-LD; featured image từ Media Library; hash SEO cho Yoast/Rank Math/tích hợp; dịch vi đầy đủ (126/126); vẫn PHP 7.4 |
| API | Media 18 route, SEO 14 route, Publishing 26 route (tenant); 6 route Super Admin; 1 route file ký |
| Migration | 3 file / 20 bảng (6 media, 5 seo, 9 publishing) — xem `DATABASE.md` |
| UI khách hàng (12 trang) | Media Studio, Tạo ảnh AI, Thư viện media, Ảnh trong bài; SEO Audit, Metadata SEO, Liên kết nội bộ; Trung tâm đăng bài, Lịch đăng, Lịch sử (job/lô/mapping), Xung đột, Cài đặt đăng bài |
| UI Super Admin (2 trang) | Media Admin (tổng quan, chi phí, giới hạn), Publishing Admin (job, lỗi, xung đột, connector, giới hạn, kill switch) |
| Dữ liệu demo | `Phase6DemoSeeder` (chỉ development): 30 media (10 ảnh AI có nhãn + job), 10 SEO audit chạy bằng engine thật, 20 job (5 lên lịch, 6 đã đăng, 3 chờ cập nhật có 3 xung đột mở, 2 lỗi, 2 sẵn sàng, 1 nháp, 1 đã hủy) |
| Chất lượng | Larastan sạch toàn app (sửa 185 lỗi kiểu có sẵn ở Research/Writer bằng docblock và sửa kiểu, không đổi hành vi) |

## 3. Chưa xong / ngoài phạm vi

- **Chưa thử với nhà cung cấp ảnh thật** (không có API key): ảnh AI được kiểm bằng `FakeImageProvider` (trả PNG thật). Adapter OpenAI images có từ Phase 3 nhưng chưa gọi API thật.
- **Chưa chạy E2E với WordPress thật** cho Phase 6: môi trường này không tải được WordPress (proxy chặn wordpress.org). Script E2E Phase 2 (`wordpress-plugin/tests/e2e`) chưa được mở rộng cho kịch bản xung đột / state / lookup. Plugin chỉ được kiểm `php -l`, tương thích PHP 7.4, test ký, bản dịch.
- **Chưa thử Yoast SEO / Rank Math thật**: adapter (ghi meta, `state_hash`) chỉ được kiểm tĩnh; phía Cloud dùng `FakeWordPress`.
- Nguồn ảnh stock (`stock`) chỉ giữ chỗ, chưa tích hợp nhà cung cấp.
- Job kiểm tra link ngoài `CheckArticleLinks` chưa có test riêng (dùng lại `PublicWebFetcher` đã test ở Phase 4).
- Trang Super Admin chưa được mở trên trình duyệt (cần 2FA); đã có test API phân quyền + audit.

## 4. Rủi ro

- Bài đăng bằng plugin < 1.1.0 không có `written_hash`: phát hiện sửa tay dựa vào thời điểm sửa (kém chính xác hơn). Cần cập nhật plugin trên các site trước khi bật chế độ cập nhật.
- AVIF phụ thuộc bản GD trên server; không có thì bỏ qua biến thể AVIF.
- Ràng buộc "một job hoạt động / bài / site" dựa vào UNIQUE `active_key`; test đồng thời đã chạy trên MySQL 8.0 nhưng chưa đo dưới tải thật.
- Ghi giới hạn ở trang Super Admin có audit nhưng không bắt nhập `reason` (giống trang cài đặt Phase 3–5), lệch quy ước "mọi thao tác ghi admin cần reason" trong `API-CONTRACTS.md` §3.
- Scheduler phải chạy (`schedule:work`/cron) và có worker queue `media`, `default`; nếu không, lịch sẽ bị đánh dấu lỡ sau `missed_grace_minutes`.

## 5. Ranh giới module & thay đổi ngoài module Phase 6

Không viết lại module đang chạy. Thay đổi ngoài `Media/Seo/Publishing` chỉ là bổ sung:
- `Sites`: contract `SiteDirectory`/`WordPressGateway` + `FetchPostState`, `ListRemoteMedia`, `PublishPost` nhận `expected`/`force`/`schema`.
- `Writer`: contract `ArticleCatalog` (+ query), `WriterAi` docblock; `Research`: `PlannerDirectory` thêm hàm đọc.
- **AI Gateway: không sửa file nào**; Media chỉ đọc `AiTaskRoute`, `AiModel`, `CostCalculator` và gọi gateway như mọi module.
- Docblock model + sửa kiểu Larastan ở Research/Writer (không đổi hành vi, toàn bộ test cũ vẫn đạt).

## 6. Sẵn sàng cho Phase 7

Sẵn sàng về mặt code: mọi kiểm tra tự động đạt (xem `PHASE6-TEST-REPORT.md`). Trước khi dùng thật nên: (1) chạy E2E với WordPress 6.4 + bản mới nhất, có Yoast và Rank Math thật; (2) thử một model tạo ảnh thật với ngân sách nhỏ; (3) cập nhật plugin 1.1.0 lên các site đã ghép nối. Nội dung Phase 7 do chủ dự án xác định.
