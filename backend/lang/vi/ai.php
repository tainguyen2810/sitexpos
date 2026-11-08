<?php

return [
    'errors' => [
        'budget_exceeded' => 'Đã chạm giới hạn ngân sách AI. Tác vụ bị tạm dừng theo cấu hình ngân sách.',
        'refused' => 'Mô hình AI từ chối xử lý yêu cầu này.',
        'billing_uncertain' => 'Nhà cung cấp AI không phản hồi kịp và lời gọi có thể đã bị tính phí. Hệ thống không tự thử lại; hãy thử lại thủ công nếu cần.',
        'invalid_output' => 'Kết quả AI không đúng định dạng yêu cầu.',
        'all_failed' => 'Tất cả nhà cung cấp AI phù hợp đều lỗi hoặc tạm ngưng.',
        'route_not_found' => 'Chưa cấu hình định tuyến cho loại tác vụ này.',
        'input_too_large' => 'Nội dung gửi tới AI vượt giới hạn cho phép.',
        'idempotency_conflict' => 'Idempotency-Key này đã được dùng cho một yêu cầu khác.',
        'price_version_locked' => 'Không thể sửa hoặc xóa phiên bản giá đã có hiệu lực. Hãy tạo phiên bản mới.',
        'pinned_wrong_kind' => 'Mô hình chỉ định phải cùng loại với tác vụ (văn bản hoặc ảnh).',
        'model_in_use' => 'Mô hình đã từng được gọi hoặc đang dùng trong định tuyến tác vụ. Hãy tắt mô hình thay vì xóa.',
        'provider_builtin' => 'Không thể xóa nhà cung cấp có sẵn; bạn có thể tắt nó.',
        'provider_in_use' => 'Nhà cung cấp còn mô hình. Hãy xóa hoặc chuyển các mô hình trước.',
        'builtin_endpoint_locked' => 'Địa chỉ API của nhà cung cấp có sẵn được cấu hình trong .env, không sửa từ giao diện.',
        'blocked_url' => 'Địa chỉ API không được phép (:reason). Chỉ chấp nhận https tới địa chỉ công khai.',
        'provider_not_configured' => 'Nhà cung cấp chưa có API key.',
        'peak_window_order' => 'Giờ kết thúc phải sau giờ bắt đầu (dùng 24:00 cho nửa đêm).',
        'duplicate_call' => 'Lời gọi AI này đã được thực hiện trước đó; hệ thống không gửi lại để tránh tính phí hai lần.',
    ],
];
