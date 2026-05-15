<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Support Ticket Language Lines
    |--------------------------------------------------------------------------
    |
    | Strings for account recovery, billing support, and general support
    | ticket flows using the Escalated helpdesk integration.
    |
    */

    // Account Support page
    'title_account_support' => 'Account Support',
    'description_account_support' => 'Having trouble with your account? Let us know and we\'ll help you get it sorted.',

    // Billing Support page
    'title_billing_support' => 'Billing Support',
    'description_billing_support' => 'Having trouble with a payment or subscription? Submit a ticket and our billing team will help.',

    // Shared
    'title_need_help' => 'Need Help?',
    'content_having_billing_issues' => 'Having issues with a payment, refund, or subscription?',
    'content_contact_our_support_team' => 'Having trouble with your account? Our support team can help.',
    'content_billing_help' => 'Billing Help',
    'content_billing_team_responds' => 'Our billing team typically responds within 24 hours. For urgent payment issues, we prioritize faster resolution.',
    'content_account_support_team_responds' => 'Our account support team typically responds within 24 hours.',
    'content_for_billing_issues_visit_billing' => 'For payment or subscription issues, visit the billing support page from your billing settings.',

    // Contact form category
    'field_category' => 'Category',
    'category_general' => 'General Inquiry',
    'category_account_recovery' => 'Account Recovery',

    // Form fields
    'field_issue_type' => 'Issue Type',
    'field_subject' => 'Subject',
    'field_description' => 'Description',

    // Placeholders
    'placeholder_subject' => 'Briefly describe your issue',
    'placeholder_description' => 'Please provide as much detail as possible about your issue...',
    'placeholder_billing_subject' => 'e.g., Payment failed, Refund request, Subscription issue',
    'placeholder_billing_description' => 'Please describe your billing issue, including any relevant transaction details...',

    // Actions
    'action_submit_ticket' => 'Submit Support Ticket',
    'action_submit_billing_ticket' => 'Submit Billing Ticket',
    'action_contact_billing_support' => 'Billing Support',
    'action_contact_support' => 'Contact Support',

    // Flash messages
    'flash_ticket_submitted' => 'Your support ticket has been submitted. We\'ll get back to you soon!',
    'flash_billing_ticket_submitted' => 'Your billing support ticket has been submitted. Our billing team will review it shortly.',

    // Validation
    'validation_subject_required' => 'Please provide a subject for your ticket.',
    'validation_description_required' => 'Please describe your issue.',
    'validation_description_max' => 'Description must be less than 5000 characters.',
    'validation_issue_type_required' => 'Please select an issue type.',
    'validation_issue_type_invalid' => 'The selected issue type is not valid.',

    // Rate limit
    'error_rate_limit' => 'You\'ve submitted too many tickets. Please try again in :minutes minutes.',

    // Account issue types
    'field_issue_account_access' => 'Account Access',
    'field_issue_login_issue' => 'Login Issue',
    'field_issue_name_change' => 'Name Change Request',
    'field_issue_email_change' => 'Email Change Request',
    'field_issue_suspended_account' => 'Suspended Account',
    'field_issue_data_request' => 'Data / Export Request',
    'field_issue_other' => 'Other',

    // Billing issue types
    'field_billing_issue_payment' => 'Payment Issue',
    'field_billing_issue_refund' => 'Refund Request',
    'field_billing_issue_subscription_change' => 'Subscription Change',
    'field_billing_issue_invoice' => 'Invoice Question',
    'field_billing_issue_cancellation' => 'Cancellation Issue',
    'field_billing_issue_other' => 'Other',
];
