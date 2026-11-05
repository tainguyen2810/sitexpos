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
        'model_in_use' => 'The model has already been called or is used by a task route. Disable it instead of deleting it.',
        'provider_builtin' => 'Built-in providers cannot be deleted; you can disable them.',
        'provider_in_use' => 'The provider still has models. Delete or move them first.',
        'builtin_endpoint_locked' => 'The API address of a built-in provider is configured in .env and cannot be changed from the UI.',
        'blocked_url' => 'This API address is not allowed (:reason). Only https to a public address is accepted.',
        'provider_not_configured' => 'The provider has no API key.',
        'peak_window_order' => 'The end time must be after the start time (use 24:00 for midnight).',
        'duplicate_call' => 'This AI call was already made; it is not sent again to avoid double charging.',
    ],
];
