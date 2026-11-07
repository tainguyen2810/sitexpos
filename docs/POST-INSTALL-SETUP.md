# Thiết lập sau cài đặt (Setup Wizard) — Phase 9

Trang quản trị `https://admin.<domain>/setup` (quyền `platform.system.manage`, chỉ Super Admin). Có thể mở lại bất cứ lúc nào; khi chưa hoàn tất, mọi trang admin hiện dải nhắc "Hệ thống chưa hoàn tất thiết lập ban đầu".

Trạng thái mỗi bước do **máy chủ tính từ cấu hình thật** (không tick tay): `NOT_STARTED`, `IN_PROGRESS`, `COMPLETE`, `WARNING`, `BLOCKED`.

## 12 bước

| # | Bước | Bắt buộc | COMPLETE khi | Làm ở đâu |
|---|---|---|---|---|
| 1 | Thông tin chung | Có | Có tên thương hiệu và email hỗ trợ | Ngay trong bước (logo PNG/JPG/WebP ≤ 512 KB, không nhận SVG) |
| 2 | Nhà cung cấp AI | Có | Ít nhất 1 provider bật, có khóa, circuit không mở | Nhà cung cấp & mô hình (khóa lưu mã hóa, không hiển thị lại); Cấu hình AI để bật bên đang dùng |
| 3 | Model AI cho từng tác vụ | Có (cho phép cảnh báo) | Mọi tác vụ có route bật và model sẵn sàng: gợi ý chủ đề, phân tích nguồn, dàn ý, viết bài, kiểm chứng, rà soát SEO, ảnh, SEO Agent, SEO Copilot. `BLOCKED` khi bước 2 chưa xong | Cấu hình AI |
| 4 | Email (SMTP) | Có | Đã gửi email thử thành công | Ngay trong bước. Chọn "theo .env" hoặc nhập SMTP; mật khẩu chỉ ghi, không đọc lại. Gửi thử chạy trong hàng đợi (202) |
| 5 | Gói dịch vụ | Có | Có ít nhất 1 gói `active` đã phát hành | Nút "Tạo bản nháp gói chuẩn" tạo Free/Starter/Pro/Business **không có giá**; nhập giá ở Gói & thanh toán |
| 6 | Thanh toán | Có | Có provider bật, đủ cấu hình, VietQR tự kiểm tra đạt | Gói & thanh toán → Cài đặt. QR chỉ là hướng dẫn chuyển khoản, không phải bằng chứng thanh toán |
| 7 | AI credits | Có | Có chính sách credits và tỷ giá USD | Gói & thanh toán |
| 8 | Google OAuth | Không | Client ID/Secret hợp lệ (kiểm tra gửi mã giả tới Google, kết quả `invalid_grant` = hợp lệ; không cấp quyền gì) | Ngay trong bước; Redirect URI hiển thị để khai báo ở Google Cloud |
| 9 | Plugin WordPress | Không | Có gói plugin đã build và đã xác nhận nắm hướng dẫn | Nút tải plugin (chỉ phục vụ file `sitex-post-X.Y.Z.zip` trong thư mục cấu hình) |
| 10 | Hàng đợi & worker | Có (cho phép cảnh báo) | Redis, Horizon, Scheduler, Queue đều ổn | Supervisor + Cron trong aaPanel |
| 11 | Kiểm tra bảo mật | Có (cho phép cảnh báo) | Đạt mọi mục; `BLOCKED` nếu `APP_DEBUG` bật, `APP_KEY` sai hoặc installer chưa khóa | `.env`, aaPanel |
| 12 | Hoàn tất | — | `READY` hoặc `READY_WITH_WARNINGS` | Nút "Xác nhận hoàn tất" (bị khóa khi còn bước bắt buộc chưa xong; API trả 422 `setup_not_ready`) |

Mục kiểm tra bảo mật: `APP_ENV=production`, `APP_DEBUG` tắt, `APP_KEY` hợp lệ, HTTPS, cookie Secure, cookie HttpOnly, installer đã khóa, `.env` không cho người khác đọc, scheduler chạy, sao lưu tự động có mã hóa, mọi Super Admin đã bật 2FA.

## Quy tắc

- Secret (mật khẩu SMTP, Google client secret, Telegram bot token) lưu trong `system_settings` với `is_secret=1`, mã hóa bằng `Crypt` (APP_KEY). API chỉ trả `<field>_configured: true/false`. Nhật ký audit chỉ ghi tên trường đã đổi.
- Giá trị trong trang quản trị ghi đè `.env` lúc chạy (`RuntimeConfig`), xóa thì quay về `.env`.
- Mọi thay đổi ghi `Audit::record('system.settings_updated', …)`; hoàn tất ghi `system.setup_completed`.
- Không có nút nào chỉ để trang trí: các bước không thể làm tại chỗ (AI, thanh toán) có liên kết tới trang cấu hình thật.

## API

`GET /api/admin/v1/system/setup`, `PUT …/settings/{general|mail|google|alerts|backup}`, `POST …/settings/logo`, `POST …/settings/{mail|google|alerts}/test` (202, giới hạn 6/phút), `POST …/setup/plans/draft`, `POST …/setup/acknowledge`, `POST …/setup/complete`, `GET …/plugin/download`. Chi tiết: [API-CONTRACTS.md](API-CONTRACTS.md) §Phase 9.
