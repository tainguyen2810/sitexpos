# SiteX Post — Triển khai & vận hành

Môi trường mục tiêu: **Ubuntu 22.04/24.04 LTS + aaPanel**, Nginx, PHP-FPM, MySQL 8.4, Redis 7, Supervisor. Docker **không bắt buộc**. Production bắt buộc HTTPS.

> ⚠️ Claude không tự deploy production. Mọi bước dưới đây do người vận hành thực hiện hoặc phê duyệt.

Các giá trị trong `<...>` là biến môi trường của bạn; **không hardcode** đường dẫn trong code. Ví dụ dưới dùng `APP_DIR=/www/wwwroot/sitexpost` (đường dẫn mặc định kiểu aaPanel) chỉ để minh họa.

## 1. Yêu cầu hệ thống

| Thành phần | Phiên bản | Ghi chú |
|---|---|---|
| PHP | 8.4 (tối thiểu 8.3) | Extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd` hoặc `imagick`, `intl`, `mbstring`, `openssl`, `pcntl`, `pdo_mysql`, `posix`, `redis`, `sodium`, `tokenizer`, `xml`, `zip`, `opcache` |
| aaPanel PHP | Bỏ `pcntl_*`, `proc_open`, `putenv` khỏi `disable_functions` **cho PHP CLI** (Horizon cần) | Giữ nguyên cho FPM nếu không cần |
| MySQL | 8.4 LTS | `utf8mb4`, `sql_mode` mặc định strict |
| Redis | 7.x | Đặt mật khẩu, bind 127.0.0.1 (hoặc mạng riêng khi tách worker) |
| Composer | 2.x | |
| Node.js | 24 LTS | Chỉ cần ở máy build; có thể build trên CI rồi tải `dist/` |
| Supervisor | aaPanel "Supervisor Manager" hoặc apt | Chạy Horizon |
| RAM | ≥ 4 GB (khởi điểm 1 server) | |

## 2. Bố trí domain

| Domain | Gốc web | Vai trò |
|---|---|---|
| `api.<domain>` | `<APP_DIR>/backend/public` | Laravel (API, connector, webhook, Horizon dashboard) |
| `app.<domain>` | `<APP_DIR>/frontend/dist/customer` | SPA khách hàng |
| `admin.<domain>` | `<APP_DIR>/frontend/dist/admin` | SPA Super Admin (có thể giới hạn IP) |

Các domain cùng domain gốc để Sanctum cookie hoạt động (`SESSION_DOMAIN=.<domain>`).

## 3. Cài đặt lần đầu

```bash
# 1. Mã nguồn (user web của aaPanel là www)
cd <PARENT_DIR> && git clone <repo-url> sitexpost && cd sitexpost
APP_DIR=$(pwd)

# 2. Backend
cd "$APP_DIR/backend"
composer install --no-dev --optimize-autoloader
cp .env.example .env            # điền giá trị thật, xem §4
php artisan key:generate
php artisan sitex:keys:generate # sinh SITEX_MASTER_KEY (Phase 2+)
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache
php artisan sitex:make-super-admin admin@<domain>

# 3. Frontend (trên máy build hoặc server có Node)
cd "$APP_DIR/frontend"
npm ci
VITE_API_BASE_URL=https://api.<domain> VITE_CUSTOMER_APP_URL=https://app.<domain> npm run build
# Kết quả: dist/customer/index.html và dist/admin/index.html

# 4. Quyền
chown -R www:www "$APP_DIR"
chmod -R 775 "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
chmod 600 "$APP_DIR/backend/.env"
```

**Không** chạy `db:seed --class=DemoSeeder` trên production (lệnh sẽ từ chối).

## 4. Biến môi trường quan trọng (`backend/.env`)

```dotenv
APP_NAME="SiteX Post"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.<domain>
APP_LOCALE=vi
APP_FALLBACK_LOCALE=vi
FRONTEND_URL=https://app.<domain>
ADMIN_URL=https://admin.<domain>

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sitexpost
DB_USERNAME=sitexpost
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database          # bắt buộc để có màn hình "Phiên đăng nhập" (liệt kê/đăng xuất thiết bị)
SESSION_DOMAIN=.<domain>
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=app.<domain>,admin.<domain>

FILESYSTEM_DISK=local           # hoặc s3 khi tách worker
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_ENDPOINT=                   # S3-compatible (MinIO, R2, Wasabi...)
AWS_USE_PATH_STYLE_ENDPOINT=false

MAIL_MAILER=smtp                # Brevo: smtp-relay.brevo.com:587
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=no-reply@<domain>
MAIL_FROM_NAME="SiteX Post"

SITEX_MASTER_KEY=               # Phase 2+, envelope encryption
# Phase 3: AI Gateway — khóa CHỈ đặt ở đây (không có ô nhập trong giao diện). Để trống = provider đó không dùng được.
OPENAI_API_KEY=
GEMINI_API_KEY=
ANTHROPIC_API_KEY=
DEEPSEEK_API_KEY=
# DEEPSEEK_BASE_URL=https://api.deepseek.com
# Có thể để trống các khóa trên và nhập từ trang admin Cấu hình AI (lưu mã hóa bằng APP_KEY;
# đổi APP_KEY thì phải nhập lại khóa). Khóa trong DB được ưu tiên hơn .env.
SITEX_AI_FAKE_PROVIDER=false    # production: tắt provider giả
# Các biến tinh chỉnh khác (timeout, deadline, ngân sách, workflow): xem backend/.env.example mục "Phase 3"
# Phase 5+: DATAFORSEO_LOGIN=, DATAFORSEO_PASSWORD=, BRAVE_SEARCH_API_KEY=
# Phase 9+: GOOGLE_CLIENT_ID=, GOOGLE_CLIENT_SECRET=
```

## 5. Nginx (aaPanel → Website → Config)

`api.<domain>`:
```nginx
root <APP_DIR>/backend/public;
index index.php;
client_max_body_size 20m;

add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options DENY always;
add_header Referrer-Policy strict-origin-when-cross-origin always;

location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ {
    include fastcgi_params;   # aaPanel: include enable-php-84.conf;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_pass unix:/tmp/php-cgi-84.sock;   # đường dẫn socket theo aaPanel của bạn
}
location ~ /\.(?!well-known) { deny all; }
```

`app.<domain>` và `admin.<domain>` (SPA):
```nginx
root <APP_DIR>/frontend/dist/customer;   # hoặc dist/admin
location / { try_files $uri $uri/ /index.html; }
location /assets/ { expires 1y; add_header Cache-Control "public, immutable"; }
add_header Content-Security-Policy "default-src 'self'; connect-src 'self' https://api.<domain>; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'" always;
```

SSL: aaPanel → SSL → Let's Encrypt, bật "Force HTTPS".

## 6. Queue & Scheduler

Supervisor (`/etc/supervisor/conf.d/sitexpost-horizon.conf` hoặc aaPanel Supervisor Manager):
```ini
[program:sitexpost-horizon]
command=php <APP_DIR>/backend/artisan horizon
user=www
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=<APP_DIR>/backend/storage/logs/horizon.log
```

Cron (aaPanel → Cron → Shell Script, chạy mỗi phút, user www):
```bash
cd <APP_DIR>/backend && php artisan schedule:run >> /dev/null 2>&1
```

Dashboard Horizon tại `https://api.<domain>/horizon`, chỉ SUPER_ADMIN truy cập (gate `viewHorizon`).

**Phase 2 — kết nối WordPress:**
- Horizon có thêm supervisor `supervisor-connector` cho queue `connector` (health check, đồng bộ, đăng thử; timeout 300 s, không retry tự động). `retry_after` của queue mặc định 330 s để lớn hơn timeout này. Khi chạy `queue:work` thay Horizon: `php artisan queue:work redis --queue=critical,default,notifications,connector`.
- Scheduler (cần cron `schedule:run` ở trên): `sitex:sites:health` mỗi 5 phút (xếp health check cho site quá 30 phút chưa kiểm tra) và `sitex:sites:prune` hằng ngày (xóa nhật ký kết nối/lịch sử kiểm tra quá 30 ngày).
- `.env`: `SITEX_CONNECTOR_PUBLIC_URL` = URL công khai của API (plugin gọi `<URL>/connector/v1`). **Không** bật `SITEX_SSRF_ALLOW_HTTP`/`SITEX_SSRF_ALLOW_HOSTS` trên production — chỉ dùng cho test cục bộ.
- Plugin mặc định gọi `https://api.sitexpost.com` (tên miền thật chưa chốt — câu hỏi mở Q2). Trước khi phát hành plugin phải sửa hằng mặc định trong `wordpress-plugin/sitex-post/sitex-post.php` hoặc hướng dẫn khách đặt `SITEX_POST_CLOUD_URL`.
- Đóng gói plugin: `wordpress-plugin/scripts/build-plugin.sh` → `wordpress-plugin/dist/sitex-post-<version>.zip`. Hướng dẫn cài: `docs/WORDPRESS-PLUGIN.md`.

**Phase 3 — AI Gateway & Queue:**
- File Supervisor mẫu: `deploy/supervisor/sitexpost-horizon.conf` (khuyến nghị) **hoặc** `deploy/supervisor/sitexpost-queue-work.conf` (không dùng Horizon). Chỉ dùng một trong hai.
- Horizon có 6 supervisor: `supervisor-core` (critical, default, notifications, billing), `supervisor-connector`, `supervisor-publishing`, `supervisor-ai` (writing, research, factcheck — tối đa 3 process, timeout 300 s), `supervisor-media`, `supervisor-analytics`. Sau khi đổi `config/horizon.php`: `php artisan horizon:terminate` (Supervisor tự khởi động lại).
- Không dùng Horizon: `php artisan queue:work redis --queue=critical,default,notifications,billing,connector,publishing,writing,research,factcheck,media,analytics --timeout=300`.
- Bất biến thời gian, giữ nguyên thứ tự khi chỉnh: timeout job 300 s < `REDIS_QUEUE_RETRY_AFTER` 330 s < `SITEX_WORKFLOW_LEASE_SECONDS` 360 s; `SITEX_AI_CALL_DEADLINE_SECONDS` (240) < 300.
- Scheduler thêm: `sitex:workflows:recover` mỗi phút (khôi phục run có lease hết hạn sau khi worker chết), `sitex:ai:expire-reservations` mỗi 5 phút, `horizon:snapshot` mỗi 5 phút (biểu đồ Horizon), `sitex:ai:prune` và `sitex:workflows:prune` hằng ngày. **Không có cron `schedule:run` thì workflow bị gián đoạn sẽ không tự khôi phục.**
- Sau `migrate`: chạy `php artisan sitex:ai:sync-registry` để tạo provider/model/giá/route mặc định (không ghi đè chỉnh sửa của admin). Giá mặc định cần Super Admin đối chiếu lại với trang giá chính thức trước khi bật provider thật.
- Bật provider thật: đặt khóa trong `.env` → `php artisan config:cache` → Super Admin vào **Cấu hình AI** bật provider/model và đặt ngân sách toàn hệ thống.
- Windows (dev): không có Horizon/pcntl → dùng `php artisan queue:work --queue=...` như trên.

## 7. Nâng cấp (zero-downtime cơ bản)

```bash
cd <APP_DIR>
php backend/artisan down --render="errors::503" --retry=60   # chỉ khi migration nặng
git fetch --tags && git checkout <tag-phiên-bản>
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache
php artisan horizon:terminate        # Supervisor tự khởi động lại với code mới
cd ../frontend && npm ci && npm run build
php ../backend/artisan up
```
Trước khi nâng cấp: **backup DB** (§9), đọc `CHANGELOG.md` mục "Upgrade notes". Migration phải tương thích ngược một phiên bản (thêm cột trước, xóa cột ở bản sau).

Rollback: checkout tag cũ, `composer install`, chỉ `migrate:rollback --step=N` nếu ghi chú phát hành cho phép; ưu tiên khôi phục backup.

## 8. Tách worker sang server khác

1. Chuyển `FILESYSTEM_DISK=s3` (bắt buộc — worker và web phải thấy cùng file).
2. Redis và MySQL lắng nghe trên mạng riêng, firewall chỉ cho IP server web/worker; bật mật khẩu Redis, TLS nếu qua mạng công cộng.
3. Server worker: clone cùng tag, cùng `.env` (trừ không cần Nginx), chạy Supervisor Horizon. Không chạy `schedule:run` trên nhiều server (hoặc dùng `onOneServer()` — đã cấu hình sẵn với Redis lock).
4. Server worker riêng cho AI: hiện **chưa** có biến `HORIZON_ROLE`; dùng `deploy/supervisor/sitexpost-queue-work.conf` và chỉ giữ chương trình `sitexpost-worker-ai` trên server đó (nợ kỹ thuật, xem `PHASE3-REPORT.md`).

## 9. Backup & khôi phục

- DB: `mysqldump --single-transaction --routines --triggers` hằng ngày, mã hóa (`gpg`/`age`), lưu ngoài server ≥ 30 ngày. aaPanel có backup tự động — bật kèm đích lưu từ xa.
- Storage: đồng bộ S3 có versioning, hoặc rsync thư mục `storage/app` nếu dùng local.
- `.env` và `SITEX_MASTER_KEY`: lưu riêng trong password manager. **Mất master key = mất toàn bộ secret kết nối site**.
- Diễn tập khôi phục mỗi quý trên staging.

## 10. Giám sát

- `https://api.<domain>/up` cho uptime monitor.
- Horizon: failed jobs, thời gian chờ queue (cấu hình `waits` cảnh báo).
- Log: `storage/logs/laravel-YYYY-MM-DD.log` (JSON), logrotate 14 ngày.
- Cảnh báo email tới SUPER_ADMIN: failed jobs > ngưỡng, chi phí AI > ngưỡng, backup thất bại.

## 11. Môi trường phát triển (Windows / Linux / macOS)

**Windows (native)**: cài PHP 8.3/8.4 (bật các extension trên trừ `pcntl`/`posix`), Composer, Node 24, MySQL 8.4 (hoặc Laragon), Redis (Memurai hoặc Redis trên WSL).
```powershell
cd backend; composer install; copy .env.example .env; php artisan key:generate
php artisan migrate; php artisan db:seed --class=DemoSeeder
php artisan serve                  # http://127.0.0.1:8000
php artisan queue:work redis       # thay cho Horizon trên Windows
cd ..\frontend; npm install; npm run dev
```
Horizon không chạy trên Windows native (cần `pcntl`); dùng `queue:work` hoặc WSL2.

**Linux/macOS**: tương tự, có thể chạy `php artisan horizon`. Mail dev: `MAIL_MAILER=log` hoặc Mailpit.

Kiểm tra tổng: `scripts/check.sh` (Linux/macOS) hoặc `scripts/check.ps1` (Windows).
