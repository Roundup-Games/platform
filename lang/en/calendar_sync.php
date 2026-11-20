<?php

return [
    // ── Heading & intro ───────────────────────────────
    'heading_connect' => 'Google Calendar Sync',
    'content_intro' => 'Roundup writes your confirmed games, campaign sessions, and event registrations into your own Google Calendar — so the commitments you make here show up where you already plan your time.',
    // ── Per-source toggles ────────────────────────────
    'label_source_games' => 'Games',
    'description_source_games' => 'Confirmed games you attend appear as calendar events.',
    'label_source_campaign_sessions' => 'Campaign sessions',
    'description_source_campaign_sessions' => 'Sessions of campaigns you play in appear as calendar events.',
    'label_source_events' => 'Events',
    'description_source_events' => 'Your event registrations appear as calendar events.',
    // ── Honest-scope block ────────────────────────────
    // Claims must stay aligned with the Google user-data disclosure in
    // lang/en/privacy.php (be4d7c9a) — never promise less or more.
    'heading_scope' => 'What this sync does — and does not do',
    'content_scope_does' => 'What Roundup does: creates, updates, and cancels calendar events that mirror your Roundup commitments — and only events Roundup itself created.',
    'content_scope_never_reads' => 'What Roundup never does: read, store, or share events you created elsewhere — and it never analyzes your calendar.',
    'content_scope_never_notifies' => 'Your guests are never notified: sync changes never trigger Google Calendar notification emails.',
    'content_scope_storage' => 'Roundup stores only the connection itself — an OAuth refresh token. Your events live in your Google Calendar, not in our database.',
    // ── Revocation consequences ───────────────────────
    'heading_disconnect' => 'If you disconnect',
    'content_disconnect_consequences' => 'When you disconnect, you choose what happens to the Roundup-managed events in your Google Calendar: keep them (they stay, but Roundup will no longer update or remove them) or have them all deleted. Either way, your games, campaigns, and event registrations on Roundup are unaffected — only the calendar mirror goes away.',
    // ── Connect CTA, disconnect choice dialog, revoked banner ─
    'action_connect' => 'Connect Google Calendar',
    'action_disconnect' => 'Disconnect',
    'confirm_disconnect' => 'Disconnect Google Calendar?',
    'label_disconnect_keep' => 'Keep events',
    'description_disconnect_keep' => 'Roundup-managed events stay in your Google Calendar, but Roundup will no longer update or remove them.',
    'label_disconnect_remove' => 'Remove Roundup events',
    'description_disconnect_remove' => 'All Roundup-managed events are deleted from your Google Calendar — past and upcoming. This can take a minute.',
    'action_disconnect_confirm' => 'Disconnect',
    'action_disconnect_cancel' => 'Cancel',
    'content_disconnecting' => 'Removing your Roundup events from Google Calendar and disconnecting — this can take a minute.',
    'content_banner_revoked' => 'Calendar sync was disconnected or its access was revoked at Google. Reconnect to start syncing again.',
    // ── Status labels ─────────────────────────────────
    'status_connected' => 'Connected',
    'status_disconnecting' => 'Disconnecting…',
    'status_revoked' => 'Access revoked',
    'status_last_sync' => 'Last sync: :time',
    // ── Gate surfaces (consumed by S02 via CalendarSyncGate) ──
    'error_connect_refused_disabled' => 'Calendar sync is not enabled for this deployment.',
    'error_connect_refused_not_allowlisted' => 'Calendar sync is not yet available for your account — it is rolling out gradually.',
    'label_mode_test_user_note' => 'Limited rollout (test mode)',
];
