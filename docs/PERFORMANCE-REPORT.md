# Báo cáo hiệu năng và độ tin cậy — Phase 9

Mọi số liệu dưới đây là **đo thật** trên máy phát triển (container 4 vCPU, 15 GB RAM, MySQL 8.0.46 mặc định với `innodb_buffer_pool_size` 128 MB, PHP 8.3 CLI, không OPcache), ngày 06/11/2026. **Không** phải số của production, **không** có load test đồng thời, **không** gọi AI thật. Không suy ra năng lực phục vụ (RPS) từ các số này.

## 1. Dữ liệu đo

`php artisan migrate:fresh --seed --seeder=DemoSeeder` trên MySQL (APP_ENV=local), mất 78 giây. Workspace demo có 3 site, 365 ngày dữ liệu Search Console: `search_query_metrics` 148.056 dòng, `search_page_metrics` 33.764 dòng, `search_performance_daily` 5.475 dòng.

## 2. Độ trễ phía máy chủ (in-process)

Công cụ: `scripts/perf/bench-inprocess.php` — khởi động ứng dụng một lần, gửi request qua HTTP kernel với tư cách `owner@demo`, 2 lần khởi động + 20 lần đo, xóa cache giữa các lần (đo trường hợp không có cache). Không gồm mạng, Nginx, PHP-FPM.

| Endpoint | p50 ms | p95 ms | Số truy vấn |
|---|---:|---:|---:|
| `GET /api/v1/me` | 3,9 | 4,5 | 2 |
| `…/sites` | 6,2 | 7,5 | 3 |
| `…/analytics/overview` | **701** | **1.002** | 11 |
| `…/analytics/queries` | **578** | **864** | 5 |
| `…/analytics/pages` | 134 | 176 | 5 |
| `…/analytics/opportunities` | 22,8 | 31,1 | 3 |
| `…/analytics/decay` | 8,1 | 12,9 | 3 |
| `…/analytics/reports` | 5,5 | 6,4 | 3 |
| `…/agent/runs` | 7,1 | 7,9 | 3 |
| `…/billing/credits` | 6,5 | 8,4 | 4 |
| `…/billing/subscription` | 11,2 | 15,5 | 13 |
| `…/billing/usage` | 10,5 | 19,6 | 9 |
| `…/writer/articles` | 9,6 | 12,0 | 3 |
| `…/research/campaigns` | 8,1 | 9,3 | 3 |
| `…/media/assets` | 51,9 | 59,5 | **33** |

## 3. Phát hiện

| # | Mức | Phát hiện | Bằng chứng | Đề xuất |
|---|---|---|---|---|
| PERF-1 | P2 | `analytics/overview` và `analytics/queries` gom nhóm toàn workspace trên `search_query_metrics` mất ~290 ms + ~250 ms mỗi truy vấn với 148k dòng; sẽ tăng tuyến tính theo dữ liệu (GSC giữ 16 tháng, site lớn có hàng triệu dòng) | Log truy vấn chậm; `EXPLAIN`: MySQL chọn index khóa ngoại `(workspace_id, site_id)`, quét ~73k dòng, `Using temporary; Using filesort` | Thử nghiệm: thêm index `(workspace_id, search_type, date)` + `FORCE INDEX` → 57–81 ms (giảm ~5 lần). Không có `FORCE INDEX` thì optimizer vẫn chọn index cũ, nên **chưa** đưa vào code; làm ở bản sau cùng với bảng tổng hợp theo ngày/tuần hoặc cache 5–15 phút cho dashboard |
| PERF-2 | P3 | `media/assets` chạy 33 truy vấn cho một trang (N+1) | Đếm truy vấn | Eager-load quan hệ trong resource |
| PERF-3 | P3 | Test làm rơi 3.361 file vào `storage/app/private/media` thật, làm sao lưu `storage/app` chậm từ ~2 s lên ~49 s | Thời gian test BackupTest | **Đã sửa**: test dùng disk giả (`tests/TestCase.php`) |
| PERF-4 | Thông tin | Rate limiter `throttle:api` trả 429 khi một người gọi > giới hạn trong 1 phút — đúng thiết kế, nhưng màn hình analytics gọi nhiều endpoint song song | Bench lần 1 bị 429 | Theo dõi khi có người dùng thật; nâng giới hạn cho nhóm GET nếu cần |

## 4. Độ tin cậy (đã kiểm)

- Toàn bộ 521 test backend chạy trên **MySQL 8.0.46** (song song 4 tiến trình): 520 pass; 1 lỗi là test được thêm giữa lúc chạy (lệnh chưa đăng ký), pass khi chạy lại. Chạy trên MySQL tìm ra một lỗi thật mà SQLite che mất (tên cột sai trong health "Thanh toán").
- Hàng đợi tách theo loại (`Queues::ALL`), workflow tự khôi phục (`sitex:workflows:recover`), mọi lệnh định kỳ đều có trong scheduler (đã đối chiếu danh sách lệnh `sitex:*` với `routes/console.php`; chỉ các lệnh thủ công là không lên lịch).
- Scheduler heartbeat mỗi phút; health báo "Nghiêm trọng" khi heartbeat cũ hơn 180 giây; monitor gửi cảnh báo khi trạng thái xấu đi và khi phục hồi, có cooldown.
- Sao lưu mã hóa và khôi phục thử trên MySQL thật: xem [BACKUP-RESTORE.md](BACKUP-RESTORE.md) §6.

## 5. Thiết kế load test (chưa chạy)

`scripts/perf/k6-smoke.js`: chỉ chạy trên **staging**, provider AI đặt Fake, 0 → 20 → 50 người dùng ảo trong 9 phút, ngưỡng lỗi < 1 %, p95 < 300 ms cho endpoint nhẹ và < 1,5 s cho analytics. Cần: máy staging cấu hình giống production (PHP-FPM, OPcache, Redis cache/session), dữ liệu demo, cookie phiên của một tài khoản test. **Chưa chạy** vì không có môi trường staging; không có số liệu tải đồng thời nào trong báo cáo này.

## 6. Khuyến nghị cấu hình production

OPcache bật (`opcache.validate_timestamps=0` sau deploy), `php artisan optimize`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `innodb_buffer_pool_size` ≈ 50–70 % RAM của máy chỉ chạy MySQL, Horizon theo `config/horizon.php`, theo dõi `analytics/*` p95 sau khi có khách thật.
