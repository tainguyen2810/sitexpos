# Kiến trúc Billing (Phase 7)

Module `backend/app/Modules/Billing` (namespace `SiteXPost\Modules\Billing`). Tài liệu liên quan: `CREDIT-LEDGER.md`, `PAYMENT-RECONCILIATION.md`, `SUBSCRIPTION-LIFECYCLE.md`, `PHASE7-IMPLEMENTATION.md`.

## 1. Nguyên tắc

1. **Không hardcode giá.** Giá gói, gói credit, tỷ lệ quy đổi credit đều nằm trong DB (`plan_versions`, `credit_packages`, `credit_policies`) và do Super Admin nhập. Giá vốn AI lấy từ `ai_model_prices` (Phase 3). Code chỉ có công thức.
2. **Mọi biến động tiền/credit đi qua sổ cái.** Credit: `credit_ledger_entries` (append-only). Tiền: `payment_orders` → `payment_transactions` → `payment_reconciliations` → `refunds`, mỗi bước có audit.
3. **Không tin trạng thái thanh toán do frontend gửi.** Nút "Tôi đã chuyển khoản" chỉ chuyển đơn sang `PROCESSING` (chờ đối soát). Đơn chỉ thành `PAID` qua webhook có chữ ký hợp lệ hoặc Billing Admin duyệt.
4. **Không scraping Internet Banking, không thu thập mật khẩu ngân hàng.** Chỉ có hai adapter: `manual_bank` (chuyển khoản + đối soát thủ công) và `signed_webhook` (một dịch vụ báo giao dịch gửi webhook ký HMAC).
5. **Không đổi hành vi hiện có khi chưa bật.** `enforcement = off` mặc định: entitlement chỉ ghi nhận, không chặn; AI chỉ trừ credit khi enforcement bật. Bật `enforce` là quyết định vận hành.
6. **Báo cáo lợi nhuận chỉ cảnh báo**, không bao giờ tự khóa khách.
7. **Chứng từ nội bộ không phải hóa đơn GTGT** (API luôn trả `is_tax_invoice: false`, snapshot chứng từ ghi `legal_notice = internal_document_not_vat_invoice`, UI hiển thị nhãn rõ).

## 2. Thành phần

| Thư mục | Vai trò |
|---|---|
| `Domain/` | 28 model (bảng ở `DATABASE.md §3.4`). Model tenant dùng `BelongsToWorkspace` (kiểm bằng `ArchitectureTest`) |
| `Support/Catalog` | Đọc plan/version/entitlement; danh sách `RESOURCES` (9 tài nguyên) và `FEATURES` (7 cờ) |
| `Support/BillingSettings` | Cài đặt toàn nền tảng (`billing_settings`, key/value, có giá trị mặc định) |
| `Support/InTenant` | Chạy một closure trong ngữ cảnh workspace (dùng cho scheduler, webhook, admin) — không bỏ scope |
| `Entitlements/EntitlementEngine` | Cài đặt `Contracts\Entitlements`: `effective`, `assertWithin`, `assertFeature`, `consume`/`release` (đếm theo kỳ trong `usage_meters`) |
| `Credits/` | `CreditLedger` (grant/consume FIFO/expire/adjust/reverse, `verify()`), `CreditConverter` (µUSD → mc), `CreditUsageMeter` (cài `AiGateway\Contracts\UsageMeter`) |
| `Subscriptions/` | `SubscriptionStatus` (8 trạng thái + bảng chuyển hợp lệ), `SubscriptionManager` (trial, free, fulfil, quote, change, cancel/resume, staff action, `tick`), `Proration` |
| `Payments/` | `PaymentOrders` (tạo đơn, mã tham chiếu, hết hạn), `VietQr` (EMVCo + CRC16), `Adapters/` (`manual_bank`, `signed_webhook`), `Reconciliation`, `PaymentConfirmation` (nơi duy nhất chuyển đơn sang `PAID`), `Refunds` |
| `Accounting/` | `Documents` (biên nhận nội bộ), `CostAggregator` (gộp `ai_requests` → `cost_records`), `Reports` (doanh thu, chi phí, lợi nhuận) |
| `Http/` | 4 controller khách hàng + webhook, 3 controller admin, FormRequest kế thừa `ApiRequest`, `BillingPresenter` |
| `Console/` | `sitex:billing:lifecycle` (15'), `sitex:billing:expire-credits` (5'), `sitex:billing:aggregate-costs --days=2` (1h), `sitex:billing:verify-ledger` (03:15) |

## 3. Ranh giới với module khác

- **AI Gateway** không biết credit. Gateway có contract `AiGateway\Contracts\UsageMeter` (`reserve`/`settle`/`release`), mặc định `NullUsageMeter`. Billing bind `CreditUsageMeter`. Gateway gọi `reserve` trước provider, `settle` sau, cùng khóa idempotency với `BudgetManager` (`callKey:attempt`) → retry queue không trừ hai lần. `BudgetManager` (ngân sách giá vốn USD) giữ nguyên.
- **Sites, Workspace, Research, Writer, Media, Publishing** chỉ gọi `Billing\Contracts\Entitlements` (`CreateSite`, `InviteMember`, `QuotaManager`, `WriterQuota`, `MediaLimits`, `ManageJobs`, các lệnh tự động). Billing không ghi vào bảng module khác.
- Bên trong Billing, `Credits/Payments/Subscriptions/Entitlements` chỉ được dùng trong module Billing và seeder demo (`ArchitectureTest`).

## 4. Đơn vị

| Đại lượng | Đơn vị | Ghi chú |
|---|---|---|
| Tiền | `*_minor` (BIGINT) + `currency` | VND exponent 0 |
| Giá vốn AI | µUSD | như Phase 3 |
| Credit | milli-credit `*_mc` | 1 credit = 1000 mc; không dùng float |
| Tỷ giá | `usd_to_currency_rate` (số nguyên, ví dụ 25000) | chỉ dùng cho báo cáo lợi nhuận; thiếu → lợi nhuận `null`, không đoán |

Quy đổi: `mc = ceil(cost_µUSD × (10000 + markup_bps) × 1000 / (10000 × micro_usd_per_credit))`, tối thiểu `min_charge_mc`. Mỗi reservation lưu `policy_id` → không áp giá mới hồi tố.

## 5. Phân quyền

| Quyền | Ai | Dùng cho |
|---|---|---|
| `billing.view` | mọi thành viên workspace | xem gói, ví, giao dịch, đơn, chứng từ |
| `billing.manage` | chỉ Owner | mua, đổi gói, hủy, trial, hồ sơ thanh toán |
| `platform.billing.manage` | Super Admin, Billing Admin | toàn bộ `/api/admin/v1/billing/*` |

Webhook `POST /api/v1/billing/webhooks/{provider}` công khai; tính xác thực = chữ ký adapter. Mọi thao tác ghi của admin gọi `Audit::record('billing.*')`.

## 6. Luồng chính (tóm tắt)

- **Mua gói/credit**: khách tạo đơn (khóa idempotency) → VietQR với mã `SXPxxxxxxxx` → webhook/admin xác nhận → `PaymentConfirmation::confirm` (một transaction khóa đơn): `PAID`, kích hoạt/gia hạn gói hoặc cấp credit (`source_ref` duy nhất), biên nhận, thông báo, audit. Xem `PAYMENT-RECONCILIATION.md`.
- **AI**: reserve (kiểm số dư khả dụng) → gọi provider → settle theo chi phí thật; timeout không chắc chắn → tính theo ước tính + `needs_review`. Xem `CREDIT-LEDGER.md`.
- **Vòng đời gói**: `tick()` mỗi 15 phút: trial hết hạn, hết kỳ → gia hạn miễn phí / chờ thanh toán / ân hạn → hết hạn; nhắc gia hạn. Xem `SUBSCRIPTION-LIFECYCLE.md`.
- **Kế toán**: doanh thu tiền mặt theo `paid_at`; doanh thu ghi nhận phân bổ đều theo ngày của kỳ; credit mua là nợ phải trả cho tới khi tiêu; hoàn tiền giảm doanh thu ghi nhận, trừ phần tương ứng credit chưa tiêu được thu hồi (phần đó chỉ giảm nợ phải trả); chi phí AI gộp hằng giờ từ `ai_requests` vào `cost_records` (vì `ai_requests` bị prune sau 90 ngày).
