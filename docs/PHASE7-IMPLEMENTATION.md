# Phase 7: Billing, Subscription, AI Credits & Payments (triển khai)

- **Nhánh**: `claude/project-thread-cz2bqq`, tách từ `main` tại `15e0697` (sau khi merge PR #1 Phase 2–6).
- **Trạng thái**: đã triển khai, **chờ người dùng nghiệm thu**. Chưa merge, chưa deploy.
- **Tài liệu thiết kế**: `BILLING-ARCHITECTURE.md`, `CREDIT-LEDGER.md`, `PAYMENT-RECONCILIATION.md`, `SUBSCRIPTION-LIFECYCLE.md`.
- **Kết quả kiểm thử**: `PHASE7-TEST-REPORT.md`.

## 1. Kiểm tra trước khi code

Phase 6 đã merge vào `main` (`15e0697`). Các nền tảng Phase 1–6 mà Billing dựa vào đã có sẵn:
- Quyền `platform.billing.manage` và vai trò `BILLING_ADMIN` (Phase 1).
- `BudgetManager` reserve/commit cùng `ai_model_prices` (Phase 3).
- Quota tạm của Research và Writer.
- Giới hạn của Media và Publishing.

Có một P0 từ audit Phase 1: hệ thống chưa có entitlement, feature flag hay plan nào. Phase này xử lý P0 đó bằng `EntitlementEngine`.

## 2. Phạm vi đã làm

| Hạng mục spec | Triển khai |
|---|---|
| Gói có version, không xóa khi đang dùng | `plans` / `plan_versions` (bất biến sau publish) / `plan_entitlements`; 409 `plan_in_use`, `version_immutable` |
| Trial chống lạm dụng, không tự trừ tiền | `trial_redemptions` theo user và email chuẩn hóa (alias Gmail); trial hết hạn thì chuyển `EXPIRED` |
| Vòng đời 8 trạng thái | `SubscriptionStatus` + `subscription_events`; scheduler `sitex:billing:lifecycle` |
| Entitlement engine | `Billing\Contracts\Entitlements`, 9 tài nguyên, 7 tính năng; nối vào Sites, Workspace, Research, Writer, Media, Publishing; `enforcement` mặc định `off` |
| Ví credit, reserve → settle idempotent | Sổ cái append-only; `CreditUsageMeter` cài `AiGateway\Contracts\UsageMeter` |
| Chính sách quy đổi credit | `credit_policies` có version (µUSD/credit, markup, mức tối thiểu) |
| Đơn thanh toán, VietQR | `PaymentOrders`, mã tham chiếu `SXP…`, chuỗi EMVCo kèm CRC16, QR vẽ bằng SVG |
| Provider adapter | `manual_bank`, `signed_webhook` (HMAC + timestamp + event_id) |
| Đối soát, xác nhận | `Reconciliation::match` với 10 quy tắc; `PaymentConfirmation` là nơi duy nhất chuyển đơn sang `PAID`; duyệt và nhập tay có audit |
| Nâng cấp / hạ cấp | Quote, proration tùy chọn, hạ cấp lên lịch cuối kỳ |
| Scheduler gia hạn | Ân hạn, `PAST_DUE`, nhắc trước 7/3/1 ngày, gói free tự lăn kỳ |
| Chứng từ nội bộ | Biên nhận `RC-YYYY-NNNNNN` và chứng từ hoàn tiền `RF-…`, ghi rõ không phải hóa đơn GTGT |
| Kế toán doanh thu và chi phí | `revenue_records`, `cost_records` (gộp từ `ai_requests`), `Reports::revenue/costs` |
| Dashboard lợi nhuận | `Reports::profitability`: theo tenant/gói, cờ `loss_making`, `heavy_user`…; chỉ cảnh báo |
| Hoàn tiền | `refunds` requested → succeeded/failed, đảo credit, chứng từ |

## 3. Thay đổi ở module đang chạy

Chỉ thêm, không viết lại module nào.

**AI Gateway**: thêm contract `UsageMeter`, `UsageDenied`, `NullUsageMeter`. `AiGateway` có thêm 3 lời gọi: reserve, settle và release.

**Kiểm tra entitlement** trước tác vụ, mỗi file thêm từ 3 đến 18 dòng:
- `CreateSite` (websites)
- `InviteMember` (members)
- `QuotaManager` và `DispatchResearchSchedules` (research_runs)
- `WriterQuota` và `RunAutonomousWriter` (articles)
- `MediaLimits` (ai_images, storage_mb)
- `ManageJobs` (publishing_jobs)

Khi `enforcement = off`, mọi lời gọi trên không chặn gì. Test Phase 1–6 vẫn đạt.

## 4. Giao diện

**Khách hàng** (`frontend/src/apps/customer/pages/billing/`, menu "Thanh toán"): 9 route, gồm 13 màn hình theo spec.

| Spec | Route / màn hình |
|---|---|
| Tổng quan billing | `billing`: gói hiện tại, ví, giới hạn, đơn đang chờ |
| Bảng giá / chọn gói | `billing-plans`: tháng/năm, trial, nhãn `[MẪU]` |
| Xác nhận đổi gói | modal quote trên `billing-plans` |
| Thanh toán VietQR | `billing-checkout`: QR, sao chép, đếm ngược, polling 8 s, "Tôi đã chuyển khoản" |
| Ví credit | `billing-credits` |
| Mua credit | gói credit trên `billing-credits` |
| Lịch sử credit | `billing-transactions` |
| Sử dụng | `billing-usage` |
| Đơn thanh toán | `billing-orders` (tab Đơn) |
| Chứng từ | `billing-orders` (tab Chứng từ, drawer chi tiết) |
| Lịch sử gói | `billing-history` |
| Hồ sơ thanh toán | `billing-profile` |
| Cài đặt gia hạn/hủy | `billing` (hủy, bỏ hủy, nhắc gia hạn) |

**Super Admin** (`frontend/src/apps/admin/pages/billing/`, quyền `platform.billing.manage`): 8 route và một liên kết audit, gồm 14 màn hình.

| Route | Nội dung |
|---|---|
| Gói | 3 tab: Gói + version, Gói credit, Chính sách credit |
| Subscription | Danh sách, chi tiết và hành động |
| Đơn | Danh sách, chi tiết, nhập thanh toán tay |
| Đối soát | Giao dịch chờ, duyệt / từ chối |
| Hoàn tiền | |
| Credit | Ví, điều chỉnh, lịch sử điều chỉnh, usage chưa chắc chắn |
| Báo cáo | 3 tab: Doanh thu, Chi phí, Lợi nhuận |
| Cài đặt | Cài đặt chung và cổng thanh toán |
| Audit billing | Trang Audit lọc theo `billing.` |

Mọi chuỗi hiển thị qua i18n (`src/locales/billing.vi.ts`). Mỗi màn hình có trạng thái loading, empty và error.

## 5. Dữ liệu demo

`BillingDemoSeeder` được gọi từ `DemoSeeder`, chỉ chạy ở development (từ chối khi production) và chạy lại không nhân đôi dữ liệu. Nội dung:
- 4 gói `[MẪU] Free/Starter/Pro/Agency`.
- 3 gói credit mẫu, policy v1, provider `demo-bank`.
- 50 workspace demo có subscription.
- 100 đơn, ≥ 200 bút toán credit, 30 bản ghi chi phí mẫu (provider `demo`), 10 hoàn tiền.
- Mọi giá và tên đều ghi rõ là dữ liệu mẫu.

## 6. Chưa làm / giới hạn

- **Chưa có cổng thanh toán thật**: chưa chọn dịch vụ báo giao dịch cho `signed_webhook`, chưa có tài khoản ngân hàng nhận tiền. Muốn thu tiền thật phải cấu hình ở Super Admin → Billing → Cài đặt.
- **Không auto-debit, không hoàn tiền tự động qua API ngân hàng**: tiền hoàn được chuyển ngoài hệ thống rồi đánh dấu.
- **Không có hóa đơn GTGT điện tử**, chỉ có biên nhận nội bộ.
- **`enforcement` tắt mặc định**: khi bật, khách không có gói sẽ bị chặn tạo site/mời thành viên/chạy AI vượt giới hạn. Cần nhập gói thật hoặc `default_plan_code` trước khi bật.
- **Locale EN** dùng fallback tiếng Việt cho chuỗi billing (giống các phase trước).
- **Chưa có test đồng thời trên MySQL** cho ví credit. Logic dùng khóa hàng (`lockForUpdate`) và ràng buộc UNIQUE, nhưng test chạy trên SQLite.
- **Chưa chạy thử UI trên trình duyệt thật** trong phase này; xem `PHASE7-TEST-REPORT.md`.
