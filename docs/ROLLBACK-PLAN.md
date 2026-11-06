# Kế hoạch quay lại phiên bản cũ (Rollback) — Phase 9

Áp dụng khi một bản phát hành mới gây lỗi trên production. Người thực hiện: người vận hành qua SSH, Super Admin phê duyệt. Ứng dụng web **không** có nút rollback (đây là thao tác có thể làm mất dữ liệu).

## 0. Quyết định nhanh

| Tình huống | Cách quay lại |
|---|---|
| Lỗi chỉ ở frontend | Thay `frontend/dist` bằng bản của tag cũ (có trong gói phát hành cũ). Không đụng DB |
| Lỗi ở backend, bản mới **không** có migration | Mục 2 (đổi mã nguồn) |
| Bản mới có migration **bổ sung** (thêm bảng/cột, quy ước mặc định) | Mục 2: mã cũ vẫn chạy được trên schema mới vì migration bổ sung tương thích ngược. Giữ nguyên DB |
| Dữ liệu bị hỏng sau nâng cấp, hoặc migration không tương thích ngược | Mục 3 (khôi phục bản sao lưu trước nâng cấp) |

Quy ước phát hành bắt buộc để rollback an toàn: **thêm ở bản N, ngừng dùng ở bản N, xóa ở bản N+1** (UPGRADE-GUIDE §3). Upgrade Wizard và `sitex:upgrade:check` từ chối migration phá dữ liệu, nên một lần nâng cấp chuẩn luôn để lại schema mà mã cũ đọc được.

## 1. Trước mọi rollback

```bash
cd /www/wwwroot/sitexpost/backend
sudo -u www php artisan sitex:backup:run --verify     # chụp trạng thái hiện tại, kể cả khi đang lỗi
sudo -u www php artisan down --retry=60
cat storage/logs/laravel-$(date +%F).log | tail -200   # lưu lại bằng chứng lỗi
```

## 2. Quay lại mã nguồn (giữ DB)

```bash
cd /www/wwwroot/sitexpost
sudo -u www git checkout <tag-cũ>
cd backend && sudo -u www composer install --no-dev -o
sudo -u www php artisan optimize
sudo -u www php artisan horizon:terminate       # Supervisor khởi động lại worker với mã cũ
sudo -u www php artisan up
sudo -u www php artisan sitex:system:monitor
```

- `system.version` trong DB vẫn là bản mới; Upgrade Wizard sẽ hiện "phiên bản CSDL mới hơn mã nguồn". Đây là trạng thái chấp nhận được tạm thời; khi đã sửa lỗi, phát hành bản vá (N+1) rồi nâng cấp bình thường.
- Plugin WordPress: Cloud chỉ yêu cầu `min_plugin_version`; quay lại Cloud không cần gỡ plugin trên site khách.

## 3. Khôi phục bản sao lưu trước nâng cấp

Chỉ khi dữ liệu hỏng hoặc schema không tương thích. **Mọi thay đổi phát sinh sau thời điểm sao lưu sẽ mất** (đơn thanh toán, credit, bài đã đăng): phải đối soát lại thủ công.

1. Xác định bản sao lưu do `upgrade.sh` hoặc Upgrade Wizard tạo ngay trước nâng cấp (Admin → Sao lưu hoặc `ls -lt storage/app/backups`).
2. Làm theo [BACKUP-RESTORE.md](BACKUP-RESTORE.md) §4: giải mã → kiểm SHA-256 → nạp vào **database mới** (`sitexpost_restored`) → đổi `DB_DATABASE` → khôi phục `storage/app`. Không ghi đè database đang có (giữ lại để đối soát).
3. Checkout tag khớp `manifest.version` của bản sao lưu, `composer install`, `optimize`, `horizon:terminate`, `up`.
4. Đối soát: so sánh đơn thanh toán (`payment_orders`), bút toán credit (`credit_ledger_entries`) và lịch sử đăng bài giữa database cũ và database đã khôi phục cho khoảng thời gian bị mất; ghi bổ sung bằng bút toán điều chỉnh, không sửa dòng ledger cũ. Chạy `sitex:billing:verify-ledger`.
5. Bài đã đăng lên WordPress trong khoảng bị mất vẫn nằm trên WordPress; Publisher đối chiếu bằng `/posts/lookup` trước khi ghi nên không tạo bài trùng, nhưng cần rà soát trạng thái bài trong Cloud.

## 4. Không bao giờ

- `migrate:rollback`, `migrate:fresh`, `db:wipe` trên production (migration `down()` không được kiểm thử như đường quay lại và có thể xóa dữ liệu).
- Đổi `APP_KEY` khi quay lại (secret trong DB mã hóa bằng khóa này).
- Xóa database đang có trước khi đối soát xong.

## 5. Diễn tập

Mỗi bản phát hành lớn: thử mục 2 và mục 3 trên staging với dữ liệu thật đã ẩn danh trước khi nâng cấp production. Ghi thời gian thực hiện để ước tính thời gian ngừng dịch vụ.

## 6. Đã kiểm chứng / chưa

- Đã làm thật (MySQL 8.0.46, bản cài E2E): sao lưu → giải mã → nạp vào database mới → 160 bảng, Super Admin còn nguyên (BACKUP-RESTORE §6).
- **Chưa** diễn tập rollback mã nguồn giữa hai bản phát hành thật (hiện chỉ có 1.0.0).
