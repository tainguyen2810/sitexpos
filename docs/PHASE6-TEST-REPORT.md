# Phase 6 — Báo cáo kiểm thử

Ngày chạy: 05/11/2026, nhánh `claude/project-thread-i3nn1q`. Môi trường: Linux, PHP 8.3.6, Node 22, Chromium (Playwright). Mọi số liệu dưới là **kết quả thật đã chạy**; mục nào chưa chạy được ghi rõ.

## 1. `scripts/check.sh` (toàn bộ, lần chạy cuối)

```
==> Backend: Pint
{"tool":"pint","result":"passed"}
==> Backend: Larastan
 [OK] No errors
==> Backend: Pest
  Tests:    1 skipped, 375 passed (2629 assertions)
==> Frontend: lint
==> Frontend: typecheck
==> Frontend: unit tests
 Test Files  9 passed (9)
      Tests  77 passed (77)
==> Frontend: build
==> Frontend: no demo code in production build
==> WordPress plugin: lint, PHP 7.4 check, signer test, translations, zip
23 files checked, 0 problems
126/126 translated
== built dist/sitex-post-1.1.0.zip (60K)
All checks passed.
real	1m32.303s
```

Test bị skip: `never oversells a budget under concurrent reservations (MySQL, …)` của Phase 3 — chỉ chạy khi có MySQL; không liên quan Phase 6. Toàn bộ test Phase 1–5 vẫn đạt (không xung đột giữa các phase).

## 1b. MySQL (phát hiện qua CI GitHub)

CI chạy Pest trên MySQL 8.4 và phát hiện lỗi mà SQLite không có (đều tồn tại từ trước, nay đã sửa trong PR):
- Thiếu `APP_KEY` khi không có `.env` → thêm khóa chỉ dùng cho test vào `phpunit.xml`.
- Tên index tự sinh dài 67 ký tự (> 64, Phase 4 `content_calendar_items`) → đặt tên ngắn.
- Khóa ngoại tổng hợp `(workspace_id, x)` với `ON DELETE SET NULL` (Phase 5 `articles.writing_batch_id`, Phase 6 `image_placements.media_asset_id`, `publishing_jobs.publishing_batch_id`): MySQL từ chối vì `workspace_id` NOT NULL → đổi thành khóa ngoại một cột.

Kết quả chạy lại với MySQL 8.0.46 cài tại máy: `migrate:fresh`, `migrate:rollback`, `migrate` đều đạt; **Pest: 376 passed (2632 assertions)** — gồm cả test đồng thời chỉ chạy trên MySQL.

## 2. Test backend Phase 6 (`backend/tests/Feature/Phase6`, 32 test)

**MediaTest (5)**: tải lên → kiểm tra → tối ưu trong queue (WebP + thumbnail); từ chối file thực thi ngụy trang, polyglot, sai phần mở rộng; tạo ảnh AI qua AI Gateway, gắn nhãn và không bao giờ làm ảnh sản phẩm; infographic chỉ từ dữ liệu có nguồn / khách cung cấp, kết quả xác định; file chỉ phục vụ qua URL ký có hạn.

**SeoTest (6)**: audit có giải thích trên bản render sẽ đăng, điểm checklist; từ chối canonical/noindex nguy hiểm khi chưa có chính sách; BLOCKED canonical khác domain kể cả khi lưu trước khi đổi chính sách; schema chỉ khi bật, không trùng plugin SEO, chỉ nội dung đủ điều kiện; link nội bộ chỉ tới URL thật đã đăng, anchor có sẵn trong văn bản; metadata trong giới hạn snippet, slug ASCII.

**PublishingTest (16)**: lưu bản nháp WP qua mọi cổng kèm SEO + schema; từ chối bài chưa duyệt / còn claim (không bypass); chỉ vai trò `content.publish` được duyệt; một job hoạt động / bài / site; **timeout lần ghi đầu không tạo bài trùng (đối soát trước)**; retry backoff khi site offline, FAILED sau max attempts, không lặp; claim nguyên tử (worker thứ hai không làm gì); **phát hiện sửa tay trên WP, không ghi đè, chỉ giải quyết bằng quyết định của người**; giữ bản WP / mở bản nháp mới; `post_missing` khi bài bị xóa; lịch theo múi giờ site + khung giờ, đăng khi đến hạn; lỡ lịch được đánh dấu, không đăng muộn; từ chối chuyển trạng thái sai; bật auto-publish cần xác nhận + audit; bulk với trạng thái từng bài, lỗi một phần, tạm dừng; preview gắn nhãn mô phỏng + snippet SEO.

**Phase6AdminTest (2)**: trang admin ẩn với khách (404), phân quyền SUPPORT / BILLING_ADMIN; Super Admin đổi giới hạn có audit, kill switch `publishing_enabled=false` thực sự chặn đăng ở cổng `policy`.

**Phase6DemoSeederTest (2)**: dữ liệu demo đủ số lượng, có nhãn; từ chối chạy ở production.

**Ngoài ra**: `CrossTenantIsolationTest` tự liệt kê mọi route tenant (gồm 58 route Phase 6) và kiểm 404 chéo tenant; `ArchitectureTest` kiểm ranh giới module (Phase 6 chỉ dùng Contracts, Seo không gọi WordPress / Publishing, model tenant dùng `BelongsToWorkspace`, request kế thừa `ApiRequest`).

## 3. Test frontend Phase 6 (`frontend/tests/phase6.spec.ts`, 16 test)

Quy tắc media phía UI khớp API (ảnh AI/infographic không vào ô sản phẩm, license unknown, alt bắt buộc); sắp xếp/màu mức độ SEO; không cho "ghi đè" khi bài mất / đổi domain; khóa idempotency hợp lệ; scope admin; client gửi multipart không tự đặt Content-Type; iframe preview sandbox + DOMPurify; i18n: mọi khóa tĩnh `p6.*`, mọi tab điều hướng, mọi giá trị enum API trả về (trạng thái, mode, cổng, loại xung đột, resolution, sync state, nguồn/license/slot/trạng thái media, mức độ/nhóm SEO) đều có nhãn.

## 4. Chạy thử app thật trên trình duyệt

Backend `php -S` + SQLite, `migrate:fresh --seed --seeder=DemoSeeder` (toàn bộ demo Phase 1–6), Vite dev, Chromium (Playwright), đăng nhập `owner@demo.sitexpost.test`, mở lần lượt 12 trang khách hàng Phase 6.

- Lần 1 phát hiện **2 lỗi giao diện** (test tự động không bắt được): (1) 5 trang Đăng WordPress hiện khóa thô `p6.publishing.nav.*` trên thanh tab (namespace dịch là `p6.pub`); (2) mở SEO Audit bằng link tới bài chưa nằm trong danh sách làm ô chọn bài trống. Đã sửa (`sectionNav.ts`, `ArticlePicker.vue`) và thêm test i18n cho tab + giá trị enum.
- Lần cuối: 12/12 trang hiển thị đúng, không lỗi console, không API lỗi (ngoài 401 `/me` mong đợi ở trang đăng nhập).
- Trang Super Admin không mở trên trình duyệt (cần thiết lập 2FA); đã kiểm bằng test API.

## 5. Phần được giả lập (mock)

| Thành phần | Cách kiểm | Ghi chú |
|---|---|---|
| Nhà cung cấp ảnh AI | `tests/Support/FakeImageProvider` (PNG thật) qua AI Gateway thật | **Chưa gọi model ảnh thật** (không có API key) |
| WordPress | `FakeWordPress` cài đặt `WordPressGateway` (state, lookup, timeout, 409, xóa bài) | **Chưa chạy E2E WordPress thật** cho Phase 6 |
| Link ngoài | Quy tắc `broken_links` kiểm với trạng thái HTTP truyền vào | **Job `CheckArticleLinks` (gọi mạng qua `PublicWebFetcher` + SsrfGuard) chưa có test riêng** |
| Yoast SEO / Rank Math | Cờ `seo_adapter` trong hồ sơ site | Adapter plugin chỉ kiểm tĩnh (lint, PHP 7.4) |

## 6. Không chạy được trong môi trường này

- E2E với WordPress thật (6.4 / mới nhất): proxy chặn tải WordPress (`wordpress.org` → 403). Lệnh chạy khi có môi trường: `wordpress-plugin/tests/e2e/README.md` (cần bổ sung kịch bản xung đột Phase 6).
- Test trên đúng MySQL 8.4 chạy ở CI GitHub (máy này dùng MySQL 8.0.46).
