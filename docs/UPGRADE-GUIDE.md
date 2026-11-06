# Hướng dẫn nâng cấp — Phase 9

Nâng cấp gồm hai phần tách biệt:

1. **Cập nhật mã nguồn** — người vận hành làm qua SSH/aaPanel. Ứng dụng web **không** tự tải, giải nén hay ghi đè mã nguồn của chính nó (tránh biến web thành công cụ chạy lệnh tùy ý).
2. **Nâng cấp cơ sở dữ liệu** — làm từ Admin → Hệ thống → Nâng cấp (Upgrade Wizard) hoặc bằng `scripts/deploy/upgrade.sh`.

## 1. Cách tự động (khuyến nghị): `scripts/deploy/upgrade.sh`

```bash
cd /www/wwwroot/sitexpost
sudo -u www scripts/deploy/upgrade.sh v1.1.0
```

Script dừng ngay nếu chạy bằng root hoặc checkout có thay đổi cục bộ, rồi:
`sitex:backup:run --verify` → `artisan down` → `git checkout <tag>` → `composer install --no-dev` → `sitex:upgrade:check` (mã thoát 2 = có migration phá dữ liệu hoặc thiếu yêu cầu → **dừng**, không migrate) → `migrate --force` → `sitex:upgrade:check --record` (ghi phiên bản + audit) → `optimize` → `horizon:terminate` → `artisan up` → `sitex:system:monitor`.
Khi lỗi, script in lệnh quay lại mã cũ và để nguyên chế độ bảo trì nếu lỗi xảy ra sau `down`.

Frontend: build bản mới (`npm ci && npm run build` với `VITE_API_BASE_URL`) hoặc dùng `frontend/dist` trong gói phát hành.

## 2. Cách thủ công với Upgrade Wizard

1. SSH: `git fetch --tags && git checkout v1.1.0 && cd backend && composer install --no-dev -o && php artisan optimize`.
2. Admin → Hệ thống → Nâng cấp hiển thị:
   - phiên bản trong CSDL và phiên bản mã nguồn, ghi chú thay đổi từ `backend/CHANGELOG.md`;
   - migration chờ chạy, đánh dấu **"Có thể làm mất dữ liệu"** nếu `up()` có `drop`, `dropColumn`, `renameColumn`, `->change()`, `truncate`, `DELETE`/`DROP` SQL;
   - yêu cầu hệ thống chưa đạt; bản sao lưu gần nhất (trong 24 giờ chưa, đã kiểm tra chưa).
3. Bấm "Bắt đầu nâng cấp", nhập lại mật khẩu, chọn "Sao lưu trước khi nâng cấp". Nút bị khóa khi có blocker.
4. Job `RunUpgrade` (hàng đợi): sao lưu (nếu chọn) → từ chối nếu có migration phá dữ liệu → `migrate --force` → dữ liệu hệ thống mặc định (`DatabaseSeeder`, idempotent) → kiểm tra sức khỏe → ghi `system.version` → audit `system.upgraded` (lỗi: `system.upgrade_failed`). Lỗi → trạng thái `failed`, gửi cảnh báo, ghi lỗi (đã che secret).

API: `GET /api/admin/v1/system/upgrade`, `POST /api/admin/v1/system/upgrade` (`password`, `backup_first`; 202). Mã lỗi: `nothing_to_upgrade` 422, `destructive_migrations` 409, `requirements_not_met` 409, `backup_required` 409, `backup_not_configured` 409, `upgrade_in_progress` 409, sai mật khẩu 422.

## 3. Migration phá dữ liệu

Không bao giờ chạy từ web. Quy trình:
1. Đọc ghi chú phát hành; xác nhận cột/bảng bị xóa không còn được dùng bởi phiên bản **đang chạy** (quy ước: thêm ở bản N, ngừng dùng ở bản N, xóa ở bản N+1).
2. `php artisan sitex:backup:run --verify` và `sitex:backup:verify --restore-test=<db>_restore_test`.
3. `php artisan down`, `php artisan migrate --force` qua SSH, kiểm tra, `php artisan up`.
4. Người phê duyệt: Super Admin, ghi vào nhật ký vận hành.

## 4. Không bao giờ

- Không đổi `APP_KEY` khi nâng cấp (secret trong DB mã hóa bằng khóa này).
- Không chạy `migrate:fresh`, `db:wipe`, `migrate:rollback` trên production.
- Không chạy trình cài đặt lại trên hệ thống đã cài (nó bị khóa).

Quay lại phiên bản cũ: [ROLLBACK-PLAN.md](ROLLBACK-PLAN.md).
