# Triển khai bằng Docker (ADR-028)

Cách này chạy toàn bộ SiteX Post Cloud trong các container riêng, **không đụng** vào Nginx/PHP/MariaDB đang phục vụ các site khác trên cùng máy chủ (ví dụ máy chủ aaPanel chạy nhiều WordPress). Cách cài truyền thống vẫn dùng được: [AAPANEL-QUICK-INSTALL.md](AAPANEL-QUICK-INSTALL.md).

## 1. Kiến trúc

```
Internet ──HTTPS──▶ Nginx của máy chủ (aaPanel, SSL Let's Encrypt)
                     ├─ api.<domain>   ─▶ 127.0.0.1:8080 ┐
                     ├─ app.<domain>   ─▶ 127.0.0.1:8081 ├─ container app  (Nginx + PHP-FPM 8.4)
                     └─ admin.<domain> ─▶ 127.0.0.1:8082 ┘
                                         container horizon   (hàng đợi)
                                         container scheduler (schedule:work)
                                         container mysql     (MySQL 8.4, không mở cổng ra ngoài)
                                         container redis     (Redis 7, không mở cổng ra ngoài)
```

| Volume | Chứa gì | Ghi chú |
|---|---|---|
| `config` | `.env` của Laravel do trình cài đặt ghi (có **APP_KEY**) | Mất volume này = không giải mã được dữ liệu đã mã hóa. Sao lưu ra ngoài máy chủ. |
| `storage` | file tải lên, log, trạng thái installer, bản sao lưu mã hóa (`app/backups`) | |
| `mysql`, `redis` | dữ liệu MySQL, Redis (AOF) | |

Image chỉ chứa mã nguồn, `vendor/` (không có gói dev) và hai SPA đã build. Image **không** chứa `.env` hay secret nào. URL của SPA được nạp lúc chạy qua `/config.js` (container ghi từ `SITEX_PUBLIC_*`), nên một image dùng được cho mọi domain.

## 2. Chuẩn bị

1. Máy chủ Linux có Docker + Docker Compose v2 (aaPanel → **App Store → Docker**). Còn trống ≥ 2 GB RAM (cả bộ dùng khoảng 1–1,5 GB) và ≥ 5 GB đĩa.
2. Một tên miền với 3 bản ghi A trỏ về IP máy chủ, ví dụ `api.staging.tenmien.com`, `app.staging.tenmien.com`, `admin.staging.tenmien.com`.
3. Image:
   - **Từ GHCR (khuyến nghị):** tạo tag `v1.0.0` trên GitHub → workflow *Docker image* build và đẩy `ghcr.io/tainguyen2810/sitexpos:1.0.0` và `:latest`. Muốn thử bản `main` chưa gắn tag: GitHub → Actions → *Docker image* → *Run workflow* → image `:edge`.
     Repo private nên image cũng private. Trên máy chủ đăng nhập một lần bằng Personal Access Token (classic) chỉ có quyền `read:packages`:
     `echo <TOKEN> | docker login ghcr.io -u tainguyen2810 --password-stdin`
   - **Build ngay trên máy chủ:** `git clone` repo rồi dùng thêm file `docker-compose.build.yml` (mục 7).

## 3. Cài đặt

```bash
sudo mkdir -p /opt/sitexpost && cd /opt/sitexpost
# lấy 3 file từ repo: deploy/docker/docker-compose.yml, deploy/docker/env.example, deploy/docker/update.sh
sudo chmod +x update.sh
sudo cp env.example .env && sudo chmod 600 .env
sudo nano .env      # điền 3 URL và 2 mật khẩu (tạo bằng: openssl rand -base64 24)
sudo docker compose pull
sudo docker compose up -d
sudo docker compose ps        # app "healthy"; horizon/scheduler chờ cài đặt (bình thường)
```

### 3.1. Reverse proxy trong aaPanel (cho từng domain)

1. **Website → Add site**: nhập domain (ví dụ `api.staging.tenmien.com`), PHP: *Pure static*, không tạo database.
2. **SSL → Let's Encrypt** → bật **Force HTTPS**.
3. **Reverse proxy → Add**: Target URL `http://127.0.0.1:8080` (app: `8081`, admin: `8082`), Send domain `$host`.
4. Mở **Config** của reverse proxy (hoặc Config của site), trong khối `location /` bảo đảm có:
   ```nginx
   proxy_set_header Host $host;
   proxy_set_header X-Real-IP $remote_addr;
   proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
   proxy_set_header X-Forwarded-Proto $scheme;
   proxy_read_timeout 660s;
   ```
   và ở cấp `server`: `client_max_body_size 25m;`
   `X-Forwarded-Proto` là bắt buộc: thiếu nó, Laravel tưởng là HTTP (cookie bảo mật, kiểm tra HTTPS sai).

Kiểm tra: `https://api.<domain>/up` trả trang "Application up"; `https://app.<domain>` hiện trang đăng nhập.

### 3.2. Trình cài đặt

1. Mở `https://api.<domain>/install`.
2. Mã cài đặt: `sudo docker compose exec app artisan sitex:install:token`
3. Bước Database: đã điền sẵn host `mysql`, cổng `3306`, database/user theo `.env`; nhập **MYSQL_PASSWORD**.
4. Bước Ứng dụng: 3 URL đã điền sẵn từ `SITEX_PUBLIC_*`.
5. Bước Redis: host `redis`, cổng `6379`, không mật khẩu (Redis chỉ nằm trong mạng nội bộ của compose).
6. Cài đặt → trình cài đặt tự khóa. Trong khoảng 30 giây container `horizon` và `scheduler` tự chạy (`docker compose logs -f horizon`).

### 3.3. Sau khi cài

1. `https://admin.<domain>` → đăng nhập Super Admin → bật 2FA → Setup Wizard ([POST-INSTALL-SETUP.md](POST-INSTALL-SETUP.md)).
2. Khóa sao lưu (khác APP_KEY):
   ```bash
   sudo docker compose exec app artisan sitex:backup:key          # in ra một dòng SITEX_BACKUP_ENCRYPTION_KEY=...
   sudo docker compose exec app sh -c 'echo "SITEX_BACKUP_ENCRYPTION_KEY=<giá trị>" >> /var/www/config/.env'
   sudo docker compose restart
   ```
   Lưu khóa này và một bản `.env` ngoài máy chủ: `sudo docker compose cp app:/var/www/config/.env ./sitex-env-backup` (giữ kín, xóa khỏi máy chủ sau khi đã cất).
3. Admin → Hệ thống → Sao lưu: bật lịch. Bản sao lưu nằm trong volume `storage` (`app/backups`); chép ra ngoài:
   `sudo docker compose cp app:/var/www/html/backend/storage/app/backups ./backups`
4. Admin → Hệ thống → Sức khỏe: Horizon và Scheduler phải "Ổn định".

## 4. Nâng cấp

Một lệnh SSH:

```bash
sudo /opt/sitexpost/update.sh 1.1.0     # lên phiên bản 1.1.0
sudo /opt/sitexpost/update.sh           # tải bản mới nhất của tag đang dùng (vd. latest)
```

`update.sh` (lấy từ `deploy/docker/update.sh`, đặt cạnh `docker-compose.yml`) làm lần lượt: sao lưu mã hóa + kiểm tra (bắt buộc khi đã đặt khóa sao lưu; sao lưu lỗi thì dừng, không đổi gì) → ghi `SITEX_VERSION` → `docker compose pull` (lỗi thì trả lại phiên bản cũ) → `docker compose up -d` → chờ pre-flight/migration → báo kết quả. Nếu bản mới bị chặn, script in đúng lệnh quay lại, ví dụ `sudo /opt/sitexpost/update.sh 1.0.0`. Script không xóa volume, không sửa `.env` của Laravel.

Làm tay (tương đương):

```bash
cd /opt/sitexpost
sudo docker compose exec app artisan sitex:backup:run --verify
sudo sed -i 's/^SITEX_VERSION=.*/SITEX_VERSION=1.1.0/' .env
sudo docker compose pull && sudo docker compose up -d
sudo docker compose logs -f app
```

Khi container `app` khởi động (đã cài đặt), nó tự:
1. chạy `sitex:upgrade:check` (pre-flight của [UPGRADE-GUIDE.md](UPGRADE-GUIDE.md));
2. nếu có migration: tạo bản sao lưu mã hóa (nếu đã đặt khóa sao lưu), rồi chạy **chỉ migration bổ sung** và ghi phiên bản;
3. nếu pre-flight bị chặn (migration phá dữ liệu, yêu cầu hệ thống) hoặc sao lưu/migration lỗi: **không migrate**, đưa site vào chế độ bảo trì và ghi lý do vào log. Quay lại bản cũ: đặt lại `SITEX_VERSION` cũ rồi `docker compose up -d`;
4. tạo cache (`artisan optimize`). `horizon`/`scheduler` chỉ chạy khi database đã ở đúng phiên bản.

Database không tự lùi phiên bản. Migration là bổ sung nên bản cũ vẫn chạy được; khôi phục sao lưu chỉ làm qua SSH theo [ROLLBACK-PLAN.md](ROLLBACK-PLAN.md).

## 5. Lệnh thường dùng

| Việc | Lệnh (trong `/opt/sitexpost`) |
|---|---|
| Trạng thái | `docker compose ps` |
| Log | `docker compose logs -f app` (hoặc `horizon`, `scheduler`, `mysql`); log Laravel: `docker compose exec app sh -c 'tail -n 100 storage/logs/laravel-$(date +%F).log'` |
| Lệnh artisan | `docker compose exec app artisan <lệnh>` (luôn chạy bằng `www-data`) |
| Sau khi sửa `.env` của Laravel | `docker compose restart` |
| Dừng / chạy lại | `docker compose stop` / `docker compose up -d` |

Không dùng `docker compose down -v`: cờ `-v` xóa volume, tức xóa database, file và APP_KEY.

## 6. Lỗi thường gặp

| Triệu chứng | Nguyên nhân | Cách sửa |
|---|---|---|
| `docker compose up` báo `set SITEX_PUBLIC_API_URL` | Thiếu biến trong `.env` của compose | Điền đủ theo `env.example` |
| `pull access denied` / `unauthorized` | Chưa `docker login ghcr.io` | Mục 2.3 |
| 502 Bad Gateway ở domain | Container chưa chạy hoặc sai cổng | `docker compose ps`, kiểm tra Target URL |
| Installer cảnh báo HTTPS dù đã có SSL | Proxy thiếu `X-Forwarded-Proto` | Mục 3.1 bước 4 |
| SPA gọi sai domain API | `SITEX_PUBLIC_API_URL` sai | Sửa `.env`, `docker compose up -d` |
| `horizon` mãi "Waiting…" | Chưa cài đặt, hoặc nâng cấp bị chặn | `docker compose logs app` |
| Site báo bảo trì sau khi đổi phiên bản | Pre-flight chặn | Đọc `docker compose logs app`, quay lại phiên bản cũ |

## 7. Build image tại chỗ

```bash
git clone https://github.com/tainguyen2810/sitexpos.git && cd sitexpos/deploy/docker
cp env.example .env && nano .env
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

## 8. Bảo mật

- Cổng 8080–8082 chỉ nghe trên `127.0.0.1`; MySQL và Redis không mở cổng ra ngoài.
- `SITEX_TRUSTED_PROXIES` (mặc định các dải mạng nội bộ của Docker) quyết định ai được tin header `X-Forwarded-*`. Không đặt `*`.
- Mã nguồn trong image thuộc root, PHP chạy bằng `www-data` chỉ ghi được `storage/`, `bootstrap/cache` và volume `config`.
- Các quy tắc của trình cài đặt giữ nguyên (ADR-025): mã cài đặt chỉ lấy được qua SSH, không ghi đè database có dữ liệu, khóa vĩnh viễn sau khi cài.
- Web vẫn không tải hay ghi đè mã nguồn (ADR-026): nâng cấp = người vận hành đổi tag image.
- Cả 3 tên miền luôn chặn máy tìm kiếm: header `X-Robots-Tag: noindex, nofollow` và `robots.txt` → `Disallow: /` (`docker/nginx-noindex.conf`).
- Image chứa mã nguồn plugin WordPress (`/var/www/html/wordpress-plugin/sitex-post`). API phục vụ file zip đã cấu hình sẵn URL API của máy chủ tại `/api/v1/plugin/sitex-post.zip` (nút "Tải plugin WordPress" trong app khách).
