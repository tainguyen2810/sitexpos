<?php

return [
    'errors' => [
        'platform_kill_switch' => 'SEO Agent đang bị quản trị viên nền tảng tạm dừng toàn hệ thống.',
        'emergency_stop' => 'SEO Agent của website này đang ở trạng thái dừng khẩn cấp.',
        'disabled' => 'SEO Agent chưa được bật cho website này.',
        'busy' => 'Hệ thống đang chạy quá nhiều lượt agent. Vui lòng thử lại sau.',
        'run_active' => 'Website này đang có một lượt agent chạy.',
        'run_not_active' => 'Lượt chạy này đã kết thúc.',
        'run_not_paused' => 'Lượt chạy này không ở trạng thái tạm dừng.',
        'task_not_awaiting' => 'Tác vụ không ở trạng thái chờ duyệt.',
        'task_not_retryable' => 'Không thể thử lại tác vụ này.',
        'approver_lacks_permission' => 'Bạn không có quyền thực hiện thao tác mà tác vụ này cần.',
        'policy_denied' => 'Chính sách agent không cho phép tác vụ này (:reasons).',
        'config_conflict' => 'Cấu hình đã được người khác thay đổi. Hãy tải lại trang.',
        'level3_confirmation_required' => 'Cấp tự chủ 3 cần bạn xác nhận rõ ràng trước khi bật.',
        'content_not_human_approved' => 'Nội dung phải được người duyệt trước khi cập nhật lên WordPress.',
        'unknown_tool' => 'Công cụ không có trong danh sách được phép.',
    ],
    'levels' => [
        0 => 'Cấp 0 — Giám sát: chỉ đọc dữ liệu và báo cáo.',
        1 => 'Cấp 1 — Đề xuất: tạo đề xuất và chạy SEO Audit; mọi thay đổi chờ người duyệt (mặc định).',
        2 => 'Cấp 2 — Chuẩn bị: soạn brief và bản nháp làm mới, fact-check; người duyệt nội dung và đăng.',
        3 => 'Cấp 3 — Tự động có kiểm soát: thêm bước cập nhật WordPress cho nội dung ĐÃ được người duyệt, trong khung giờ và ngân sách cho phép. Chỉ bật khi bạn xác nhận.',
    ],
];
