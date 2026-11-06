# Sao lưu và khôi phục — Phase 9

## 1. Bản sao lưu gồm gì

Một file `sitexpost-<thời gian>.sxpbk` trong `SITEX_BACKUP_PATH` (mặc định `backend/storage/app/backups`, quyền 0600), bên trong là một tar đã mã hóa chứa:

| File | Nội dung |
|---|---|
| `database.sql` | `mysqldump --single-transaction --routines --triggers` (mật khẩu truyền qua `MYSQL_PWD`, không nằm trên dòng lệnh). SQLite: bản sao file |
| `storage.tar` | `storage/app` (media, logo, file người dùng), trừ thư mục backup và thư mục installer |
| `env` | Bản sao `.env` (có `APP_KEY` — cần để giải mã secret trong DB) |
| `manifest.json` | Phiên bản, driver DB, SHA-256 và kích thước từng file |

**Mã hóa**: AES-256-GCM theo khối 1 MB, mỗi khối có tag xác thực, khối kết thúc riêng để phát hiện file bị cắt. Định dạng `SXPBK1`. Khóa là `SITEX_BACKUP_ENCRYPTION_KEY` (32 byte, base64), **khác `APP_KEY`**: lộ file backup không đủ để đọc, mất máy chủ không mất khóa nếu bạn đã lưu khóa ở nơi khác.

## 2. Thiết lập

```bash
sudo -u www php artisan sitex:backup:key          # in ra SITEX_BACKUP_ENCRYPTION_KEY=base64:...
# 1) thêm dòng đó vào backend/.env   2) lưu MỘT BẢN NGOÀI máy chủ (password manager / giấy niêm phong)
sudo -u www php artisan optimize
```

Tùy chọn trong `.env`: `SITEX_BACKUP_PATH`, `SITEX_BACKUP_RETENTION_DAYS` (14), `SITEX_BACKUP_DISK` (disk trong `config/filesystems.php`, ví dụ `s3`, để có **bản ngoài máy chủ** dưới `sitexpost-backups/`), `SITEX_MYSQLDUMP_PATH`, `SITEX_MYSQL_PATH`, `SITEX_BACKUP_TIMEOUT`.

Lịch: Admin → Hệ thống → Sao lưu → bật "sao lưu hằng ngày" và chọn giờ. Scheduler gọi `sitex:backup:run --scheduled --verify` mỗi 15 phút, lệnh chỉ chạy khi đã tới giờ và hôm nay chưa có bản thành công. `sitex:backup:prune` chạy 04:30 hằng ngày, xóa bản cũ hơn hạn giữ nhưng **luôn giữ bản thành công mới nhất**.

## 3. Lệnh

```bash
php artisan sitex:backup:run [--verify]                       # tạo bản sao lưu
php artisan sitex:backup:verify [id] [--restore-test=sitexpost_restore_test]
php artisan sitex:backup:decrypt <file.sxpbk> <out.tar>        # giải mã để khôi phục tay
php artisan sitex:backup:prune
```

`verify` kiểm tra: SHA-256 của file, giải mã toàn bộ (tag từng khối), manifest, SHA-256 từng file bên trong, dump có `CREATE TABLE`. `--restore-test` nạp dump vào một database **nháp** (tên bắt buộc kết thúc `_restore_test` và khác database đang chạy), đếm bảng, rồi xóa database nháp. User MySQL cần quyền trên database nháp đó.

Trang Admin → Sao lưu: tạo bản mới (nhập lại mật khẩu), kiểm tra một bản, xem trạng thái lưu ngoài máy chủ. **Không có nút khôi phục trên web** — khôi phục là thao tác phá dữ liệu, chỉ làm qua SSH.

## 4. Khôi phục (SSH, người vận hành tự làm)

> Đọc hết trước khi làm. Khôi phục **ghi đè** dữ liệu hiện có. Luôn tạo một bản sao lưu của trạng thái hiện tại trước (kể cả khi đang lỗi).

```bash
cd /www/wwwroot/sitexpost/backend
sudo -u www php artisan sitex:backup:run                         # chụp trạng thái hiện tại (nếu còn chạy được)
sudo -u www php artisan down
sudo -u www php artisan sitex:backup:decrypt storage/app/backups/<file>.sxpbk /tmp/restore.tar
mkdir /tmp/restore && tar -xf /tmp/restore.tar -C /tmp/restore
cat /tmp/restore/manifest.json                                   # kiểm phiên bản, driver
(cd /tmp/restore && sha256sum database.sql storage.tar)          # so với manifest

# Database: khuyến nghị nạp vào database MỚI rồi đổi DB_DATABASE, thay vì ghi đè database cũ
mysql -u<user> -p -e "CREATE DATABASE sitexpost_restored CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u<user> -p sitexpost_restored < /tmp/restore/database.sql

# File
tar -xf /tmp/restore/storage.tar -C /tmp/restore/storage && rsync -a /tmp/restore/storage/app/ storage/app/

# .env: chỉ lấy APP_KEY từ /tmp/restore/env nếu máy chủ mới chưa có .env đúng.
# KHÔNG đổi APP_KEY trên hệ thống đang có dữ liệu mã hóa bằng khóa khác.
# Sửa DB_DATABASE=sitexpost_restored trong .env, rồi:
sudo -u www php artisan optimize && sudo -u www php artisan horizon:terminate
sudo -u www php artisan up
shred -u /tmp/restore.tar; rm -rf /tmp/restore
```

Khôi phục sang máy chủ mới: cài mã nguồn cùng phiên bản (`manifest.version`), **không** chạy trình cài đặt, đặt `.env` từ bản sao lưu (giữ nguyên `APP_KEY`), rồi làm các bước trên. Nếu phiên bản mã mới hơn bản sao lưu: `php artisan sitex:upgrade:check` rồi `migrate --force` (chỉ migration bổ sung).

## 5. Diễn tập

Mỗi quý: `sitex:backup:verify --restore-test=sitexpost_restore_test` trên production (không đụng database đang chạy) và một lần khôi phục đầy đủ trên staging. Ghi kết quả vào nhật ký vận hành.

## 6. Đã kiểm chứng (06/11/2026)

- `tests/Feature/System/BackupTest.php` (SQLite): mã hóa/giải mã 2,5 MB nhiều khối, phát hiện sửa 1 byte, phát hiện file bị cắt, sai khóa; tạo bản sao lưu 0600 không chứa chuỗi rõ; verify; decrypt CLI ra tar đọc được; file bị sửa → verify thất bại; thiếu khóa → bản ghi thất bại và health "Nghiêm trọng"; từ chối restore-test vào DB thật; prune giữ bản mới nhất.
- **MySQL 8.0.46 thật** (bản cài E2E): `sitex:backup:run --verify` → 332 KB, mọi kiểm tra OK; `sitex:backup:verify --restore-test=sitex_e2e_restore_test` → 160 bảng nạp vào DB nháp rồi DB nháp bị xóa; quy trình §4 làm tay (decrypt → tar → nạp `database.sql` vào DB mới) → 160 bảng, Super Admin còn nguyên.
- Chưa thử: đẩy bản sao lưu lên disk ngoài máy chủ thật (S3/SFTP) — cần thông tin tài khoản lưu trữ.
