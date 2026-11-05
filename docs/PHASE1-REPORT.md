# Báo cáo Phase 1 — SaaS Core (05/11/2026)

Phạm vi: xác thực, workspace đa tenant, vai trò & RBAC, thành viên/lời mời, audit, dashboard khách hàng + Super Admin, dữ liệu demo (chỉ dev). **Không** có AI, billing, WordPress (đúng phạm vi). Không deploy production.

## 1. Kết quả kiểm tra (đã chạy thật)

| Lệnh | Kết quả |
|---|---|
| `scripts/check.sh` | **All checks passed** (exit 0) |
| ├ `vendor/bin/pint --test` | passed |
| ├ `vendor/bin/phpstan analyse` (Larastan level 6) | `[OK] No errors` |
| ├ `vendor/bin/pest` (SQLite) | **112 passed** (387 assertions) |
| ├ `npm run lint` (ESLint) | 0 lỗi |
| ├ `npm run typecheck` (vue-tsc, TS 6.0 strict) | 0 lỗi |
| ├ `npm run test` (Vitest) | **16 passed** (3 file) |
| └ `npm run build` | build `dist/customer` và `dist/admin` thành công |
| `pest` với `DB_CONNECTION=mysql` (**MySQL 8.0.46**) | **112 passed** |
| `migrate` → `migrate:rollback` → `migrate` (MySQL 8.0.46) | thành công |
| `composer audit` | không có cảnh báo |
| `npm audit --omit=dev` | 0 lỗ hổng (bundle production) |
| Smoke test trình duyệt `scripts/e2e/customer.js` | 9/9 bước đạt |
| Smoke test trình duyệt `scripts/e2e/admin.js` | 8/8 bước đạt |

Smoke test chạy trên app thật (Laravel `artisan serve` + Vite + MySQL), không mock:
- **Khách hàng:** khách chưa đăng nhập bị chuyển về /login. Luồng chính: đăng ký → xác minh email bằng link lấy từ log mailer → tạo workspace → dashboard → mời thành viên. Tạo workspace thứ 2 và chuyển qua lại bằng switcher. Owner của tenant B mở URL workspace của tenant khác thì thấy trang 404, không có dữ liệu. VIEWER thấy danh sách thành viên nhưng không có nút quản lý.
- **Admin:** tài khoản OWNER thường bị từ chối. SUPER_ADMIN bị buộc bật 2FA (TOTP thật), thấy mã khôi phục, rồi vào dashboard số liệu thật và xem chi tiết workspace. Mở phiên hỗ trợ: tab app khách hàng mở ra với banner, chỉ xem, không có nút ghi. Kết thúc phiên xong, sự kiện có trong nhật ký hệ thống.

## 2. Thay đổi chính

**Backend** (`backend/`, Laravel 13, namespace `SiteXPost\`)

*Kernel*
- `app/Kernel/Tenancy`:
  - `TenantContext`; `BelongsToWorkspace` + `WorkspaceScope` fail-closed (truy vấn ngoài context sẽ ném exception); chặn ghi chéo tenant.
  - `ResolveWorkspace`: xác định tenant server-side từ `{workspace}` + membership. Không phải thành viên → 404.
  - `TenantJob` / `RestoreTenantContext` cho queue.
- `app/Kernel/Http`:
  - Envelope lỗi, `X-Request-Id`, `SetLocale`, security headers, cursor pagination.
  - `ApiRequest` cấm `workspace_id`/`tenant_id`/`user_id`/`owner_id`/`platform_role` trong input.
- `app/Kernel/Security/SsrfGuard`, `Logging/RedactSecrets`.

*Modules*
- `Identity`:
  - Fortify headless (`/api/v1/auth/*`): đăng ký, đăng nhập, xác minh email, quên/đặt lại mật khẩu, 2FA.
  - `/me`, quản lý phiên, khóa tài khoản.
- `Workspace`:
  - CRUD workspace, thành viên, lời mời (token hash sha256, hết hạn 7 ngày), chuyển quyền sở hữu.
  - Enum `WorkspaceRole`/`Permission` và Policy cho mọi action; dashboard; audit theo workspace.
- `Audit`: `audit_logs` bất biến, `Audit::record()`.
- `Platform`:
  - Admin API `/api/admin/v1`, `PlatformRole`/`PlatformPermission`, bắt buộc 2FA với staff.
  - Phiên hỗ trợ chỉ xem (30 phút, bắt buộc lý do, có audit); `sitex:make-super-admin`.
- `/me` trả thêm `platform_permissions[]` (chỉ là gợi ý cho UI; API luôn kiểm tra lại).

*Dữ liệu*
- Migrations: users/sessions/password_reset_tokens, workspaces/members/invitations, audit_logs, job_runs/system_settings, impersonation_sessions.
- Factories; `DemoSeeder` từ chối chạy khi `APP_ENV=production`.

*Khác*
- Bản dịch `lang/vi` (mặc định), `lang/en`.
- Tests: `tests/Unit` (scope, SSRF, redact, ma trận quyền, kiến trúc), `tests/Feature` (Identity, Workspace, Tenancy, Platform, quy ước API).

**Frontend** (`frontend/`, Vue 3.5 + TS 6.0 + Tailwind 4 + Pinia + vue-router + vue-i18n)

*Dùng chung* (`src/shared`)
- API client (cookie Sanctum + XSRF, tự thử lại khi gặp 419, không lưu token).
- Bộ UI và các state loading/empty/error, `SafeHtml` (DOMPurify).
- Layout sidebar/topbar responsive; trang đăng nhập/2FA dùng chung; `AccountSettings`, `AuditLogTable`.

*`src/apps/customer`*
- Đăng ký, đăng nhập, 2FA, quên/đặt lại mật khẩu, xác minh email, chấp nhận lời mời, onboarding.
- Dashboard, thành viên & lời mời, cài đặt workspace (chuyển quyền sở hữu, xóa), nhật ký, tài khoản (hồ sơ, mật khẩu, 2FA, phiên).
- Workspace switcher, banner phiên hỗ trợ.

*`src/apps/admin`*
- Đăng nhập, bắt buộc bật 2FA, dashboard, workspace (khóa/mở khóa, phiên hỗ trợ), người dùng (khóa/mở khóa), nhân sự nền tảng, nhật ký hệ thống, tài khoản.

*Tests và build*
- Tests `tests/*.spec.ts`: API client, helper phân quyền, SafeHtml/EmptyState/ErrorState.
- Build ra `dist/customer/index.html`, `dist/admin/index.html`.

**Khác**
- `scripts/check.sh`, `scripts/check.ps1`, `scripts/e2e/*`.
- `.github/workflows/ci.yml` (chưa chạy — chưa có repo).
- Placeholder `wordpress-plugin/`.
- Tài liệu: README (cài đặt dev Windows/Linux), API-CONTRACTS (đường dẫn auth thực tế, quy tắc tenant), DATABASE, SECURITY, DEPLOYMENT, ROADMAP, CLAUDE.md.

## 3. Lệch so với thiết kế Phase 0 (đã cập nhật tài liệu)

- **Đường dẫn auth:** theo Fortify, dưới `/api/v1/auth/...` (ví dụ `/auth/user/password` thay cho `/me/password`).
- **`request_id`:** trả trong header `X-Request-Id`; riêng response lỗi có thêm `error.request_id`.
- **`SESSION_DRIVER=database`:** cần cho màn hình "Phiên đăng nhập".
- **Giới hạn đăng nhập:** 60/giờ theo IP (thay vì 20), để tránh khóa nhầm văn phòng dùng chung IP. Giới hạn 5/phút theo email+IP vẫn giữ nguyên.
- **`idempotency_keys`:** hoãn sang Phase 3, vì Phase 1 chưa có endpoint nào tiêu tốn quota.
- **EDITOR:** xem được danh sách thành viên (theo ma trận quyền `members.view`) nhưng không có thao tác quản lý; menu Cài đặt và Nhật ký bị ẩn.

## 4. Lỗi tồn tại / rủi ro

1. **Chưa kiểm tra trên MySQL 8.4:** môi trường cloud chặn tải MySQL 8.4, nên mới chạy trên MySQL 8.0.46 và SQLite. CI đã cấu hình MySQL 8.4 nhưng chưa chạy.
2. **Chưa có repo GitHub**, nên CI chưa chạy. Code nằm trong git cục bộ.
3. **Email mới thử qua `MAIL_MAILER=log`**, chưa gửi SMTP/Brevo thật.
4. **Horizon chưa chạy thử** dưới Supervisor. Các job (gửi lời mời) đã test qua queue sync và `TenantJob`.
5. **Chưa cài thử trên Windows:** hướng dẫn trong README mới được kiểm tra trên Linux.
6. **`npm audit`:** 3 cảnh báo mức moderate (`minimatch`/`glob`), chỉ nằm trong công cụ dev (eslint, @vue/test-utils). Bundle production có 0 lỗ hổng.
7. **Bản dịch `en`** mới là khung, chuỗi thiếu sẽ hiển thị tiếng Việt (fallback).
8. **Xóa workspace** chỉ chuyển sang trạng thái `pending_deletion`. Job xóa vĩnh viễn sau thời gian lưu giữ chưa làm (để phase sau).
9. **Responsive:** đã chụp kiểm tra ở 390px và 1280px; 768px chưa chụp riêng.

## 5. Checklist nghiệm thu (theo yêu cầu)

| Tiêu chí | Trạng thái | Bằng chứng |
|---|---|---|
| Đăng ký, đăng nhập, tạo workspace | ✅ | Pest `Identity/*`, `Workspace/WorkspaceTest`; e2e `customer.js` |
| Xác minh email, quên/đặt lại mật khẩu, quản lý phiên | ✅ | `EmailVerificationAndPasswordTest`, `RegistrationAndLoginTest` |
| Workspace switcher, 1 user nhiều tenant | ✅ | e2e (2 workspace, chuyển qua lại) |
| Vai trò platform SUPER_ADMIN/SUPPORT/BILLING_ADMIN | ✅ | `AdminApiTest` (ma trận quyền) |
| Vai trò tenant OWNER/ADMIN/EDITOR/VIEWER, RBAC, policy, middleware | ✅ | `RolePermissionTest`, `MemberTest`, `InvitationTest` |
| Audit log | ✅ | Có test audit trong từng module; e2e audit phiên hỗ trợ |
| Dashboard khách hàng và Super Admin riêng | ✅ | 2 SPA, e2e cả hai |
| Thành viên, lời mời, quyền truy cập | ✅ | API + UI + test |
| Migration, factory, seeder; demo chỉ ở dev | ✅ | `DemoSeeder` từ chối production (có test) |
| Tenant context xác định server-side | ✅ | `ResolveWorkspace`; `workspace_id` trong body → 422 (có test) |
| Tenant A không đọc/sửa được tenant B (IDOR) | ✅ | `CrossTenantIsolationTest` tự liệt kê **mọi** route tenant → 404; e2e trang 404 |
| Kiểm tra quyền ở API, truy vấn DB và job | ✅ | Policy + scope fail-closed (`WorkspaceScopeTest`) + `TenantJobTest` |
| Có tests và tài liệu API | ✅ | 112 Pest + 16 Vitest + 2 e2e; `docs/API-CONTRACTS.md` |
| Không có module AI | ✅ | Không có code AI; dashboard chỉ ghi "Sắp ra mắt" |
| Migration trên MySQL 8.4 | ⚠️ | Đã chạy trên 8.0.46; 8.4 chờ CI/server |

## 6. Tài khoản demo (chỉ dev, sau `migrate --seed --seeder=DemoSeeder`)
Mật khẩu chung `SiteXDemo2026`:
- `owner@`, `admin@`, `editor@`, `viewer@demo.sitexpost.test`: vai trò tương ứng trong "Cửa hàng A".
- `other@demo.sitexpost.test`: OWNER của "Agency B".
- `superadmin@`, `support@`, `billing@demo.sitexpost.test`: staff nền tảng, phải bật 2FA khi vào app admin.
