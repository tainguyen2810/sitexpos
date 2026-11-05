# Smoke test trình duyệt (Playwright) — chỉ dùng ở môi trường dev

Kiểm tra end-to-end Phase 1 trên app thật (không mock). **Chỉ chạy với DB dev đã seed demo** — script tạo user/workspace mới và bật 2FA cho `superadmin@demo.sitexpost.test`.

Chuẩn bị (backend `.env`: `MAIL_MAILER=log`, `SESSION_DRIVER=database`, `SANCTUM_STATEFUL_DOMAINS` gồm `127.0.0.1:5173,127.0.0.1:5174`):

```bash
cd backend && php artisan migrate:fresh --seed --seeder=DemoSeeder && php artisan serve --port=8000
cd frontend && npx vite --host 127.0.0.1                                   # app khách hàng :5173
cd frontend && SITEX_APP=admin VITE_CUSTOMER_APP_URL=http://127.0.0.1:5173 npx vite --host 127.0.0.1   # admin :5174
```

Chạy (cần `playwright` đã cài, ví dụ `npm i -g playwright && npx playwright install chromium`):

```bash
node scripts/e2e/customer.js ./shots   # đăng ký → xác minh email (đọc link trong log) → workspace → mời → switcher → chặn chéo tenant → VIEWER
node scripts/e2e/admin.js ./shots      # chặn non-staff → buộc 2FA → dashboard → chi tiết workspace → phiên hỗ trợ chỉ xem → audit
```

Biến môi trường tùy chọn: `SITEX_APP_URL`, `SITEX_ADMIN_URL`, `SITEX_LOG` (đường dẫn file log Laravel chứa email).
`admin.js` cần DB vừa seed lại (super admin demo chưa bật 2FA).
