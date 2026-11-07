<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

/**
 * Ready-made writing styles (v1.0.9). Each one is a style profile in the
 * same shape a learned style has (StyleLearner fields), so the writer
 * treats both identically. Display names/descriptions live in the SPA
 * locale files (writer.builtin.<key>); this text is only for the model.
 * A style describes HOW to write, never facts.
 */
final class BuiltinStyles
{
    public const KEYS = ['friendly', 'expert', 'step_by_step', 'sales', 'storytelling', 'news', 'formal'];

    /**
     * @return array<string, mixed>|null
     */
    public static function profile(?string $key): ?array
    {
        return $key !== null ? (self::all()[$key] ?? null) : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'friendly' => [
                'summary' => 'Thân thiện, gần gũi như đang trò chuyện với một người bạn; dễ đọc, không hàn lâm.',
                'tone' => 'Ấm áp, tích cực, tự nhiên.',
                'addressing' => 'Xưng "mình", gọi người đọc là "bạn".',
                'sentence_style' => 'Câu ngắn đến vừa, dùng từ thông dụng; giải thích thuật ngữ ngay khi xuất hiện.',
                'paragraph_style' => 'Đoạn 2–4 câu, nhiều khoảng thở.',
                'intro_pattern' => 'Mở bằng một tình huống đời thường hoặc câu hỏi người đọc hay gặp.',
                'conclusion_pattern' => 'Tóm lại ý chính trong 2–3 câu và một lời khuyên nhẹ nhàng.',
                'heading_style' => 'Heading dạng câu hỏi hoặc lợi ích rõ ràng.',
                'formatting' => 'Dùng gạch đầu dòng khi liệt kê; in đậm ý quan trọng vừa phải.',
                'dos' => ['Đưa ví dụ đời thường', 'Giải thích đơn giản'],
                'donts' => ['Dùng thuật ngữ khó mà không giải thích', 'Giọng ra lệnh'],
            ],
            'expert' => [
                'summary' => 'Giọng chuyên gia: chắc chắn, chính xác, có dẫn chứng và nguồn rõ ràng.',
                'tone' => 'Điềm tĩnh, khách quan, đáng tin cậy.',
                'addressing' => 'Gọi người đọc là "bạn"; tránh xưng "tôi" quá nhiều.',
                'sentence_style' => 'Câu rõ nghĩa, dùng đúng thuật ngữ chuyên ngành kèm giải thích ngắn.',
                'paragraph_style' => 'Đoạn 3–5 câu, mỗi đoạn một luận điểm.',
                'intro_pattern' => 'Nêu vấn đề và vì sao nó quan trọng, kèm một dữ kiện có nguồn.',
                'conclusion_pattern' => 'Tổng kết các điểm chính và khuyến nghị cụ thể.',
                'heading_style' => 'Heading mô tả nội dung chính xác, không giật tít.',
                'formatting' => 'Dùng bảng hoặc danh sách để so sánh; trích nguồn sau các số liệu.',
                'dos' => ['Chỉ dùng số liệu có nguồn', 'Nêu ưu và nhược điểm'],
                'donts' => ['Khẳng định tuyệt đối không có căn cứ', 'Từ ngữ cảm tính, phóng đại'],
            ],
            'step_by_step' => [
                'summary' => 'Hướng dẫn từng bước rõ ràng để người đọc làm theo được ngay.',
                'tone' => 'Rõ ràng, thực tế, khích lệ.',
                'addressing' => 'Gọi người đọc là "bạn".',
                'sentence_style' => 'Câu ngắn, bắt đầu bằng động từ hành động ("Mở…", "Chọn…").',
                'paragraph_style' => 'Mỗi bước 1–3 câu; ghi chú và lưu ý tách riêng.',
                'intro_pattern' => 'Nói ngắn gọn người đọc sẽ làm được gì và cần chuẩn bị gì.',
                'conclusion_pattern' => 'Tóm tắt các bước và các lỗi thường gặp cần tránh.',
                'heading_style' => 'Đánh số bước: "Bước 1: …".',
                'formatting' => 'Danh sách đánh số cho các bước; hộp "Lưu ý" cho cảnh báo.',
                'dos' => ['Mỗi bước một hành động', 'Nêu kết quả mong đợi sau mỗi bước'],
                'donts' => ['Gộp nhiều thao tác vào một câu dài', 'Bỏ qua bước chuẩn bị'],
            ],
            'sales' => [
                'summary' => 'Thuyết phục, tập trung vào vấn đề của khách và lợi ích của giải pháp, kết thúc bằng lời kêu gọi.',
                'tone' => 'Nhiệt tình, tự tin nhưng trung thực.',
                'addressing' => 'Gọi người đọc là "bạn".',
                'sentence_style' => 'Câu ngắn, mạnh; nhấn vào lợi ích thay vì thông số.',
                'paragraph_style' => 'Đoạn ngắn 1–3 câu.',
                'intro_pattern' => 'Chạm vào vấn đề hoặc mong muốn của người đọc.',
                'conclusion_pattern' => 'Tóm lợi ích chính và lời kêu gọi hành động rõ ràng.',
                'heading_style' => 'Heading nêu lợi ích.',
                'formatting' => 'Gạch đầu dòng cho lợi ích; in đậm điểm nổi bật.',
                'dos' => ['Theo mạch vấn đề → giải pháp → lợi ích → kêu gọi', 'Chỉ nêu lợi ích có thật'],
                'donts' => ['Hứa hẹn quá mức', 'Bịa khuyến mãi, giá hoặc đánh giá'],
            ],
            'storytelling' => [
                'summary' => 'Kể chuyện: dẫn dắt bằng một câu chuyện hoặc tình huống, tạo cảm xúc rồi mới đến thông tin.',
                'tone' => 'Gần gũi, giàu hình ảnh, có cảm xúc.',
                'addressing' => 'Xưng "mình" hoặc ngôi thứ ba khi kể; gọi người đọc là "bạn".',
                'sentence_style' => 'Câu đa dạng độ dài, có hình ảnh cụ thể.',
                'paragraph_style' => 'Đoạn ngắn đến vừa, chuyển cảnh mượt.',
                'intro_pattern' => 'Mở bằng một khoảnh khắc hoặc câu chuyện nhỏ (giả định rõ là ví dụ, không bịa nhân vật có thật).',
                'conclusion_pattern' => 'Khép lại câu chuyện và rút ra bài học cho người đọc.',
                'heading_style' => 'Heading gợi tò mò nhưng vẫn rõ nội dung.',
                'formatting' => 'Ít danh sách; dùng trích dẫn ngắn để nhấn mạnh.',
                'dos' => ['Gắn câu chuyện với thông tin hữu ích'],
                'donts' => ['Bịa trải nghiệm hoặc nhân vật có thật', 'Kể lan man xa chủ đề'],
            ],
            'news' => [
                'summary' => 'Tin tức ngắn gọn: ý chính lên đầu, khách quan, dễ lướt.',
                'tone' => 'Trung lập, khách quan.',
                'addressing' => 'Không xưng hô; viết ở ngôi thứ ba.',
                'sentence_style' => 'Câu ngắn, rõ chủ ngữ; trả lời ai, cái gì, khi nào, ở đâu, vì sao.',
                'paragraph_style' => 'Đoạn 1–3 câu, quan trọng nhất trước (kim tự tháp ngược).',
                'intro_pattern' => 'Câu mở tóm toàn bộ tin chính.',
                'conclusion_pattern' => 'Thông tin bối cảnh hoặc bước tiếp theo; không bình luận chủ quan.',
                'heading_style' => 'Heading ngắn, mang thông tin.',
                'formatting' => 'Ít định dạng; số liệu kèm nguồn.',
                'dos' => ['Nêu nguồn cho mọi thông tin'],
                'donts' => ['Ý kiến cá nhân', 'Giật tít'],
            ],
            'formal' => [
                'summary' => 'Trang trọng, lịch sự, chuẩn mực như văn bản doanh nghiệp.',
                'tone' => 'Lịch sự, chuyên nghiệp, tôn trọng.',
                'addressing' => 'Xưng "chúng tôi", gọi người đọc là "Quý khách" hoặc "Quý doanh nghiệp".',
                'sentence_style' => 'Câu hoàn chỉnh, ngữ pháp chuẩn, không dùng từ lóng hay biểu tượng cảm xúc.',
                'paragraph_style' => 'Đoạn 3–5 câu, mạch lạc.',
                'intro_pattern' => 'Giới thiệu mục đích bài viết một cách trang trọng.',
                'conclusion_pattern' => 'Tóm tắt và lời mời liên hệ lịch sự.',
                'heading_style' => 'Heading danh từ, trang trọng.',
                'formatting' => 'Danh sách khi cần trình bày quy trình hoặc điều khoản.',
                'dos' => ['Dùng từ ngữ chuẩn mực'],
                'donts' => ['Từ lóng, viết tắt', 'Giọng suồng sã'],
            ],
        ];
    }
}
