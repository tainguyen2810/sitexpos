# Trình cài đặt web (`/install`) — Phase 9

Trình cài đặt giúp đưa SiteX Post lên máy chủ mới mà không cần sửa `.env` bằng tay. Nó chạy được khi **chưa có `.env`, chưa có `APP_KEY` và chưa có database**. Bảo mật của trình cài đặt nằm ở [INSTALLER-SECURITY.md](INSTALLER-SECURITY.md). Các bước cấu hình sau khi cài nằm ở [POST-INSTALL-SETUP.md](POST-INSTALL-SETUP.md).

## 1. Điều kiện trước khi mở `/install`

| Hạng mục | Yêu cầu | Trong aaPanel |
|---|---|---|
| Mã nguồn | Đã giải nén bản phát hành hoặc `git clone`, có `backend/vendor` | Files |
| Domain API | Trỏ **document root** vào `backend/public` | Website → Site directory |
| PHP | ≥ 8.3 với `pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, fileinfo, curl, gd, intl`; nên có `redis, pcntl, posix` | App Store → PHP → Install extensions |
| Hàm PHP | `proc_open`, `pcntl_*` không bị vô hiệu hóa (Horizon cần) | PHP → Disabled functions |
| MySQL | **8.0 trở lên, không phải MariaDB**. Database trống, user có quyền `CREATE` | Databases → Add |
| Redis | Khuyến nghị (queue, cache, Horizon) | App Store → Redis |
| Quyền ghi | `backend/storage`, `backend/bootstrap/cache`, file `backend/.env` (hoặc thư mục `backend/`) | Files → Permission (user `www`) |

## 2. Lấy mã cài đặt (setup token)

Lần đầu mở `https://api.<domain>/install`, máy chủ tự sinh một mã và ghi vào `backend/storage/app/installer/setup-token.txt` (quyền 0600, nằm ngoài thư mục web). Chỉ người có quyền truy cập file máy chủ mới đọc được mã này, nên người lạ truy cập domain trước bạn **không** thể chiếm quyền Super Admin.

- aaPanel → Files → mở file trên, sao chép mã.
- Hoặc SSH: `php artisan sitex:install:token` (in mã mới, mã cũ mất hiệu lực). Thêm `--file` để ghi vào file.
- Mã hết hạn sau 24 giờ (`SITEX_INSTALLER_TOKEN_TTL_HOURS`). Máy chủ chỉ lưu SHA-256 của mã.

## 3. Các bước

| # | Bước | Trình cài đặt làm gì | Ghi gì |
|---|---|---|---|
| 1 | Chào mừng | Nhận mã cài đặt (chỉ giữ trong bộ nhớ trình duyệt) | — |
| 2 | Kiểm tra môi trường | PHP, extension, hàm bị chặn, `memory_limit`, Composer, quyền ghi, `.env`, cache cấu hình, dung lượng đĩa, HTTPS, định tuyến Nginx, document root. Mỗi mục `PASS / WARNING / FAIL` kèm cách sửa trong aaPanel | — |
| 3 | Cơ sở dữ liệu | Kết nối thử, kiểm tra phiên bản (MySQL ≥ 8, không MariaDB), số bảng, quyền `CREATE`. **Từ chối database đã có bảng** (`database_not_empty`) | `storage/app/installer/pending.json` (mật khẩu mã hóa bằng khóa tạm `pending.key`) |
| 4 | Cấu hình ứng dụng | Tên, URL API/app/admin, ngôn ngữ, múi giờ, email hệ thống (log hoặc SMTP). Cảnh báo nếu URL không phải HTTPS | như trên |
| 5 | Redis & hàng đợi | Thử kết nối Redis (`AUTH/PING/INFO`), chọn Redis + Horizon hoặc database queue. Tải mẫu Supervisor, Cron, Nginx đã điền sẵn đường dẫn | như trên |
| 6 | Super Admin | Họ tên, email, mật khẩu ≥ 12 ký tự có chữ hoa, chữ thường, số, ký hiệu, không chứa phần tên của email. Chỉ lưu **bcrypt hash** | như trên |
| 7 | Cài đặt | Chạy tuần tự, mỗi bước idempotent và ghi nhật ký | xem §4 |

## 4. Bước "Cài đặt" chạy những gì

1. `revalidate` — kiểm tra lại toàn bộ cấu hình đã lưu.
2. `verify_database` — kết nối lại, so **dấu vân tay** database (host/port/tên/user). Nếu database đã có bảng mà không phải do lần chạy trước của chính trình cài đặt này → dừng, không ghi đè.
3. `write_env` — ghi `.env` (gộp với file cũ nếu có, sao lưu bản cũ vào `storage/app/installer/env-backup-*.env`, quyền 0640). **Không bao giờ thay `APP_KEY` đã có**; chỉ sinh `APP_KEY` khi chưa có.
4. `migrate` — `migrate --force` trên database trống đã xác nhận.
5. `seed` — dữ liệu hệ thống mặc định (`DatabaseSeeder`: AI registry, không có dữ liệu demo).
6. `create_admin` — tạo Super Admin từ hash đã lưu; email được đánh dấu đã xác minh. 2FA bắt buộc ở lần đăng nhập đầu.
7. `verify` — kiểm tra bảng, Super Admin, `APP_KEY`.
8. `mark_installed` — ghi `system.installed_at`, `system.version`, `SITEX_INSTALLED=true`, file khóa `installed.json`; xóa cấu hình tạm và mã cài đặt.

Nếu một bước lỗi: giao diện hiện bước lỗi và nút "Xem chẩn đoán" (nhật ký đã che mật khẩu). Sửa nguyên nhân rồi bấm "Thử lại cài đặt" — các bước đã xong sẽ được bỏ qua nhờ tính idempotent; chạy lại chỉ được phép khi database vẫn là database đã xác nhận ở bước 2.

## 5. Sau khi cài

- `/install` trả **403 "đã cài đặt"**. Khóa dựa trên **nhiều tín hiệu**, xóa một tín hiệu không mở lại được trình cài đặt: file `installed.json`, biến `SITEX_INSTALLED`, `system.installed_at` trong database, hoặc đã có tài khoản `SUPER_ADMIN`.
- Muốn tắt hẳn trình cài đặt: `SITEX_INSTALLER_ENABLED=false` (trả 404).
- Cấu hình Supervisor (Horizon) và Cron bằng các mẫu đã tải, build frontend, đăng nhập admin, bật 2FA, rồi làm [Setup Wizard](POST-INSTALL-SETUP.md).

## 6. Lệnh hỗ trợ

```bash
php artisan sitex:install:status     # trạng thái cài đặt + kiểm tra môi trường
php artisan sitex:install:token      # mã cài đặt mới
```

## 7. Đã kiểm chứng

- 18 test Pest (`tests/Feature/Installer/InstallerTest.php`): quyền file, xác thực mã, giới hạn tần suất, hết hạn, tắt trình cài đặt, từ chối DB có dữ liệu, không lưu secret dạng rõ, mật khẩu mạnh, cài đặt trọn vẹn, khóa còn hiệu lực khi xóa file khóa, giữ nguyên `APP_KEY`, lỗi giữa chừng rồi chạy lại.
- E2E thật ngày 06/11/2026: bản mã sạch (không có `.env`), PHP 8.3, **MySQL 8.0.46**, Redis 7, Chromium qua Playwright: đủ 7 bước, 160 bảng, Super Admin bcrypt, `.env` 0640, `/install` trả 403 sau khi cài, nhật ký không chứa mật khẩu. Chi tiết: [E2E-TEST-REPORT.md](E2E-TEST-REPORT.md).
