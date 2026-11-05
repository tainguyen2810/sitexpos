# SiteX Post

Nền tảng SaaS AI SEO tự động cho WordPress.

- **SiteX Post Cloud** — backend Laravel 13 (`backend/`) + 2 SPA Vue 3 (`frontend/`: app khách hàng và app Super Admin)
- **SiteX Post** — plugin WordPress (`wordpress-plugin/sitex-post/`, Phase 2)

> Trạng thái: **Phase 1 — SaaS Core đã xong** (xác thực, workspace đa tenant, phân quyền, thành viên & lời mời, audit, dashboard khách hàng & Super Admin). Chi tiết: [docs/PHASE1-REPORT.md](docs/PHASE1-REPORT.md). Chưa có AI, billing, WordPress.

## Cài đặt môi trường dev

Yêu cầu: PHP 8.3+ (khuyến nghị 8.4; extension: `bcmath intl mbstring pdo_mysql sodium` + `redis` nếu dùng Redis), Composer 2, Node 24, MySQL 8.4 (hoặc 8.0+), Redis 7 (tùy chọn ở dev).

### Linux / macOS
```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
# Tạo DB + user MySQL rồi sửa DB_* trong .env
# Không có Redis? đặt QUEUE_CONNECTION=database và CACHE_STORE=database
php artisan migrate --seed --seeder=DemoSeeder   # tài khoản demo in ra màn hình (mật khẩu SiteXDemo2026)
php artisan serve                                 # http://localhost:8000
php artisan queue:work                            # hoặc: php artisan horizon (cần Redis)

cd ../frontend
npm install
cp .env.example .env
npm run dev          # app khách hàng  http://localhost:5173
npm run dev:admin    # app Super Admin http://localhost:5174
```

### Windows (PowerShell)
```powershell
cd backend; composer install; copy .env.example .env; php artisan key:generate
# Sửa DB_* trong .env. Nếu không có Redis/phpredis: QUEUE_CONNECTION=database, CACHE_STORE=database
php artisan migrate --seed --seeder=DemoSeeder
php artisan serve
php artisan queue:work      # Horizon không chạy trên Windows native (cần pcntl)
cd ..\frontend; npm install; copy .env.example .env; npm run dev
```

Email ở dev ghi vào `backend/storage/logs/laravel-*.log` (`MAIL_MAILER=log`) — mở link xác minh/đặt lại mật khẩu/lời mời từ đó.
Tạo Super Admin thật (không có tài khoản admin mặc định): `php artisan sitex:make-super-admin email@domain` — lần đầu vào app admin sẽ bị buộc bật 2FA.

### Kiểm tra chất lượng
```bash
scripts/check.sh          # Linux/macOS: Pint, Larastan, Pest, ESLint, vue-tsc, Vitest, build
scripts/check.ps1         # Windows
```
Smoke test trình duyệt: [scripts/e2e/README.md](scripts/e2e/README.md).

## Tài liệu
| File | Nội dung |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Kiến trúc, module, phiên bản công nghệ, ADR |
| [docs/DATABASE.md](docs/DATABASE.md) | Thiết kế cơ sở dữ liệu |
| [docs/SECURITY.md](docs/SECURITY.md) | Bảo mật, phân quyền, connector WordPress |
| [docs/API-CONTRACTS.md](docs/API-CONTRACTS.md) | Hợp đồng API |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Roadmap & checklist nghiệm thu |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Cài đặt, nâng cấp, vận hành |
| [docs/VERIFICATION.md](docs/VERIFICATION.md) | Kết quả xác minh dependencies |
| [docs/PHASE1-REPORT.md](docs/PHASE1-REPORT.md) | Báo cáo Phase 1 |
| [CLAUDE.md](CLAUDE.md) | Quy tắc làm việc cho Claude/dev |
