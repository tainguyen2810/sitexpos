# Phase 7: Báo cáo kiểm thử

- **Ngày chạy**: 06/11/2026
- **Nhánh**: `claude/project-thread-cz2bqq`
- **Môi trường**: Linux, PHP 8.3, Node 22, Chromium (Playwright)

Mọi số liệu dưới đây là **kết quả thật đã chạy**. Mục nào chưa chạy được đều ghi rõ.

## 1. `scripts/check.sh` (toàn bộ, lần chạy cuối)

```
==> Backend: Pint
{"tool":"pint","result":"passed"}
==> Backend: Larastan
 [OK] No errors
==> Backend: Pest
  Tests:    1 skipped, 429 passed (3023 assertions)
==> Frontend: lint
==> Frontend: typecheck
==> Frontend: unit tests
 Test Files  10 passed (10)
      Tests  84 passed (84)
==> Frontend: build
==> Frontend: no demo code in production build
==> WordPress plugin: lint, PHP 7.4 check, signer test, translations, zip
23 files checked, 0 problems
126/126 translated
== built dist/sitex-post-1.1.0.zip (60K)
All checks passed.
real	2m6.067s
```

- So với Phase 6 (375 test), số test backend tăng thêm 54. Toàn bộ test của Phase 1–6 vẫn đạt.
- Test bị skip là `never oversells a budget under concurrent reservations` của Phase 3. Test này chỉ chạy khi có MySQL.
- Khi máy không có `backend/.env`, Pest in cảnh báo `file_get_contents(.env)` cho mỗi test và chỉ hiện "429 warnings". Đây là cảnh báo có từ trước, không phải lỗi. Có `.env` thì Pest in số liệu như trên.

## 2. Test backend Phase 7 (51 test, `tests/Feature/Billing`, `tests/Unit/Billing`)

### BillingMathTest (unit, 12)
- Quy đổi µUSD sang credit: markup, mức tối thiểu, làm tròn lên.
- Proration: làm tròn xuống, không tính âm.
- VietQR: CRC16 khớp vector chuẩn `123456789 → 29B1`, cấu trúc EMVCo, parse ngược.
- Mã tham chiếu: bảng ký tự không gây nhầm, tách mã khỏi nội dung chuyển khoản.
- Bảng chuyển trạng thái subscription và quyền dùng AI theo từng trạng thái.
- Ghi nhận doanh thu theo kỳ, phân loại `CostCategory`.

### PaymentFlowTest (11)
- Đơn `PENDING` có QR. Nút "đã chuyển khoản" chỉ chuyển sang `PROCESSING`.
- Webhook kích hoạt gói đúng một lần; replay trả `duplicate`.
- Webhook giả chữ ký, quá hạn hoặc thiếu chữ ký nhận 401.
- Thiếu tiền, thừa tiền, sai tiền tệ, không tìm thấy mã đều chuyển sang hàng chờ duyệt.
- Đơn hết hạn không tự kích hoạt; staff duyệt được.
- Thanh toán tay với mã ngân hàng duy nhất: 201, lần trùng 409.
- Nạp credit idempotent, kể cả khi click đúp hoặc webhook gửi lặp.
- Hoàn tiền thành công thì đảo credit; thất bại thì không thay đổi gì.
- **Hoàn tiền credit chưa dùng không bị tính là lỗ** (test hồi quy, xem §4).
- Chỉ Owner tạo được đơn; workspace khác nhận 404; gửi `workspace_id` trong body nhận 422.

### AiCreditsTest (8)
- Thiếu credit thì chặn **trước khi** gọi provider.
- Settle tính markup.
- Retry cùng khóa trả `duplicate_call` và không trừ hai lần.
- Timeout không chắc chắn: tính theo ước tính và đưa vào danh sách cần duyệt.
- Provider từ chối thì trả lại toàn bộ chỗ giữ.
- Worker chết: chỗ giữ hết hạn và được settle theo ước tính.
- Chi phí vượt số dư thì ghi `uncollected`, không để ví âm.
- Credit tiêu theo FIFO theo hạn, rồi hết hạn.
- Sổ cái chặn update và delete.

### SubscriptionLifecycleTest (11)
- Một người chỉ được một trial, kể cả khi dùng alias Gmail. Trial hết hạn không thu tiền. Trial bị tắt thì không bắt đầu được.
- Endpoint free từ chối gói trả phí.
- Nâng cấp có proration, giữ nguyên id và ngày hết kỳ.
- Hạ cấp được lên lịch và áp dụng khi gia hạn.
- Ân hạn hết thì `EXPIRED`, dữ liệu còn nguyên, đăng ký lại được.
- Hủy và bỏ hủy có lịch sử. Nhắc gia hạn chỉ gửi một lần.
- Hành động của staff có audit; khách nhận 404, Support nhận 403.
- Xóa gói đang dùng nhận 409.
- Publish version mới không đổi giá của subscription cũ.

### EntitlementsAdminTest (7)
- `enforcement = off` thì không thay đổi gì.
- Khi `enforce`:
  - Không có gói: `subscription_required`.
  - Vượt giới hạn website: `plan_limit_reached`.
  - Mời thành viên vượt giới hạn: 402.
  - Tính năng không có trong gói: `feature_unavailable`.
- Gói mặc định được áp dụng.
- Quyền vào admin API.
- Điều chỉnh credit: không cho làm âm ví, không để lại bản ghi mồ côi.
- Secret của provider không bao giờ được trả về hay ghi log.
- Các báo cáo đều trả về được.

### BillingDemoSeederTest (2)
- Số lượng: 4 gói `[MẪU]`, 50 subscription, 100 đơn, ≥ 200 bút toán, 30 bản ghi chi phí, 10 hoàn tiền.
- `verify()` của sổ cái trả rỗng.
- Seeder từ chối chạy ở production.

### Test có sẵn được mở rộng
- `CrossTenantIsolationTest` tự liệt kê mọi route tenant, gồm 26 route billing. Đã thêm `paymentOrder` và `billingDocument` của tenant B, đều trả 404.
- `ArchitectureTest` thêm 3 quy tắc:
  - Model tenant của Billing dùng `BelongsToWorkspace`.
  - Request kế thừa `ApiRequest`.
  - `Credits`, `Payments`, `Subscriptions`, `Entitlements` chỉ được dùng trong Billing và seeder.

## 3. Test frontend (`frontend/tests/billing.spec.ts`, 7 test)

- Định dạng tiền và credit, hàm `toInt`.
- QR render được.
- Locale có đủ mọi khóa `bill.*` và `billAdmin.*` mà giao diện dùng.
- Nhãn "không phải hóa đơn GTGT" hiển thị trên chứng từ.

## 4. Chạy thử app thật trên trình duyệt

**Cách chạy**:
- Backend: `php artisan serve` với SQLite, dữ liệu `migrate:fresh --seed --seeder=DemoSeeder` (demo Phase 1–7).
- Frontend: Vite dev cho cả hai app, điều khiển bằng Chromium (Playwright).
- Khách hàng: đăng nhập `owner@demo.sitexpost.test`, mở 9 trang billing, chọn gói Starter để tạo đơn và mở trang thanh toán QR.
- Super Admin: đăng nhập `superadmin@demo.sitexpost.test`, bật 2FA, mở 8 trang billing, tab gói credit, tab báo cáo chi phí và lợi nhuận, trang audit lọc `billing.`.

**Lỗi phát hiện ở lần chạy đầu** (test tự động không bắt được), đã sửa:

1. **Báo cáo lợi nhuận sai (nghiêm trọng)**: 8 khách có hoàn tiền gói credit hiện doanh thu ghi nhận âm và bị gắn cờ "Lỗ", ví dụ −1.575.419 ₫.
   - Nguyên nhân: credit mua chưa từng được ghi nhận doanh thu (vẫn nằm trong nợ phải trả), nhưng khoản hoàn tiền lại bị trừ toàn bộ vào doanh thu ghi nhận.
   - Đã sửa `Reports`: hoàn tiền chỉ giảm doanh thu ghi nhận ở phần không tương ứng credit chưa tiêu được thu hồi. Báo cáo doanh thu có thêm dòng `recognized.refunds_minor`.
   - Đã thêm test hồi quy. Test này fail với code cũ và pass với code mới (đã kiểm chứng).
2. Biểu đồ "Theo ngày" (trang Sử dụng của khách, trang Báo cáo chi phí của admin): khi chỉ có một ngày dữ liệu, cột phủ kín cả khung. Đã giới hạn độ rộng cột và thêm nhãn ngày và số liệu.
3. Trang Tổng quan billing có nút "Chuyển sang gói này" ngay trên gói hiện tại. Đã đổi thành "Đổi gói".
4. Admin: gói đang có khách dùng vẫn hiện nút "Xóa gói" (backend vẫn chặn với 409). Đã ẩn nút khi gói còn subscription đang sống.

**Kết quả lần chạy cuối**:
- 9/9 trang khách và 9/9 trang admin hiển thị đúng.
- Không còn khóa i18n thô, không có lỗi console, không API nào lỗi. Ngoại lệ duy nhất là 401 của `/me` ở trang đăng nhập, đúng như mong đợi.

## 5. Phần được giả lập

| Thành phần | Cách kiểm | Ghi chú |
|---|---|---|
| Dịch vụ báo giao dịch ngân hàng | Webhook ký HMAC do test tự tạo (`signedWebhook()`) | **Chưa nối nhà cung cấp thật nào** vì chưa chọn |
| Ngân hàng nhận tiền | Provider `manual_bank` với số tài khoản mẫu `0000000000` | QR có cấu trúc đúng chuẩn nhưng **chưa quét thử bằng app ngân hàng thật** |
| Provider AI | `FakeProvider` qua AI Gateway thật | Không gọi API thật |
| Chi phí AI trong demo | 30 `cost_records` với provider `demo` | Demo chỉ tạo bản ghi chi phí cho vài workspace, nên báo cáo lợi nhuận demo phần lớn hiện chi phí $0 |

## 6. Chưa chạy được hoặc chưa có

- **Test đồng thời trên MySQL cho ví credit và đơn**: logic dùng `lockForUpdate` và ràng buộc UNIQUE, nhưng các test chạy trên SQLite. CI GitHub (MySQL 8.4) sẽ chạy toàn bộ Pest khi mở PR.
- **Quét QR bằng app ngân hàng thật** và **webhook từ nhà cung cấp thật**: cần tài khoản ngân hàng và dịch vụ mà người dùng chọn.
- **Locale EN**: chưa có bản dịch riêng, đang dùng fallback tiếng Việt.
