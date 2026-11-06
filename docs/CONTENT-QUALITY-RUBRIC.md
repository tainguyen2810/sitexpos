# Content Quality Rubric (v1)

Code: `Pipeline/QualityScorer.php` (`RUBRIC_VERSION = 'v1'`), `Pipeline/QualityAuditor.php`. Điểm 0–100, tính bằng quy tắc xác định (không phải ý kiến model). Báo cáo lưu ở `quality_reports` theo từng phiên bản.

> Điểm chất lượng đo mức hoàn thiện của bài theo tiêu chí nội bộ. Nó **không** dự đoán hay bảo đảm thứ hạng tìm kiếm, và **không** phải điều kiện duyệt duy nhất (xem `FACT-CHECK-POLICY.md` §5).

| Tiêu chí | Tối đa | Cách tính |
|---|---|---|
| `factual_accuracy` | 20 | Tỷ lệ claim VERIFIED có trọng số rủi ro (LOW 1 … CRITICAL 4); trừ claim sai/lỗi thời |
| `evidence_coverage` | 10 | Claim MEDIUM+ có bằng chứng / tổng claim MEDIUM+ |
| `search_intent` | 10 | Từ khóa chính xuất hiện ở mở bài, khớp search intent |
| `structure` | 10 | Đúng 1 H1, số H2 hợp lý, không nhảy cấp heading |
| `keyword_usage` | 8 | Mật độ hợp lý; phạt nhồi từ khóa |
| `readability` | 8 | Độ dài câu trung bình, đoạn > 120 từ |
| `originality` | 8 | Độ tương đồng cao nhất với bài khác trong workspace |
| `repetition` | 6 | Câu lặp, cụm sáo rỗng ("trong thời đại công nghệ"…) |
| `internal_linking` | 5 | Số liên kết nội bộ |
| `citations` | 5 | Số trích dẫn từ bằng chứng |
| `brand_voice` | 5 | Cụm từ cấm/ưu tiên theo Writing Preset |
| `cta` | 3 | Có CTA cuối bài khi preset yêu cầu |
| `completeness` | 2 | Có kết luận, có FAQ nếu được yêu cầu |

Vấn đề (`issues`) mức `error`: còn claim CRITICAL chưa xác minh, chứa cụm từ bị cấm. Mức `warning`: nhồi từ khóa, rủi ro trùng lặp, claim chưa có bằng chứng, lỗi nhất quán.

Ngưỡng tối thiểu để duyệt: `min_quality_score_for_approval` (mặc định 50, Super Admin chỉnh được theo nền tảng hoặc theo workspace).
