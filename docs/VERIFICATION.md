# Xác minh môi trường & dependencies — 05/11/2026

## 1. Khảo sát

| Hạng mục | Kết quả |
|---|---|
| Repository GitHub | **Chưa có.** Không có repository nào khả dụng cho phiên làm việc; dự án được khởi tạo dưới dạng git repo cục bộ (nhánh `main`) |
| Máy phát triển của người dùng | Windows x64 (thiết bị đã liên kết, chưa cấp thư mục) |
| Môi trường khảo sát (Linux cloud) | PHP 8.3.6 (có `pcntl`, `posix`, `redis`, `sodium`, `intl`, `pdo_mysql`, `gd`), Composer 2, Node 22.23, npm 10.9, Redis 7.0.15. **Không có MySQL** |

## 2. Phiên bản mới nhất (Packagist/npm, 05/11/2026)

| Gói | Bản ổn định mới nhất | Yêu cầu PHP / ghi chú |
|---|---|---|
| laravel/framework | v13.40.0 (03/11/2026) | `^8.3` |
| laravel/horizon | v5.50.1 | hỗ trợ Laravel `^13.0` |
| laravel/sanctum | v4.3.3 | hỗ trợ `^13.0` |
| laravel/fortify | v1.41.0 | |
| larastan/larastan | v3.14.0 | hỗ trợ `^13` |
| pestphp/pest | v5.3.5 — **yêu cầu PHP 8.4**; v4.7.8 yêu cầu `^8.3` | → chọn Pest 4 |
| vue | 3.5.44 | |
| typescript | 7.0.2 (latest), 6.0.3 | → chọn 6.0 (xem §4) |
| vite | 8.3.5 | |
| tailwindcss / @tailwindcss/vite | 4.3.4 | |
| vue-router | 5.4.0 | |
| pinia | 4.1.0 | |
| vue-i18n | 11.4.18 | |
| vue-tsc | 3.3.14 | |
| MySQL | 8.0 EOL 04/2026 | → chọn 8.4 LTS |

## 3. Backend — giải quyết dependency (đã chạy)

`composer update --no-install` với [verification-composer.json](verification-composer.json), ràng buộc platform PHP 8.3.6:

- ✅ Giải quyết thành công 153 gói, không xung đột: laravel/framework v13.40.0, sanctum v4.3.3, fortify v1.41.0, horizon v5.50.1, tinker v3.0.2, larastan v3.14.0 (phpstan 2.3.5), pest v4.7.8, pest-plugin-laravel v4.1.0, phpunit 12.5.33, pint v1.32.1, htmlpurifier v4.19.1, flysystem-aws-s3-v3 3.35.3.
- ✅ `composer audit --locked`: **không có cảnh báo bảo mật**.
- ⚠️ Lưu ý: `laravel/tinker ^2.x` không hỗ trợ Laravel 13 → dùng `^3.0`.
- ⏸ Chưa cài đầy đủ `vendor/` và chưa chạy app: môi trường cloud bị chặn tải zip từ GitHub nên Composer phải clone từng gói (rất chậm); việc cài thật sẽ làm ở Phase 1.

## 4. Frontend — cài thử & build (đã chạy)

Cài `vue@3.5 vue-router@5 pinia@4 vue-i18n@11` + dev `vite@8 @vitejs/plugin-vue@6 typescript@6.0 vue-tsc@3 tailwindcss@4 @tailwindcss/vite@4 vitest eslint`:

- ✅ Không có xung đột peer dependency.
- ✅ App mẫu (SFC + Pinia + Router + i18n + Tailwind): `vue-tsc --noEmit` pass, `vite build` thành công.
- ❌ Với `typescript@7.0.2`: `vue-tsc` lỗi `ERR_PACKAGE_PATH_NOT_EXPORTED: './lib/tsc'` → **ghim TypeScript 6.0.x**.
- ⚠️ `npm audit`: 3 cảnh báo mức moderate ở `minimatch` (phụ thuộc của `eslint` 10.14, chỉ dùng khi dev, không vào bundle). Theo dõi bản vá ở Phase 1.

## 5. Chưa xác minh (cần ở Phase 1 / khi có server)
- Migration trên MySQL 8.4 thật.
- Horizon chạy dưới Supervisor trên aaPanel.
- Plugin trên WordPress 6.4 / PHP 7.4 (Phase 2).
