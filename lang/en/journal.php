<?php

// Campaign journal copy (M071). Flat, prefixed, snake_case keys per the
// i18n naming convention (php artisan i18n:check). type_* and visibility_*
// mirror App\Enums\JournalEntryKind / JournalEntryVisibility (type_* follows
// the GameType enum-label precedent); the session-end journal composer
// copy (title_composer/content_composer_description/label_composer_placeholder/
// action_composer_submit) landed with S01 — later slices append the journal
// tab surface. status_* mirrors App\Enums\GoalStatus and
// type_progression/type_item/type_condition/type_note mirror
// App\Enums\CharacterStateChangeType (M071/S02); status_ is a recognized
// LangFileParser prefix.

return [
    'type_recap' => 'Recap',
    'type_in_character' => 'In-character',
    'type_prep_note' => 'GM prep note',
    'visibility_players' => 'Players',
    'visibility_gms' => 'GMs',
    'error_not_campaign_session' => 'A journal recap can only be written for a campaign session.',
    'error_recap_too_long' => 'Recap must not exceed 10000 characters.',
    'title_composer' => 'Write a Journal Recap',
    'content_composer_description' => 'Your recap becomes part of the campaign journal — share what happened in this session with your players.',
    'label_composer_placeholder' => 'Share what happened in this session…',
    'action_composer_submit' => 'Publish to Journal',
    'status_planned' => 'Planned',
    'status_active' => 'Active',
    'status_achieved' => 'Achieved',
    'status_failed' => 'Failed',
    'status_abandoned' => 'Abandoned',
    'type_progression' => 'Progression',
    'type_item' => 'Item',
    'type_condition' => 'Condition',
    'type_note' => 'Note',
    'error_not_participant' => 'Only campaign participants can write this journal content.',
    'error_participant_not_active' => 'Only approved or benched participants can write in-character journal entries.',
    'error_not_campaign_owner' => 'Only the campaign GM can do this.',
    'error_body_empty' => 'The journal entry cannot be empty.',
    'error_body_too_long' => 'Journal entries must not exceed 10000 characters.',
    'error_in_game_date_too_long' => 'The in-game date must not exceed 100 characters.',
    'error_title_required' => 'A goal needs a title.',
    'error_title_too_long' => 'Goal titles must not exceed 200 characters.',
    'error_description_too_long' => 'Goal descriptions must not exceed 1000 characters.',
    'error_delta_empty' => 'The state change cannot be empty.',
    'error_delta_too_long' => 'State changes must not exceed 2000 characters.',
    'tab_overview' => 'Overview',
    'tab_journal' => 'Journal',
    'filter_all' => 'All',
    'filter_recap' => 'Recaps',
    'filter_in_character' => 'In-character',
    'filter_prep_note' => 'GM Notes',
    'empty_journal' => 'No journal entries yet.',
    'action_newer_entries' => 'Newer',
    'action_older_entries' => 'Older',
    'label_filter_group' => 'Filter journal entries',
    'label_unknown_author' => 'Former member',
    'heading_goals' => 'Campaign Goals',
    'heading_state_changes' => 'Character States',
    'label_goal_title' => 'Goal title',
    'label_goal_description' => 'Description (optional)',
    'label_state_participant' => 'Character',
    'label_state_choose_participant' => 'Choose a character',
    'label_state_change_type' => 'Change type',
    'label_state_choose_type' => 'Choose a type',
    'label_state_delta' => 'State change',
    'label_in_game_date' => 'In-game date (optional)',
    'action_add_goal' => 'Add goal',
    'action_change_status' => 'Update status',
    'action_post_in_character' => 'Post in-character',
    'action_post_prep_note' => 'Save prep note',
    'action_log_state' => 'Log state change',
    'action_show_all' => 'Show all',
    'action_load_earlier_changes' => 'Load earlier changes',
    'empty_goals' => 'No goals yet.',
    'empty_state_changes' => 'No state changes yet.',
    'hint_prep_note' => 'Only visible to GMs — players never see prep notes.',
    'flash_in_character_posted' => 'Posted to the journal.',
    'flash_prep_note_posted' => 'Prep note saved.',
    'flash_goal_created' => 'Goal added.',
    'flash_goal_status_changed' => 'Goal status updated.',
    'flash_state_change_logged' => 'State change logged.',

    // Previously On composition copy (M071/S04, spec §3.1): the digest's
    // plain-text sections and the publish guards. :session is the
    // completed-sessions count; the composed body is plain text only.
    'title_previously_on' => 'Previously on :campaign — Session :session',
    'section_goals_moved' => 'Goals moved',
    'section_loose_threads' => 'Loose threads',
    'section_next_session' => 'Next session',
    'error_draft_already_published' => 'This Previously On digest has already been published.',
    'error_session_not_publishable' => 'Previously On can only be published for an upcoming campaign session.',

    // Previously On panel copy (M071/S04-T03): the GM's Journal-tab review
    // surface — draft preview, one-click publish, member gate, published
    // confirmation. The gate keys use title_/content_ prefixes (the gate_
    // prefix is not recognized by the i18n checker).
    'title_previously_on_panel' => 'Previously On',
    'content_previously_on_description' => 'A digest of your latest journal happenings, composed for the next session. Review it, then share it with your table.',
    'action_publish_previously_on' => 'Publish Previously On',
    'flash_previously_on_published' => 'Previously On published to the session bulletin board.',
    'error_previously_on_not_available' => 'Previously On is not available right now.',
    'label_previously_on_target_session' => 'For session',
    'label_previously_on_published_at' => 'Published on :date',
    'label_previously_on_composed_at' => 'Composed :date',
    'action_refresh_previously_on' => 'Refresh draft',
    'flash_previously_on_refreshed' => 'Draft refreshed from the journal.',
    'error_previously_on_refresh_failed' => 'The draft could not be refreshed — please try again.',
    'content_previously_on_audience' => 'Delivered to all approved participants of this session, including one-off guests.',
    'title_previously_on_gate' => 'Previously On is a member feature',
    'content_previously_on_gate' => 'GM members get an automatic Previously On digest before every session — recap, goals, and loose threads from your campaign journal. View your membership options to unlock it.',
    'action_view_membership' => 'View membership',

    // Recruitment summary copy (M071/S05, spec §4): the auto-draft lines
    // and write guards for the spoiler-safe "Campaign so far" block.
    // :count is the completed-sessions count; :themes carries the charter
    // tone/theme chips (labelled through the existing charter.* keys).
    'content_recruitment_sessions_played' => ':count sessions played',
    'content_recruitment_tone_themes' => 'Tone & themes: :themes',
    'error_recruitment_unauthorized' => 'Only the campaign GM can manage the recruitment summary.',
    'error_recruitment_checklist_incomplete' => 'Confirm every spoiler-safety check before applying the recruitment summary.',
    'error_recruitment_draft_required' => 'The recruitment summary cannot be empty.',
    'error_recruitment_draft_too_long' => 'The recruitment summary must not exceed 10000 characters.',

    // Recruitment composer copy (M071/S05-T03, spec §4.1/§4.2): the GM's
    // Journal-tab composer — spoiler-safety checklist, goal/excerpt
    // pickers, the editable draft, and the in-composer visitor preview
    // (the real public page redirects authenticated users). :count is
    // the stale-session / refreshed-session count; :session is the
    // excerpt label's session date.
    'action_recruit_with_campaign' => 'Recruit with this campaign',
    'action_apply_recruitment_summary' => 'Publish to the campaign page',
    'action_remove_recruitment_summary' => 'Remove from the campaign page',
    'action_copy_recruitment_summary' => 'Copy draft',
    'action_redact_selection' => 'Redact selection',
    'action_preview_as_visitor' => 'Preview as a visitor',
    'action_append_excerpt' => 'Add excerpt',
    'action_copy_public_link' => 'Copy public link',
    'heading_recruitment_composer' => 'Recruitment summary',
    'section_recruitment_checklist' => 'Spoiler-safety checklist',
    'section_recruitment_goals' => 'Goals you consider safe to share',
    'section_recruitment_excerpts' => 'Recap excerpts',
    'label_recruitment_draft' => 'Your "Campaign so far" draft',
    'label_recruitment_excerpt' => 'From session :session',
    'confirmation_recruitment_review_excerpts' => 'I reviewed every excerpt for spoilers.',
    'confirmation_recruitment_names_removed' => 'All player names and identifying details are removed.',
    'confirmation_recruitment_visitor_preview' => 'I checked the visitor preview — this is what I want strangers to read.',
    'hint_recruitment_public' => 'This becomes public. Visitors considering your table will read it.',
    'hint_recruitment_open_seats' => 'Your campaign is public with open seats — a summary helps recruiting.',
    'hint_recruitment_stale' => ':count new sessions have been played since your summary was last refreshed.',
    'empty_recruitment_no_goals' => 'No active goals yet.',
    'empty_recruitment_no_recaps' => 'No recap entries yet.',
    'flash_recruitment_applied' => 'The "Campaign so far" block is live on your campaign page.',
    'flash_recruitment_removed' => 'The "Campaign so far" block was removed from your campaign page.',
];
