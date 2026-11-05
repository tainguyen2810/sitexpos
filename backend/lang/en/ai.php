<?php

return [
    'errors' => [
        'budget_exceeded' => 'The AI budget limit has been reached. The task was paused according to the budget settings.',
        'refused' => 'The AI model declined this request.',
        'billing_uncertain' => 'The AI provider did not answer in time and the call may have been billed. It was not retried automatically; retry manually if needed.',
        'invalid_output' => 'The AI output did not match the required format.',
        'all_failed' => 'Every eligible AI provider failed or is temporarily paused.',
        'route_not_found' => 'No routing is configured for this task type.',
        'input_too_large' => 'The content sent to the AI exceeds the allowed limit.',
        'idempotency_conflict' => 'This Idempotency-Key was already used for a different request.',
        'price_version_locked' => 'A price version that is already in effect cannot be changed or deleted. Create a new version.',
        'model_in_use' => 'The model is used by a task route.',
        'duplicate_call' => 'This AI call was already made; it is not sent again to avoid double charging.',
    ],
];
