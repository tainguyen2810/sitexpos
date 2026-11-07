# Runbook giám sát và xử lý sự cố — Phase 9

Dành cho người vận hành SiteX Post Cloud trên Ubuntu + aaPanel. Mọi lệnh chạy trong `backend/` bằng user web (`sudo -u www php artisan …`), **không** chạy bằng root.

## 1. Có gì đang theo dõi

| Thành phần | Tần suất | Ghi chú |
|---|---|---|
| `sitex:system:heartbeat` | mỗi phút | Ghi dấu thời gian scheduler. Health báo **Nghiêm trọng** khi cũ hơn 180 giây |
| `sitex:system:monitor` | 5 phút | Chạy System Health cho các mục sự cố, xếp hàng cảnh báo khi trạng thái **xấu đi** (ok → cảnh báo/nghiêm trọng, cảnh báo → nghiêm trọng), lặp lại cảnh báo nghiêm trọng sau cooldown, báo **phục hồi** khi về ok. Đo hạn SSL mỗi giờ khi `APP_URL` là https |
| Admin → Hệ thống → Sức khỏe | khi mở | 16 mục, mỗi mục có tóm tắt, chi tiết và cách sửa |
| `GET https://api.<domain>/up` | uptime monitor bên ngoài | Laravel health route; dùng UptimeRobot/BetterStack… (cảnh báo khi cả máy chủ chết, vì monitor nội bộ cũng chết theo) |
| Horizon dashboard | khi cần | Failed jobs, thời gian chờ queue |
| Log | liên tục | `storage/logs/laravel-YYYY-MM-DD.log`, secret đã được che bởi `SecretRedactor` |

Mục được cảnh báo (`Monitor::ALERTABLE`): application, database, redis, horizon, scheduler, queue, storage, ai_providers, ai_spending, payments, wordpress, backup, ssl. Ba mục `gsc`, `mail`, `security` chỉ hiển thị (thiếu cấu hình là việc của Setup Wizard, không phải sự cố).

## 2. Kênh cảnh báo

Admin → Hệ thống → Cảnh báo:
- **Email**: danh sách nhận (tối đa 10). Để trống = gửi mọi Super Admin. Cần SMTP hoạt động (Setup Wizard bước 4).
- **Telegram**: bot token (chỉ ghi, không đọc lại) + chat id.
- **Cooldown**: 5–1440 phút (mặc định 60, `SITEX_ALERT_COOLDOWN_MINUTES`), tính theo từng cặp mục + mức độ để tránh bão cảnh báo. Thông báo phục hồi luôn được gửi.
- Nút "Gửi thử" chạy trong hàng đợi. Lịch sử cảnh báo (kênh nào gửi được/thất bại) ở cùng trang.

Lưu ý: cảnh báo đi qua hàng đợi. Nếu chính queue/Horizon chết thì cảnh báo không đi được — vì vậy **bắt buộc** có uptime monitor bên ngoài và kiểm tra Horizon trong Supervisor.

## 3. Xử lý theo mục

| Mục | Dấu hiệu | Việc cần làm |
|---|---|---|
| scheduler | "Scheduler chưa chạy / dừng N phút" | aaPanel → Cron: có dòng `* * * * * cd <APP>/backend && php artisan schedule:run >> /dev/null 2>&1` chạy bằng `www`? Chạy tay `php artisan schedule:run` xem lỗi. Sau khi sửa, heartbeat cập nhật trong ≤ 1 phút |
| horizon | "Horizon không hoạt động" / "tạm dừng" | `supervisorctl status sitexpost-horizon`; `supervisorctl restart sitexpost-horizon`; nếu tạm dừng: `php artisan horizon:continue`. Xem `storage/logs/horizon.log` |
| queue | Hàng chờ dài / nhiều job lỗi | Horizon → Failed. Lỗi do provider/WordPress → để workflow tự retry; lỗi code → sửa rồi `php artisan horizon:forget`/retry có chọn lọc. Workflow kẹt: `sitex:workflows:recover` (đã chạy mỗi phút) |
| database | Không kết nối | `systemctl status mysql`, dung lượng đĩa, `max_connections`. Không chạy `migrate:*` khi đang sự cố |
| redis | Không kết nối | `systemctl status redis`; session/cache/queue phụ thuộc Redis → người dùng bị đăng xuất, job dừng |
| storage | Đĩa ≥ 85 % (cảnh báo) / gần đầy (nghiêm trọng) | Xóa log cũ, `sitex:backup:prune`, chuyển bản sao lưu ra ngoài máy chủ, dọn `storage/app/private/media` theo chính sách lưu |
| ai_providers | Không provider nào sẵn sàng / circuit mở | Admin → Nhà cung cấp & mô hình: kiểm khóa; Cấu hình AI: provider đang dùng. Circuit tự half-open sau cooldown. Không tắt circuit breaker |
| ai_spending | Chi phí gần/vượt ngân sách | Admin → Sử dụng AI: xem workspace tiêu nhiều; hạ ngân sách hoặc tạm dừng autonomous. AI **không** được tự đổi ngân sách |
| payments | Không có cổng / webhook bị từ chối | Webhook bị từ chối = chữ ký sai, quá hạn hoặc phát lại. Kiểm secret webhook ở Gói & thanh toán; xem Đối soát. **Không** tự đánh dấu đơn đã trả nếu chưa đối chiếu sao kê |
| wordpress | Nhiều site lỗi kết nối | Thường do site khách (plugin tắt, firewall, SSL). Xem Admin → Publishing; site lỗi kéo dài thì báo khách. Bài đăng có khóa idempotent nên retry không tạo trùng |
| backup | Thất bại / quá 24 giờ / chỉ lưu cục bộ | Admin → Sao lưu xem lỗi; thường là thiếu `SITEX_BACKUP_ENCRYPTION_KEY`, sai `SITEX_MYSQLDUMP_PATH` hoặc hết đĩa. Chạy `sitex:backup:run --verify`. Xem [BACKUP-RESTORE.md](BACKUP-RESTORE.md) |
| ssl | Còn ≤ 14 ngày / hết hạn / http | aaPanel → Website → SSL → gia hạn Let's Encrypt; kiểm cron gia hạn của aaPanel |
| application | `APP_DEBUG` bật, chưa cache cấu hình, sai phiên bản | Sửa `.env`, `php artisan optimize` |

## 4. Mức độ và thời gian phản hồi (đề xuất, chưa có cam kết SLA)

| Mức | Ví dụ | Phản hồi |
|---|---|---|
| Nghiêm trọng | DB/Redis/scheduler/Horizon chết, sao lưu thất bại, SSL hết hạn | Trong giờ làm việc: ngay. Ngoài giờ: theo người trực |
| Cảnh báo | Hàng chờ dài, provider AI lỗi một phần, sao lưu > 24 giờ | Trong ngày làm việc |
| Phục hồi | Mục về ok | Ghi nhật ký vận hành |

## 5. Sau sự cố

1. Ghi nhật ký vận hành: thời gian, mục, nguyên nhân, cách sửa, dữ liệu bị ảnh hưởng.
2. Nếu có thể mất dữ liệu: dừng ghi (`php artisan down`), sao lưu trạng thái hiện tại trước khi sửa.
3. Nếu sự cố liên quan khách (đăng bài lỗi, trừ credit sai): kiểm `sitex:billing:verify-ledger` (chạy hằng ngày 03:15), ledger là append-only — sửa bằng bút toán điều chỉnh, không sửa dòng cũ.

## 6. Đã kiểm chứng / chưa

- Đã có test: chuyển trạng thái → xếp cảnh báo, cooldown bỏ qua cảnh báo trùng, báo phục hồi, health scheduler cũ → nghiêm trọng (`tests/Feature/System/SystemAdminTest.php`).
- Trên bản cài E2E thật: heartbeat + monitor chạy qua `schedule:work`, trang Health hiển thị đủ mục.
- **Chưa thử**: gửi email qua SMTP thật và Telegram thật (cần tài khoản), uptime monitor bên ngoài.
