<?php

return [
    // ── Actions ────────────────────────────────────────────────────
    'action_report' => 'Report',
    'action_submit_report' => 'Submit Report',

    // ── Titles ─────────────────────────────────────────────────────
    'title_report_content' => 'Report :type',

    // ── Content ────────────────────────────────────────────────────
    'content_report_explanation' => 'Why are you reporting this? Reports are sent to our moderation team for review.',
    'content_submitting' => 'Submitting...',

    // ── Entity type labels ─────────────────────────────────────────
    'entity_user' => 'User',
    'entity_game' => 'Game',
    'entity_campaign' => 'Campaign',

    // ── Report reasons ─────────────────────────────────────────────
    'reason_inappropriate_content' => 'Inappropriate content',
    'reason_harassment' => 'Harassment or abuse',
    'reason_spam' => 'Spam',
    'reason_misleading' => 'Misleading or false information',
    'reason_other' => 'Other',

    // ── Form labels ────────────────────────────────────────────────
    'label_description' => 'Additional details',
    'label_optional' => 'optional',
    'placeholder_description' => 'Provide any additional context (optional)',

    // ── Validation ─────────────────────────────────────────────────
    'validation_reason_required' => 'Please select a reason for reporting.',
    'validation_reason_invalid' => 'Please select a valid reason.',
    'validation_description_max' => 'Description may not be longer than 1000 characters.',

    // ── Errors ─────────────────────────────────────────────────────
    'error_rate_limit' => 'You\'ve submitted too many reports. Please try again in :minutes minutes.',
    'error_entity_not_found' => 'The reported content could not be found.',
    'error_self_report' => 'You cannot report your own content.',
    'error_already_reported' => 'You have already reported this content. Our team is reviewing it.',

    // ── Flash ──────────────────────────────────────────────────────
    'flash_report_submitted' => 'Thank you. Your report has been submitted to our moderation team.',
];
