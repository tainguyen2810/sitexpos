# Bảo mật trình cài đặt — Phase 9

Mục tiêu: người đầu tiên truy cập domain **không** chiếm được quyền Super Admin; không lộ secret; không ghi đè dữ liệu; không chạy lệnh tùy ý; trình cài đặt khóa vĩnh viễn sau khi xong.

## 1. Mô hình đe dọa và biện pháp

| Đe dọa | Biện pháp | Kiểm chứng |
|---|---|---|
| Người lạ mở `/install` trước chủ máy chủ | Mọi API cần header `X-Setup-Token`. Mã sinh ngẫu nhiên 192 bit (`random_bytes(24)`), chỉ nằm trong file `storage/app/installer/setup-token.txt` (0600, ngoài web root) hoặc in ra qua SSH. Máy chủ chỉ lưu SHA-256, so sánh bằng `hash_equals`, hết hạn 24 giờ | `InstallerTest`: thiếu/sai/hết hạn mã → 401 |
| Dò mã | 10 lần sai / 10 phút / IP và 50 lần sai / giờ toàn cục → 429; 120 request/phút/IP. Bộ đếm dùng cache **file** (không phụ thuộc DB/Redis chưa cấu hình) | `InstallerTest` (rate limit) |
| Mở lại trình cài đặt sau khi đã cài | Khóa nhiều tín hiệu: `installed.json`, `SITEX_INSTALLED`, `system.installed_at` trong DB, hoặc đã có `SUPER_ADMIN`. Bất kỳ tín hiệu nào → 403 | `InstallerTest`: xóa file khóa vẫn 403; tạo Super Admin bằng CLI cũng khóa |
| Ghi đè database đang có dữ liệu | Từ chối DB có bảng (`database_not_empty`); dấu vân tay DB được xác nhận lại ngay trước migration; chỉ cho chạy lại khi chính trình cài đặt đã bắt đầu migration trên DB đó | `InstallerTest` |
| Thay `APP_KEY` làm mất dữ liệu mã hóa | `EnvWriter` không bao giờ thay `APP_KEY` đã có; luôn sao lưu `.env` cũ | `InstallerTest` (APP_KEY preserved) |
| Lộ mật khẩu DB/Redis/SMTP | Lưu phía máy chủ, mã hóa bằng khóa tạm `pending.key` (0600); API chỉ trả cờ `*_set`; nhật ký `install.log` che secret; xóa cấu hình tạm sau khi cài | `InstallerTest` (no plaintext secrets); E2E: `grep` mật khẩu trong log = 0 |
| Lộ mật khẩu Super Admin | Chỉ lưu bcrypt hash từ bước 6; không bao giờ trả về; không lưu trong trình duyệt | `InstallerTest` |
| Credential trong trình duyệt | Mã cài đặt chỉ trong biến JS; không dùng localStorage/sessionStorage/cookie | Xem `public/installer/installer.js` |
| XSS trong trình cài đặt | CSP chặt (`default-src 'self'`, không inline script/style), mọi chuỗi chèn bằng `textContent` | Header CSP trong `InstallerController::page` |
| CSRF | Không dùng cookie/phiên; xác thực bằng header nên trình duyệt khác không gửi kèm được | Thiết kế |
| Chạy lệnh tùy ý / root | Không có endpoint chạy lệnh. Chỉ gọi `migrate`, `db:seed` với class cố định. Supervisor/Cron/Nginx được **tải mẫu** để người vận hành tự dán | Code review `RunInstallation` |
| SSRF qua ô host DB/Redis | Chỉ mở kết nối PDO/TCP tới host do người có mã cài đặt nhập (là chủ máy chủ); không trả nội dung phản hồi, chỉ mã lỗi đã phân loại | `DatabaseProbe`, `RedisProbe` |
| Người dùng thường dùng installer sau cài | 403 trước khi tới controller; `SITEX_INSTALLER_ENABLED=false` → 404 | `InstallerTest` |

## 2. File trong `storage/app/installer/`

| File | Nội dung | Quyền |
|---|---|---|
| `token.json` | SHA-256 của mã + hạn | 0600, xóa khi cài xong |
| `setup-token.txt` | Mã dạng rõ (chỉ khi tự sinh hoặc `--file`) | 0600, xóa khi cài xong |
| `pending.json`, `pending.key` | Cấu hình tạm, secret mã hóa | 0600, xóa khi cài xong |
| `progress.json`, `install.log` | Tiến trình, nhật ký đã che secret | 0600 |
| `env-backup-*.env` | Bản sao `.env` trước khi ghi | 0600 |
| `installed.json` | Khóa | 0600 |

Thư mục có `.htaccess` deny và nằm ngoài `public/`; Nginx mẫu chỉ phục vụ `backend/public`.

## 3. Việc người vận hành phải làm

1. Trỏ document root đúng `backend/public` (bước kiểm tra môi trường cảnh báo nếu sai).
2. Dùng HTTPS trước khi nhập mật khẩu (bước kiểm tra cảnh báo nếu không).
3. Sau khi cài: `chmod 600 backend/.env` nếu muốn chặt hơn 0640, lưu `APP_KEY` và `SITEX_BACKUP_ENCRYPTION_KEY` ngoài máy chủ.
4. Có thể đặt `SITEX_INSTALLER_ENABLED=false` để trình cài đặt trả 404.
