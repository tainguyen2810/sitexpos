# E2E: SiteX Post Cloud ↔ WordPress thật

Chạy Cloud + một hoặc nhiều WordPress cục bộ, ghép nối qua wizard wp-admin thật (Playwright) rồi kiểm tra: health check, đồng bộ (chuyên mục/thẻ/bài/trang/sản phẩm), đăng thử ×3 không tạo trùng, ảnh featured + inline, SEO (Yoast hoặc thẻ meta tích hợp), request ký sai/replay/timestamp cũ/không ký bị từ chối, lọc XSS, đổi khóa, ngắt kết nối từ Cloud và từ WP rồi xác nhận không còn quyền ghi.

## Chuẩn bị (một lần)

- PHP 8.x với mysqli, MySQL/MariaDB (user `wp`/`wp` có quyền tạo DB, đổi bằng `WP_DB_USER`/`WP_DB_PASSWORD`/`WP_DB_HOST`).
- Mã nguồn WordPress (ví dụ 6.4.x và bản mới nhất) giải nén vào hai thư mục.
- Node 22 + Playwright (`npm i -g playwright` hoặc dùng bản có sẵn; script tải Playwright bằng `require`, nên `NODE_PATH=$(npm root -g)` cũng được).
- Backend đã `composer install`, `.env` có `APP_KEY`.

## Chạy

```bash
# 1. Cloud (biến môi trường CHỈ dùng cho test cục bộ: cho phép http://127.0.0.1)
cd backend
export DB_DATABASE=sitexpost_e2e QUEUE_CONNECTION=database CACHE_STORE=database SESSION_DRIVER=database \
       SITEX_SSRF_ALLOW_HOSTS=127.0.0.1 SITEX_SSRF_ALLOW_HTTP=true \
       APP_URL=http://127.0.0.1:8000 SITEX_CONNECTOR_PUBLIC_URL=http://127.0.0.1:8000 MAIL_MAILER=log
php artisan migrate:fresh --force
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t public public/index.php &
php artisan queue:work --queue=connector,default --tries=1 &

# 2. WordPress (cài site, symlink plugin, tạo tài khoản Author + dữ liệu mẫu)
php wordpress-plugin/tests/e2e/install-wp.php /path/wp-6.8 wp68 8081 --stubs   # --stubs: giả lập WooCommerce + Yoast
php wordpress-plugin/tests/e2e/install-wp.php /path/wp-6.4 wp64 8082
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8081 -t /path/wp-6.8 &
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8082 -t /path/wp-6.4 &

# 3. Chạy (cùng các biến môi trường ở bước 1 để script tinker được DB)
cd wordpress-plugin/tests/e2e
BACKEND_DIR=../../../backend node run-e2e.mjs /path/wp-6.8@8081:stubs /path/wp-6.4@8082
```

Ảnh chụp từng bước wizard lưu ở `tests/e2e/screenshots/` (không commit). Lần chạy lại cần cài lại WordPress (DB mới) và, nếu chạy nhiều lần trong một giờ, `php artisan cache:clear` để xóa bộ đếm giới hạn ghép nối 10 lần/giờ/IP.

`--stubs` chỉ giả lập sự **hiện diện** của WooCommerce (post type `product`, giá trong meta) và Yoast (hằng `WPSEO_VERSION`); không thay thế việc thử với plugin thật trước khi phát hành.
