# Fact-check Policy (Phase 5)

Code: `Pipeline/RuleClaims.php`, `Pipeline/VerdictRules.php`, `Pipeline/FactChecker.php`, `Pipeline/ApprovalGate.php`.

## 1. Nguyên tắc
1. **Model đề xuất, quy tắc quyết định.** Độ tự tin của model không bao giờ được dùng.
2. **Không tìm thấy ≠ sai.** Thiếu bằng chứng → `INSUFFICIENT_EVIDENCE`, không bao giờ `CONTRADICTED`.
3. Bằng chứng phải là đoạn trích **nguyên văn** từ nguồn hệ thống đã tải (`evidence_items`). Model không được tuyên bố đã truy cập website.
4. Người dùng có thể xác minh thủ công, kèm ghi chú và (tùy chọn) URL bằng chứng.

## 2. Mức rủi ro (risk floor theo loại claim)

| Loại | Mức tối thiểu |
|---|---|
| `legal`, `risky_instruction` | CRITICAL |
| `spec`, `price`, `compatibility`, `version`, `release_date` | HIGH |
| `statistic`, `research`, `comparison` | MEDIUM |
| `other` | LOW |

Model có thể **nâng** mức rủi ro, không thể hạ dưới mức sàn.

## 3. Verdict

| Verdict | Điều kiện |
|---|---|
| `VERIFIED` | ≥ 1 đoạn trích hỗ trợ hợp lệ, chứa **mọi định danh chính xác** của claim (model, phiên bản, số + đơn vị), không có đoạn mâu thuẫn. HIGH/CRITICAL cần nguồn có độ tin cậy ≥ `high_risk_min_reliability` (60). |
| `CONTRADICTED` | Có đoạn trích mâu thuẫn thật. |
| `INSUFFICIENT_EVIDENCE` | Không có bằng chứng hợp lệ (gồm cả trường hợp model nói "sai" nhưng không có đoạn mâu thuẫn — ghi chú `not_found_is_not_false`). |
| `OUTDATED` | Claim nhạy thời gian (`price`, `version`, `release_date`, `legal`) mà bằng chứng mới nhất cũ hơn `outdated_after_months` (24). |
| `NEEDS_HUMAN_REVIEW` | Bằng chứng mâu thuẫn nhau, thiếu định danh trong bằng chứng, nguồn kém tin cậy cho claim rủi ro cao, hoặc model không trả lời. |
| `PENDING` | Chưa kiểm chứng hoặc section đã bị sửa sau lần kiểm chứng. |

## 4. Xác minh thủ công
- `POST claims/{id}/verify` với verdict + ghi chú bắt buộc (≥ 10 ký tự); lưu `reviewer_type = human`, người xác minh và thời điểm; ghi audit.
- `VERIFIED`/`CONTRADICTED` thủ công phải kèm bằng chứng: chọn `evidence_ids` có trong hồ sơ của bài hoặc nhập `reference_url` (http/https). Thiếu cả hai → 422 (backend chặn, giao diện cũng chặn).
- Claim CRITICAL chỉ người có quyền duyệt (`content.publish`) được xác minh; claim khác cần quyền sửa (`content.edit`).

## 5. Điều kiện duyệt bài (ApprovalGate)
Chặn duyệt khi:
- bài không ở trạng thái chờ duyệt, chưa có nội dung, hoặc đang có workflow AI chạy;
- còn claim **CRITICAL** chưa `VERIFIED`;
- có section đã sửa nhưng chưa kiểm chứng lại;
- chưa chạy đánh giá chất lượng cho phiên bản hiện tại, hoặc điểm < `min_quality_score_for_approval` (50);
- kiểm tra trùng lặp đề xuất loại và người dùng chưa chọn quyết định;
- còn claim **HIGH** chưa xác minh và chưa xác nhận (`acknowledge_high_risk`) khi `require_ack_for_high_risk` bật.

Cảnh báo (không chặn): claim `INSUFFICIENT_EVIDENCE`/`PENDING` mức thấp hơn.

Điểm chất lượng **không bao giờ** là điều kiện duyệt duy nhất. Mọi chỉnh sửa sau khi duyệt làm mất hiệu lực phê duyệt.
