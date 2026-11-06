<?php

return [
    'errors' => [
        'platform_kill_switch' => 'The SEO Agent is paused platform-wide by an administrator.',
        'emergency_stop' => 'The SEO Agent for this website is emergency-stopped.',
        'disabled' => 'The SEO Agent is not enabled for this website.',
        'busy' => 'Too many agent runs are in progress. Try again later.',
        'run_active' => 'An agent run is already in progress for this website.',
        'run_not_active' => 'This run has ended.',
        'run_not_paused' => 'This run is not paused.',
        'task_not_awaiting' => 'The task is not waiting for approval.',
        'task_not_retryable' => 'This task cannot be retried.',
        'approver_lacks_permission' => 'You do not have the permission this task requires.',
        'policy_denied' => 'The agent policy does not allow this task (:reasons).',
        'config_conflict' => 'The configuration was changed by someone else. Reload the page.',
        'level3_confirmation_required' => 'Autonomy level 3 requires your explicit confirmation.',
        'content_not_human_approved' => 'Content must be approved by a person before the WordPress update.',
        'unknown_tool' => 'The tool is not in the allowed list.',
    ],
    'levels' => [
        0 => 'Level 0 — Monitor: read data and report only.',
        1 => 'Level 1 — Recommend: create proposals and run SEO audits; every change waits for a person (default).',
        2 => 'Level 2 — Prepare: draft refresh briefs and content, fact-check; a person approves content and publishing.',
        3 => 'Level 3 — Controlled automation: also runs the WordPress update for content a person ALREADY approved, within the window and budget. Only with your confirmation.',
    ],
];
