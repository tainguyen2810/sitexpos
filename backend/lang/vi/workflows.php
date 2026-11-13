<?php

return [
    'errors' => [
        'interrupted_paid_call' => 'Tiến trình bị gián đoạn khi đang gọi API tính phí. Hệ thống không tự gọi lại để tránh bị tính phí hai lần; hãy thử lại thủ công.',
        'too_many_yields' => 'Tiến trình phải chia quá nhiều lượt mà vẫn chưa xong; đã dừng để tránh lặp vô hạn. Hãy thử lại thủ công.',
        'not_cancelable' => 'Tác vụ đã kết thúc, không thể hủy.',
        'not_retryable' => 'Chỉ thử lại được tác vụ đã thất bại.',
    ],
];
