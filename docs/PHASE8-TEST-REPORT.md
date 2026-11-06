# Phase 8: Báo cáo kiểm thử

- **Ngày chạy**: 06/11/2026
- **Nhánh**: `claude/project-thread-pkiwke` (tách từ `main` `de6a4c0`)
- **Môi trường**: Linux, PHP 8.3.6, Node 22, sqlite (test), không có credentials Google/AI thật

Mọi số liệu dưới đây là **kết quả thật đã chạy**. Mục nào chưa chạy được đều ghi rõ.

## 1. `scripts/check.sh` (toàn bộ, lần chạy cuối)

```
==> Backend: Pint
{"tool":"pint","result":"passed"}
==> Backend: Larastan
 [OK] No errors
==> Backend: Pest
  Tests:    475 warnings (3594 assertions)
OK, but some tests were skipped!
Tests: 475, Assertions: 3594, Skipped: 1.
==> Frontend: lint
==> Frontend: typecheck
==> Frontend: unit tests
 Test Files  11 passed (11)
      Tests  93 passed (93)
==> Frontend: build
✓ built in 1.78s
✓ built in 1.13s
==> Frontend: no demo code in production build
assert-no-demo: OK – no demo code in dist/
==> WordPress plugin: lint, PHP 7.4 check, signer test, translations, zip
23 files checked, 0 problems
126/126 translated
== built dist/sitex-post-1.1.0.zip (60K)
All checks passed.
exit=0
```

“475 warnings” là một cảnh báo môi trường lặp ở mọi test: `file_get_contents(.../backend/.env)` vì máy kiểm thử không có file `.env` (test lấy cấu hình từ `phpunit.xml`). 1 test bị skip có từ trước Phase 8. Trước Phase 8 (main `de6a4c0`): 429 test backend; Phase 8 thêm 46 test backend và 1 file Vitest (9 test).

## 2. Test Phase 8 (backend)
| File | Nội dung |
|---|---|
| `tests/Unit/Analytics/AnalyticsEngineTest.php` (7) | CTR từ tổng, vị trí có trọng số; phân loại với dữ liệu tối thiểu / mới đăng / ngưỡng cấu hình; decay cần đủ lịch sử, trừ hao đột biến và mùa vụ; health chỉ từ thành phần có dữ liệu (không bịa); experiment trước/sau có quy tắc dữ liệu tối thiểu; chuẩn hóa URL không đoán; **không gắn “mồ côi” khi chưa biết nội dung** |
| `tests/Unit/Agent/AgentPolicyEngineTest.php` (6) | Danh sách tool đóng (không SQL/shell/HTTP/billing/phân quyền); deny đầu tiên thắng; mức 0–3, mức 3 chỉ khi người bật; khung giờ theo múi giờ site; ngân sách, giới hạn ngày/tháng, gói, quyền principal, allow/forbid; lịch theo múi giờ không trôi |
| `tests/Feature/Analytics/SearchConsoleSyncTest.php` (5) | OAuth không hỏi mật khẩu, chỉ `sites.manage` cấu hình; chọn property + đồng bộ mọi chiều idempotent, phân trang, checkpoint; backoff khi quota, chạy tiếp không trùng; Google thu hồi token → `reauth_required`, disconnect thu hồi tại Google; scheduler chạy hai lần chỉ đồng bộ một lần |
| `tests/Feature/Analytics/AnalysisApiTest.php` (4) | Phân loại/decay/cơ hội có bằng chứng, chạy lại không nhân đôi; dashboard có bộ lọc + ghi chú GSC; editor duyệt, viewer chỉ đọc; experiment không gọi là A/B |
| `tests/Feature/Analytics/ContentRefreshTest.php` (5) | Refresh qua Writer → chờ người duyệt → đăng; cổng Publisher fail → giữ chờ; **bài sửa trên WordPress → conflict, không ghi đè**; chỉ bài đã duyệt + đã đăng; hủy |
| `tests/Feature/Analytics/ReportCopilotTest.php` (3) | Báo cáo chỉ từ dữ liệu lưu, CSV an toàn (chống formula injection), email chỉ thành viên đang hoạt động; Copilot chỉ dữ liệu tenant, tách dữ kiện/suy luận/đề xuất, không hành động; AI chỉ chạy trong queue |
| `tests/Feature/Agent/AgentApiTest.php` (8) | Chỉ quản lý site cấu hình, mức 3 không tự bật; tắt → từ chối run, mức 1 cần duyệt; task đã duyệt chạy qua executor; chặn khi vượt ngân sách; **nội dung web không thành tool/input (prompt injection)**; dừng khẩn cấp + kill switch; scheduler chạy hai lần một run; run đồng thời 409, phục hồi task quá hạn |
| `tests/Feature/Analytics/Phase8DemoSeederTest.php` (2) | Khối lượng demo, mọi hàng gắn `is_demo`, cơ hội có bằng chứng, ≤ 1 run hoạt động/site, idempotent; từ chối chạy production |
| `tests/Feature/Tenancy/CrossTenantIsolationTest.php` | Tự liệt kê mọi route tenant, gồm 64 route Phase 8 với fixture `phase8Fixture` → 404 chéo tenant |
| `tests/Unit/ArchitectureTest.php` (+5 luật) | Model Phase 8 dùng `BelongsToWorkspace`; request kế thừa `ApiRequest`; Analytics/Agent chỉ dùng contract; Agent không dùng nội bộ Analytics, `Process` hay hàm shell; `ToolExecutor` chỉ dùng trong `Agent\Engine` |

## 3. Test Phase 8 (frontend)
`frontend/tests/analytics.spec.ts` (9 test): định dạng không biến thiếu dữ liệu thành 0 (`—`), query string bỏ bộ lọc rỗng, kỳ mặc định 28 ngày trước độ trễ GSC, màu trạng thái, gợi ý duyệt Agent khớp policy server, chặn tool ghi ở mức 0; **mọi khóa i18n tĩnh trong 24 màn hình đều có bản dịch**; mọi giá trị enum API có nhãn tiếng Việt; không `v-html`; trang experiments ghi rõ “không phải A/B”, health ghi “không phải điểm của Google”.

## 4. Bảo mật
| Kiểm tra | Kết quả |
|---|---|
| Cross-tenant (64 route Phase 8) | 404 — pass |
| Phân quyền theo vai trò (viewer/editor/admin/owner) | pass (SearchConsoleSync, AnalysisApi, ContentRefresh, AgentApi) |
| Token Google không lộ ra API/log; thu hồi khi ngắt kết nối; kiểm tra scope | pass (`Http::fake`) |
| Prompt injection từ tiêu đề/truy vấn | Không tạo tool/input mới — pass |
| Agent không có SQL/shell/billing/API key/phân quyền | Unit + ArchitectureTest — pass |
| Mức 3 không tự bật, dừng khẩn cấp, kill switch | pass |
| Không ghi đè bài sửa trên WordPress | pass |
| Email báo cáo chỉ tới thành viên | pass (người ngoài → 403 `recipient_not_member`) |
| CSV formula injection (nhãn bắt đầu bằng `=`) | pass — ô bị trung hòa bằng tiền tố `'` |

## 5. Dữ liệu demo (chạy thật)
`php artisan migrate:fresh --seed --seeder=DemoSeeder` trên sqlite (APP_ENV=local): Phase8DemoSeeder ≈ 15 giây; chạy lại 27 ms (idempotent). Kết quả: 3 site, 365 ngày (5 475 hàng tổng theo ngày, 33 764 hàng trang, 148 056 hàng truy vấn), 100 bài mới có mapping, 509 truy vấn, 204 cơ hội (7 quy tắc khớp với dữ liệu demo), 204 đề xuất, 24 sự kiện decay, 36 điểm health, 5 experiment (4 hoàn tất bằng engine thật, 1 đang theo dõi), 6 báo cáo tháng `ready`, 20 lượt Agent, 10 refresh job.

## 6. Chưa kiểm thử được
- **Google Search Console thật** và **AI provider thật**: không có credentials; dùng `Http::fake` / `FakeProvider`.
- **E2E trình duyệt** cho màn hình Phase 8: chưa chạy (chỉ typecheck, lint, Vitest, build).
- **MySQL 8.4**: test chạy trên sqlite; truy vấn dùng query builder, `CAST(... AS SIGNED)` trong báo cáo đã chạy trên sqlite nhưng chưa chạy trên MySQL trong phiên này.
- Hiệu năng với dữ liệu lớn (hàng triệu hàng GSC/site): chưa đo.
