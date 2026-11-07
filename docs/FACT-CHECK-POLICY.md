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
**Từ v1.0.11 (ADR-030): kiểm chứng là cảnh báo, không phải bức tường.** Thiếu nguồn xác minh không chặn người duyệt; người duyệt chỉ cần xác nhận đã xem cảnh báo.

Chặn duyệt khi:
- bài không ở trạng thái chờ duyệt, chưa có nội dung, hoặc đang có workflow AI chạy;
- chưa chạy đánh giá chất lượng cho phiên bản hiện tại, hoặc điểm < `min_quality_score_for_approval` (50);
- kiểm tra trùng lặp đề xuất loại và người dùng chưa chọn quyết định;
- phiên bản hiện tại còn nội dung giữ chỗ (`placeholder_content`, v1.0.6): ≥ 2 ô bảng kiểu "Cần xác minh" hoặc ≥ 2 câu nói về việc thiếu nguồn. Viết thận trọng là bỏ ý không có bằng chứng, không phải thay nó bằng "cần xác minh".

Cần xác nhận (`acknowledge_high_risk`, mục chặn có `requires_ack`; có xác nhận thì chuyển thành cảnh báo):
- còn claim **CRITICAL** chưa `VERIFIED`;
- còn claim **HIGH** chưa xác minh khi `require_ack_for_high_risk` bật.

Cảnh báo (không chặn): section đã sửa nhưng chưa kiểm chứng lại; claim `INSUFFICIENT_EVIDENCE`/`PENDING`.

Lần duyệt lưu `checks.acknowledged_high_risk` + toàn bộ cảnh báo vào `editorial_approvals` và audit `writer.article_approved`, nên luôn biết ai đã duyệt bài còn cảnh báo. Cổng `fact_check` khi đăng WordPress (PublishingGates) cũng chỉ ghi cảnh báo (`details.warning = true`), không chặn.

Điểm chất lượng **không bao giờ** là điều kiện duyệt duy nhất. Mọi chỉnh sửa sau khi duyệt làm mất hiệu lực phê duyệt.

## 6. Tự duyệt (tùy chọn)
Code: `Pipeline/AutoApprover.php`. Mặc định **tắt**.

- Super Admin bật `auto_approve_enabled` (cho cả nền tảng hoặc từng workspace) và đặt `auto_approve_min_quality` (mặc định 75).
- Khách chọn `approval_mode = auto` cho từng bài (wizard, bulk, hoặc đổi ở tab Tổng quan). Chọn `auto` khi chính sách đang tắt → 422.
- Sau khi workflow viết hoặc kiểm chứng lại chạy xong, hệ thống chỉ tự duyệt khi **đủ tất cả** điều kiện:
  - ApprovalGate (§5) không còn mục chặn, và **không** dùng xác nhận rủi ro;
  - không còn claim CRITICAL hoặc HIGH chưa xác minh, không có section chưa kiểm chứng lại, không có claim `CONTRADICTED`/`OUTDATED` (tự duyệt vẫn nghiêm ngặt: không có người thì không có gì chưa kiểm chứng được duyệt);
  - điểm chất lượng ≥ max(`auto_approve_min_quality`, `min_quality_score_for_approval`);
  - kiểm tra trùng lặp đề xuất `create_new` (hoặc chưa có kết quả trùng).
- Không đủ điều kiện → bài ở `NEEDS_HUMAN_REVIEW`, lý do lưu trong `settings.auto_approve_result.reasons` và hiện ở tab Tổng quan.
- Phê duyệt tự động ghi `editorial_approvals` với `user_id = null`, `checks.auto = true`, audit `writer.article_auto_approved`. Mọi chỉnh sửa sau đó làm mất hiệu lực giống phê duyệt của người.
- Tự duyệt **không** đăng bài lên WordPress.
