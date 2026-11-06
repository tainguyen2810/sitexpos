# Thanh toán & đối soát (Phase 7)

Code: `backend/app/Modules/Billing/Payments/`. Test: `tests/Feature/Billing/PaymentFlowTest.php`.

> Không dùng scraping Internet Banking, không thu thập mật khẩu ngân hàng. Trạng thái thanh toán do frontend gửi không bao giờ được tin.

## 1. Đơn thanh toán (`payment_orders`)

- Mục đích (`purpose`): `subscription`, `renewal`, `upgrade`, `credit_package`.
- Mã tham chiếu `reference` UNIQUE: `SXP` + 8 ký tự từ `[A-HJ-NP-Z2-9]` (bỏ I, O, 0, 1 để tránh nhầm khi gõ tay). Khách ghi mã này vào nội dung chuyển khoản.
- `idempotency_key` UNIQUE theo workspace → bấm "Thanh toán" hai lần trả cùng đơn.
- Hết hạn sau `order_expiry_minutes` (mặc định 1440').
- Trạng thái: `PENDING` → `PROCESSING` (khách bấm "Tôi đã chuyển khoản" — **chỉ** báo hiệu, không kích hoạt gì) → `PAID` → (`PARTIALLY_REFUNDED` | `REFUNDED`); hoặc `EXPIRED`, `CANCELLED`, `FAILED`.

## 2. VietQR

`VietQr::payload()` dựng chuỗi EMVCo (NAPAS 247, GUID `A000000727`, dịch vụ `QRIBFTTA`) từ `bank_bin`, `account_no` của provider `manual_bank`, số tiền, nội dung = mã tham chiếu, CRC16-CCITT (kiểm bằng vector chuẩn `123456789 → 29B1`). Frontend render QR SVG (`qrcode-generator`, không gọi dịch vụ ngoài). Provider chưa nhập tài khoản → không tạo QR, hiện cảnh báo "chưa cấu hình".

## 3. Adapter

| Adapter | Xác nhận bằng | Ghi chú |
|---|---|---|
| `manual_bank` | Billing Admin nhập giao dịch thủ công (mã giao dịch ngân hàng UNIQUE) hoặc duyệt giao dịch chờ | Mặc định, không cần bên thứ ba |
| `signed_webhook` | `POST /api/v1/billing/webhooks/{provider}` | Dịch vụ báo giao dịch (chưa chọn nhà cung cấp) gửi JSON đã chuẩn hóa |

**Webhook ký**: header `X-SiteX-Timestamp` (unix giây) và `X-SiteX-Signature: sha256=<hex>` với `HMAC-SHA256(secret, timestamp + "." + raw_body)`. Lệch thời gian > ±300 s, sai chữ ký, thiếu header → `401 webhook_rejected`, không xử lý gì; chỉ log cảnh báo kèm lý do và SHA-256 của payload (không log payload, không log secret). Provider không tồn tại hoặc đang tắt → 404. Webhook hợp lệ được xử lý đồng bộ và trả `200 {status: processed|duplicate, results[]}`. Chống replay: `(provider, event_id)` UNIQUE → gửi lại trả `duplicate`, không xử lý lần hai. Secret lưu mã hóa (cast `encrypted`), không bao giờ trả qua API (chỉ `credentials_set`).

## 4. Khớp giao dịch (`Reconciliation::match`)

Mỗi giao dịch vào lưu `payment_transactions` với `(provider, external_id)` UNIQUE (giao dịch lặp → 409 `duplicate_transaction`). Quy tắc theo thứ tự:

| Điều kiện | Kết quả `match_status` / lý do |
|---|---|
| Giao dịch chiều ra | `ignored` / `outgoing` |
| Không có mã `SXP…` trong nội dung | `unmatched` / `reference_not_found` |
| Nhiều mã | `unmatched` / `multiple_references` |
| Mã không tồn tại | `unmatched` / `reference_not_found` |
| Khác tiền tệ | `mismatch` / `currency_mismatch` |
| Đơn đã trả | `mismatch` / `order_already_paid` |
| Đơn hết hạn / hủy / lỗi | `mismatch` / `order_not_payable` (không tự kích hoạt) |
| Giao dịch trước thời điểm tạo đơn > 5' | `mismatch` / `predates_order` |
| Thiếu tiền | `mismatch` / `underpaid` |
| Thừa tiền và `overpayment_policy = manual_review` | `mismatch` / `overpaid` |
| Còn lại | `matched` → `PaymentConfirmation::confirm` |

Mọi kết quả ghi `payment_reconciliations` (kết quả, chênh lệch, người duyệt, ghi chú).

## 5. Duyệt thủ công (Super Admin → Đối soát)

- **Duyệt** giao dịch `unmatched`/`mismatch` cho một đơn (ghi chú bắt buộc) — kể cả đơn đã hết hạn, sau khi người kiểm tra.
- **Từ chối** giao dịch (ghi chú bắt buộc) → `rejected`.
- **Nhập thanh toán thủ công** cho đơn (`bank_reference` UNIQUE, số tiền, ghi chú) → tạo giao dịch `manual` rồi xác nhận.
- Tất cả có audit `billing.*`.

## 6. Xác nhận (`PaymentConfirmation::confirm`)

Nơi duy nhất chuyển đơn sang `PAID`. Trong một transaction có khóa hàng đơn: kiểm trạng thái → `PAID`, `paid_amount_minor`, `paid_at` → thực hiện (kích hoạt/gia hạn/đổi gói hoặc cấp credit với `source_ref = order id`) → biên nhận nội bộ → thông báo (UNIQUE theo đơn + loại) → audit. Gọi lại với đơn đã `PAID` trả `false`, không làm gì.

## 7. Hoàn tiền (`refunds`)

Admin tạo yêu cầu (số tiền ≤ đã thu − đã hoàn, lý do) → `requested`; tiền được chuyển trả ngoài hệ thống → admin đánh dấu `succeeded` (mã tham chiếu) hoặc `failed` (lý do). Thành công: cập nhật `refunded_amount_minor`, trạng thái đơn, đảo credit chưa tiêu (đơn credit), chứng từ hoàn tiền, audit. Hệ thống không tự chuyển tiền.
