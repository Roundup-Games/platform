<?php

/*
|--------------------------------------------------------------------------
| Escalated requester mails — Roundup copy (M081 follow-up)
|--------------------------------------------------------------------------
|
| Copy for the four requester-facing escalated ticket mails. The S03
| view overrides replaced the vendor's translatable keys with hardcoded
| English, which silently dropped localization for German users filing
| tickets. These keys restore the Roundup voice in both locales; the
| override views resolve the requester's preferred_language and pass it
| explicitly to __(). Agent-facing surfaces (assigned) stay EN-only per
| the K002 exemption.
|
*/

return [
    // ── new-ticket ──────────────────────────────────────────────
    'heading_new_ticket' => 'We received your ticket',
    'body_new_ticket_intro' => 'Thanks for reaching out to :brand Support. Your ticket is in and our team is on it.',
    'body_new_ticket_follow' => 'You can follow every update on the ticket page.',

    // ── reply ───────────────────────────────────────────────────
    'heading_ticket_reply' => 'New reply on :reference',
    'body_ticket_reply_intro' => 'A new reply has been added to your support ticket.',

    // ── resolved ────────────────────────────────────────────────
    'heading_ticket_resolved' => 'Your ticket is resolved',
    'body_ticket_resolved_intro' => 'We have marked your support ticket as resolved.',
    'body_ticket_resolved_follow' => 'If anything is still not right, reply on the ticket page — that reopens the ticket and puts it back with our team.',

    // ── status-changed ──────────────────────────────────────────
    'heading_ticket_status_changed' => 'Your ticket status changed',
    'body_ticket_status_changed_intro' => 'Here is the latest on your support ticket.',
    'body_ticket_status_changed_follow' => 'If something looks off, reply on the ticket page and our team will pick it back up.',

    // ── shared field labels & chrome ────────────────────────────
    'field_reference' => 'Reference',
    'field_subject' => 'Subject',
    'field_priority' => 'Priority',
    'field_previous_status' => 'Previous status',
    'field_new_status' => 'New status',
    'action_view_ticket' => 'View Ticket',
    'content_signoff' => 'Thanks',
];
