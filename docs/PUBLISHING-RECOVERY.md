# Publishing Recovery (Phase 6)

Cách hệ thống đăng bài phục hồi khi lỗi mà **không đăng trùng, không đăng trễ ngoài ý muốn, không lặp vô hạn**.

## 1. Phân loại lỗi connector

| Mã lỗi (ConnectorException) | Có thể đã ghi? | Xử lý |
|---|---|---|
| `unreachable`, `site_error` (timeout, 5xx) | **Có thể** | Đối soát (§2) rồi mới thử lại |
| `rate_limited` | Không | Thử lại với backoff |
| `conflict` (409 `sitex_conflict`) | Không | Đọc state có ký, mở xung đột, job → `UPDATE_PENDING` |
| lỗi xác thực / chữ ký / 4xx khác | Không | `FAILED` ngay, không thử lại |

Mỗi lần thử ghi một dòng `publishing_attempts` (attempt_no, operation `create`/`update`/`state_check`/`reconcile`, outcome `succeeded`/`reconciled`/`conflict`/`skipped` (cổng chặn)/`failed`/`uncertain`, http_status, payload_hash, duration_ms).

## 2. Đối soát sau timeout

Khi kết quả không rõ, `ExecutePublish::connectorFailure()` gọi `GET /posts/lookup` theo `idempotency_key` của mapping:

- Tìm thấy bài **do chính SiteX ghi** (`written_hash == content_hash`) và sửa đổi trong khoảng thời gian của lần thử → ghi nhận `PUBLISHED` với outcome `reconciled`; **không gửi lại**.
- Tìm thấy nhưng không khớp → mapping nhận `wp_post_id` (adopt) để lần sau là cập nhật có kiểm tra xung đột, không phải tạo mới.
- Không tìm thấy → thử lại.

Thêm vào đó, plugin chống trùng theo khóa idempotency (kèm khóa tạm `sitex_in_progress`), nên kể cả hai yêu cầu cùng tới WordPress vẫn chỉ có một bài.

## 3. Backoff & giới hạn

`config('sitex.publishing.backoff_minutes') = [1, 5, 15, 60, 180]` phút theo số lần thử. Hết `max_attempts` (chính sách website, mặc định 5) → `FAILED`, audit `publishing.failed`. Không có vòng lặp vô hạn; người dùng bấm **Thử lại** (`POST /jobs/{id}/retry`, quyền `content.publish`) để bắt đầu lại — mọi cổng được kiểm tra lại.

## 4. Worker chết / mất message queue

- Claim nguyên tử bằng `locked_until` (mặc định 300 s) → hai worker không gửi cùng job.
- `sitex:publishing:dispatch` (mỗi phút) gửi lại job `PUBLISHING` có `next_attempt_at` đã tới và khóa đã hết hạn. Gửi trùng vô hại nhờ claim.
- `RunPublishingJob` có `tries = 1`; việc thử lại do `ExecutePublish` lên lịch (không để queue tự retry mù).

## 5. Lỡ lịch (missed schedule)

Khi dispatcher chạy trễ (server tắt, queue dừng): lịch quá hạn **trong** `missed_grace_minutes` (mặc định 180) vẫn được đăng; quá hạn **ngoài** khoảng đó → lịch `missed`, job `FAILED` với `missed_schedule`, audit `publishing.schedule_missed`. Hệ thống **không tự đăng muộn**: người dùng đổi lịch hoặc thử lại.

## 6. Nội dung đổi sau khi duyệt

Lúc thực thi, bản render phải khớp `content_hash` chốt khi duyệt và phiên bản bài phải là phiên bản đã duyệt. Khác → job về `READY_FOR_REVIEW` (`content_changed_since_approval`), cần duyệt lại.

## 7. Batch

Tạm dừng batch → job của batch không được claim/dispatch. Tiếp tục → dispatcher xử lý tiếp. Hủy → các job chưa gửi chuyển `CANCELLED` (lịch chờ bị hủy); job đang gửi dở được để hoàn tất, không bao giờ bị hủy nửa chừng. Lỗi một bài không ảnh hưởng bài khác.

## 8. Vận hành

```bash
php artisan sitex:publishing:dispatch     # thủ công (bình thường chạy qua scheduler mỗi phút)
php artisan sitex:publishing:auto         # chỉ website đã bật auto-publish
```

Cần `php artisan schedule:work` (hoặc cron `schedule:run`) và worker queue đang chạy. Super Admin xem job lỗi / xung đột ở trang Publishing Admin; kill switch `publishing_enabled=false` dừng mọi lần đăng ở cổng `policy`.
