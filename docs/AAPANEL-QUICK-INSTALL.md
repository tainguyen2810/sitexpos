# Cài nhanh trên aaPanel (Ubuntu) — Phase 9

Hướng dẫn ngắn để chạy SiteX Post Cloud trên một máy chủ Ubuntu có aaPanel. Chi tiết vận hành: [DEPLOYMENT.md](DEPLOYMENT.md). Trình cài đặt web: [INSTALLATION-WIZARD.md](INSTALLATION-WIZARD.md).

> Các lệnh SSH bên dưới chạy bằng user web `www` (`sudo -u www …`), **không** chạy bằng root, trừ khi ghi rõ.

## 1. Chuẩn bị trong aaPanel (khoảng 15 phút)

1. **App Store** → cài Nginx, **PHP 8.4** (hoặc 8.3), **MySQL 8.4** (không dùng MariaDB), **Redis**, **Supervisor**.
2. **PHP 8.4 → Install extensions**: `redis`, `fileinfo`, `intl`, `bcmath` (gd, curl, mbstring thường có sẵn).
3. **PHP 8.4 → Disabled functions**: bỏ `proc_open`, `proc_get_status`, `pcntl_signal`, `pcntl_alarm`, `pcntl_async_signals` khỏi danh sách (Horizon và mysqldump cần).
4. **Databases → Add**: tạo database `sitexpost` (utf8mb4), user riêng, mật khẩu mạnh. Database phải **trống**.
5. **Website → Add site** cho 3 domain:
   - `api.<domain>` → Site directory: `/www/wwwroot/sitexpost/backend/public`, PHP 8.4.
   - `app.<domain>` → `/www/wwwroot/sitexpost/frontend/dist/customer` (static).
   - `admin.<domain>` → `/www/wwwroot/sitexpost/frontend/dist/admin` (static).
6. **SSL** → Let's Encrypt cho cả 3 domain, bật Force HTTPS.

## 2. Đưa mã nguồn lên

Cách A — bản phát hành (khuyến nghị): tải `sitex-post-<version>.tar.gz` (tạo bằng `scripts/release/build-release.sh`), kiểm tra `sha256sum -c`, giải nén vào `/www/wwwroot/sitexpost`. Bản này đã có `vendor/`, `frontend/dist/` và plugin zip.

Cách B — từ git (cần Composer và Node trên máy chủ):

```bash
cd /www/wwwroot && sudo -u www git clone <repo-url> sitexpost && cd sitexpost
sudo -u www git checkout v1.0.0
cd backend && sudo -u www composer install --no-dev --optimize-autoloader
cd ../frontend && sudo -u www npm ci && sudo -u www VITE_API_BASE_URL=https://api.<domain> VITE_CUSTOMER_APP_URL=https://app.<domain> npm run build
```

Quyền (root, một lần):

```bash
chown -R www:www /www/wwwroot/sitexpost
chmod -R 775 /www/wwwroot/sitexpost/backend/storage /www/wwwroot/sitexpost/backend/bootstrap/cache
```

## 3. Nginx cho domain API

Website → `api.<domain>` → Config: thay khối `location /` bằng mẫu "Nginx: domain API" tải từ trình cài đặt (bước 5), hoặc tối thiểu:

```nginx
root /www/wwwroot/sitexpost/backend/public;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ /\.(?!well-known) { deny all; }
```

Hai domain SPA cần fallback về `index.html`: `location / { try_files $uri $uri/ /index.html; }`.

## 4. Chạy trình cài đặt

1. Mở `https://api.<domain>/install`.
2. Lấy mã trong **Files** → `backend/storage/app/installer/setup-token.txt` (hoặc `sudo -u www php artisan sitex:install:token`).
3. Làm theo 7 bước. Ở bước Redis, tải 4 mẫu cấu hình.

## 5. Worker và scheduler

- **Supervisor** → Add daemon: dán mẫu "Supervisor: Horizon" (user `www`, lệnh `php artisan horizon`). Nếu không dùng được Horizon, dùng mẫu `queue:work`.
- **Cron** → Add Shell Script, chạy **mỗi phút**: mẫu "Cron: scheduler" (`cd …/backend && php artisan schedule:run`).
- Kiểm tra: Admin → Hệ thống → Sức khỏe: mục *Horizon* và *Scheduler* phải "Ổn định" sau 1–2 phút.

## 6. Sau khi cài

1. Mở `https://admin.<domain>`, đăng nhập Super Admin, bật 2FA (bắt buộc).
2. Làm Setup Wizard (`/setup`): [POST-INSTALL-SETUP.md](POST-INSTALL-SETUP.md).
3. Sao lưu: tạo khóa `sudo -u www php artisan sitex:backup:key`, thêm dòng in ra vào `backend/.env`, **lưu một bản khóa ngoài máy chủ**, rồi bật lịch sao lưu ở Admin → Hệ thống → Sao lưu. Xem [BACKUP-RESTORE.md](BACKUP-RESTORE.md).
4. Cảnh báo: Admin → Hệ thống → Cảnh báo (email, Telegram).
5. `php artisan optimize` sau mỗi lần sửa `.env`.

## 7. Lỗi thường gặp

| Triệu chứng | Nguyên nhân | Cách sửa |
|---|---|---|
| `/install` trả 404 trang Nginx | Thiếu `try_files` | Mục 3 |
| `/install` hiện chữ trần, không có CSS | Document root không phải `backend/public` | Website → Site directory |
| Bước 2 báo FAIL `ext_*` | Thiếu extension | PHP → Install extensions |
| Bước 3 `database_not_empty` | DB đã có bảng | Tạo DB mới, **không** xóa DB cũ nếu chưa chắc |
| Horizon "Nghiêm trọng" | Supervisor chưa chạy hoặc `pcntl` bị chặn | Mục 1.3 và 5 |
| Scheduler "Nghiêm trọng" | Cron chưa tạo | Mục 5 |
