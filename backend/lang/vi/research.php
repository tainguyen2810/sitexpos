<?php

return [
    'errors' => [
        'concurrency_limit' => 'Workspace đang có tối đa :max nghiên cứu chạy cùng lúc. Hãy chờ nghiên cứu hiện tại hoàn tất.',
        'quota_exceeded' => 'Đã dùng hết :max lượt nghiên cứu của tháng này.',
        'campaign_limit' => 'Workspace đã đạt giới hạn :max chiến dịch.',
        'competitor_limit' => 'Chiến dịch đã đạt giới hạn :max đối thủ.',
        'ideas_limit' => 'Số ý tưởng mỗi lần nghiên cứu tối đa là :max.',
        'calendar_limit' => 'Đã đạt giới hạn :max bài trong lịch của tháng này.',
        'in_progress' => 'Chiến dịch đang có một nghiên cứu chạy.',
        'campaign_not_active' => 'Chiến dịch đang tạm dừng hoặc đã lưu trữ.',
        'campaign_archived' => 'Chiến dịch đã lưu trữ.',
        'site_missing' => 'Website của chiến dịch không còn tồn tại.',
        'not_cancelable' => 'Nghiên cứu này không thể hủy (đã kết thúc).',
        'not_retryable' => 'Chỉ có thể chạy lại nghiên cứu bị lỗi.',
        'idempotency_conflict' => 'Idempotency-Key đã được dùng cho yêu cầu khác.',
        'competitor_exists' => 'Đối thủ này đã có trong chiến dịch.',
        'competitor_is_own_site' => 'Không thể thêm chính website của bạn làm đối thủ.',
        'blocked_url' => 'Địa chỉ không được phép (:reason). Chỉ chấp nhận website công khai.',
        'already_scheduled' => 'Ý tưởng này đã có trong lịch.',
        'idea_rejected' => 'Không thể lên lịch ý tưởng đã loại bỏ.',
        'gsc_not_configured' => 'Máy chủ chưa cấu hình Google OAuth (GOOGLE_CLIENT_ID/SECRET).',
        'gsc_invalid_state' => 'Phiên kết nối Google không hợp lệ hoặc đã hết hạn.',
        'gsc_not_connected' => 'Website chưa kết nối Google Search Console.',
        'gsc_property_unknown' => 'Tài khoản Google không có quyền với property này.',
    ],
    'notifications' => [
        'opportunities_title' => 'Phát hiện :count cơ hội SEO mới cho ":campaign"',
    ],
];
