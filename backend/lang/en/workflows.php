<?php

return [
    'errors' => [
        'interrupted_paid_call' => 'The run was interrupted during a paid API call. It is not called again automatically to avoid being billed twice; retry manually.',
        'not_cancelable' => 'The task has already finished and cannot be canceled.',
        'not_retryable' => 'Only failed tasks can be retried.',
    ],
];
