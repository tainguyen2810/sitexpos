# Sổ cái credit AI (Phase 7)

Code: `backend/app/Modules/Billing/Credits/`. Test: `tests/Feature/Billing/AiCreditsTest.php`, `tests/Unit/Billing/BillingMathTest.php`.

## 1. Bảng

| Bảng | Vai trò |
|---|---|
| `credit_wallets` | Một ví / workspace. `balance_mc`, `held_mc` chỉ là **projection** (bộ đệm); nguồn sự thật là sổ cái |
| `credit_grants` | "Xô" credit: nguồn `included` / `purchased` / `trial` / `promotional` / `bonus` / `refund` / `adjustment`; `amount_mc`, `remaining_mc`, `expires_at` (có thể null), `source_ref` UNIQUE |
| `credit_reservations` | Giữ chỗ cho một lời gọi AI: `idempotency_key` UNIQUE (= khóa reservation của Gateway), `amount_mc`, `policy_id`, `status` held/settled/released/uncertain, `expires_at`, `needs_review` |
| `credit_ledger_entries` | **Append-only** (model chặn update/delete): `entry_type` grant/consume/expire/refund/adjust/reversal, `amount_mc` có dấu, `balance_after_mc`, `grant_id`, `idempotency_key` UNIQUE, `reverses_entry_id` |
| `credit_adjustments` | Điều chỉnh của admin, lý do bắt buộc, gắn `ledger_entry_id` |
| `credit_policies` | Versioned: `micro_usd_per_credit`, `markup_bps`, `min_charge_mc`, `effective_from` |
| `credit_packages` | Gói credit bán: số credit, giá, hạn dùng |

## 2. Quy đổi chi phí → credit

```
mc = ceil( cost_µUSD × (10000 + markup_bps) × 1000 / (10000 × micro_usd_per_credit) )
mc = max(mc, min_charge_mc)
```
Số nguyên, làm tròn lên một lần ở cuối. Policy hiện hành = bản có `effective_from ≤ now` mới nhất. Không có policy → AI không trừ credit được khi enforcement bật (màn Cài đặt cảnh báo "chưa cấu hình").

## 3. Luồng AI (tích hợp Gateway Phase 3)

1. **Reserve** — `CreditUsageMeter::reserve(key, ws, task, estimate)`: quy đổi theo policy, khóa ví (`lockForUpdate`), kiểm `balance − held ≥ amount`; thiếu → `UsageDenied` → Gateway trả `insufficient_credits` **trước khi** gọi provider. Đủ → tạo reservation `held`, tăng `held_mc`. Khóa trùng → Gateway đã chặn bằng `duplicate_call`.
2. **Settle** — chi phí thật đã biết: tiêu `min(thực tế, số dư)` theo FIFO (grant hết hạn sớm nhất trước, grant không hạn sau cùng), giải phóng phần giữ dư. Gọi lại cùng khóa → `false`, không trừ lần hai.
3. **Uncertain** — timeout sau khi đã gửi request: tính theo **ước tính**, `needs_review = true`, hiện ở màn admin "Usage chưa chắc chắn"; admin có thể hoàn bằng bút toán đảo.
4. **Release** — provider từ chối/không tính phí: trả lại toàn bộ chỗ giữ.
5. **Hết hạn giữ chỗ** — worker chết: sau `reservation_ttl_minutes` (30') lệnh `expire-credits` settle theo ước tính (giống `BudgetManager`).
6. **Không âm ví** — chi phí thật > số dư: chỉ trừ tới 0, phần thiếu ghi `uncollected_mc` trên reservation (báo cáo), không tạo nợ âm.

## 4. Cấp và hết hạn

- Thanh toán gói → grant `included` (hết hạn cuối kỳ nếu `included_credits_expire`), `source_ref` dạng `order:<id>`.
- Mua gói credit → grant `purchased` (hạn theo `validity_days`, hoặc không hạn nếu cho phép).
- Trial → grant `trial`. Admin → `adjustment` (dương/âm; âm không được làm số dư < 0 → 422, không để lại bản ghi điều chỉnh mồ côi).
- `sitex:billing:expire-credits` ghi bút toán `expire` cho phần còn lại của grant đã hết hạn.
- Hoàn tiền đơn credit → bút toán `reversal` trừ phần credit chưa tiêu tương ứng (`credits_reversed_mc` trên refund).

## 5. Bất biến và kiểm tra

- `idempotency_key` UNIQUE trên sổ cái và reservation; `source_ref` UNIQUE trên grant → xác nhận thanh toán lặp không cấp hai lần.
- `CreditLedger::verify()` (lệnh `sitex:billing:verify-ledger`, hằng ngày): với mỗi ví, `balance_mc` = tổng `amount_mc` sổ cái = tổng `remaining_mc` grant; `held_mc` = tổng reservation `held`. Lệch → log lỗi (không tự sửa). Seeder demo và test đều kiểm `verify() === []`.
- Mọi thao tác ghi chạy trong transaction có khóa hàng ví.
