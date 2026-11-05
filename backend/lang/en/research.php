<?php

return [
    'errors' => [
        'concurrency_limit' => 'This workspace can run at most :max research job(s) at the same time.',
        'quota_exceeded' => 'All :max research runs of this month have been used.',
        'campaign_limit' => 'This workspace reached its limit of :max campaigns.',
        'competitor_limit' => 'This campaign reached its limit of :max competitors.',
        'ideas_limit' => 'At most :max ideas per research run.',
        'calendar_limit' => 'The calendar limit of :max items this month was reached.',
        'in_progress' => 'A research run is already in progress for this campaign.',
        'campaign_not_active' => 'The campaign is paused or archived.',
        'campaign_archived' => 'The campaign is archived.',
        'site_missing' => 'The campaign website no longer exists.',
        'not_cancelable' => 'This research run cannot be canceled (already finished).',
        'not_retryable' => 'Only failed research runs can be retried.',
        'idempotency_conflict' => 'Idempotency-Key reused for a different request.',
        'competitor_exists' => 'This competitor is already in the campaign.',
        'competitor_is_own_site' => 'Your own website cannot be a competitor.',
        'blocked_url' => 'URL not allowed (:reason). Only public websites are accepted.',
        'already_scheduled' => 'This idea is already on the calendar.',
        'idea_rejected' => 'A rejected idea cannot be scheduled.',
        'gsc_not_configured' => 'Google OAuth is not configured on the server (GOOGLE_CLIENT_ID/SECRET).',
        'gsc_invalid_state' => 'The Google connection session is invalid or expired.',
        'gsc_not_connected' => 'This website is not connected to Google Search Console.',
        'gsc_property_unknown' => 'The Google account has no access to this property.',
    ],
    'notifications' => [
        'opportunities_title' => ':count new SEO opportunities for ":campaign"',
    ],
];
