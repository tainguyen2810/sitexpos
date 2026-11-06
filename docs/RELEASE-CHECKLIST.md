# Checklist phát hành — Phase 9

Dùng cho mỗi bản phát hành (tag `vX.Y.Z`). Đánh dấu từng mục và lưu bản đã đánh dấu vào nhật ký vận hành. Không bỏ mục nào; mục không áp dụng ghi "N/A" kèm lý do.

## A. Trước khi tạo tag (máy phát triển / CI)

- [ ] Nhánh phát hành đã merge vào `main` qua PR có review (không merge thẳng).
- [ ] `scripts/check.sh` xanh: Pint, Larastan, Pest, frontend typecheck/lint/test/build, `check:no-demo`, plugin PHP 7.4 lint. Dán kết quả vào PR.
- [ ] CI GitHub Actions xanh, gồm Pest trên **MySQL 8.4**.
- [ ] `config/sitex.php` → `version` đã tăng; `backend/CHANGELOG.md` có mục `## [X.Y.Z]` (build-release.sh từ chối nếu thiếu).
- [ ] Migration mới: chỉ **bổ sung**. Chạy `php artisan sitex:upgrade:check` trên bản sao DB staging → mã thoát 0, không có "Có thể làm mất dữ liệu". Migration phá dữ liệu (nếu thật sự cần) tách sang bản sau theo UPGRADE-GUIDE §3.
- [ ] Không có Model ID AI chưa xác minh, không có giá hardcode, không có secret trong mã (`git grep -nE "sk-|AIza|BEGIN PRIVATE"` sạch).
- [ ] Plugin WordPress: nếu đổi giao thức, tăng phiên bản plugin và `min_plugin_version`; build `sitex-post-X.Y.Z.zip`.
- [ ] Tài liệu cập nhật cùng thay đổi (CLAUDE.md, ARCHITECTURE, DATABASE, SECURITY, API-CONTRACTS, ROADMAP khi liên quan).

## B. Đóng gói

- [ ] `scripts/release/build-release.sh https://api.<domain> https://app.<domain>` trên **máy build** (không phải production). Kết quả `dist/release/sitex-post-X.Y.Z.tar.gz` + `.sha256` + zip plugin.
- [ ] Kiểm gói: không có `.env`, `storage/` dữ liệu, `node_modules`, `tests`, `src/demo`.
- [ ] Tạo tag `vX.Y.Z` (người có quyền tạo, không phải AI).

## C. Staging

- [ ] Staging cấu hình giống production (PHP-FPM 8.4, OPcache, MySQL 8.4, Redis, Horizon, Supervisor).
- [ ] Nâng cấp staging từ bản đang chạy trên production bằng `scripts/deploy/upgrade.sh vX.Y.Z`.
- [ ] Admin → Hệ thống → Sức khỏe: không có mục Nghiêm trọng.
- [ ] Chạy E2E: `scripts/e2e/customer.js`, kịch bản admin, `wordpress-plugin/tests/e2e/run-e2e.mjs` với WordPress thật.
- [ ] Một bài viết đầu-cuối với AI thật ngân sách nhỏ (nếu bản phát hành đụng Writer/AI Gateway); đối chiếu credit.
- [ ] Load test `scripts/perf/k6-smoke.js` khi bản phát hành đụng analytics/hiệu năng (chỉ staging, provider AI Fake).
- [ ] Diễn tập rollback mục 2 (ROLLBACK-PLAN) cho bản phát hành lớn.

## D. Production (người vận hành, có Super Admin phê duyệt)

- [ ] Thông báo khách về thời gian bảo trì (nếu có migration nặng).
- [ ] Bản sao lưu gần nhất đã kiểm (`sitex:backup:verify`) và có bản ngoài máy chủ.
- [ ] `sudo -u www scripts/deploy/upgrade.sh vX.Y.Z` (hoặc các bước tay ở UPGRADE-GUIDE §2). **Không** chạy bằng root, không `migrate:fresh`, không đổi `APP_KEY`.
- [ ] Frontend `dist` mới đã đặt vào web root của app/admin.
- [ ] Sau nâng cấp: Admin → Nâng cấp hiện phiên bản mới; audit có `system.upgraded`.
- [ ] Sức khỏe hệ thống: không có Nghiêm trọng sau 15 phút; Horizon chạy; scheduler heartbeat < 180 giây.
- [ ] Smoke tay: đăng nhập khách + admin, mở dashboard, tạo một tác vụ nhỏ, kiểm một site WordPress thật.
- [ ] Plugin WordPress mới (nếu có) đã đặt vào thư mục tải của Setup Wizard bước 9.

## E. Sau phát hành (24–72 giờ)

- [ ] Theo dõi cảnh báo, failed jobs, p95 `analytics/*`, chi phí AI.
- [ ] Không có lỗi tăng bất thường trong log.
- [ ] Ghi kết quả phát hành và thời gian ngừng dịch vụ vào nhật ký vận hành.
- [ ] Nếu lỗi nghiêm trọng: [ROLLBACK-PLAN.md](ROLLBACK-PLAN.md).
