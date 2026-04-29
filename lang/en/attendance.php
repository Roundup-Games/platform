<?php

return [
    // Tier labels
    'label_tier_reliable' => 'Reliable',
    'label_tier_active' => 'Active',
    'label_tier_newcomer' => 'Newcomer',
    'content_tier_description_reliable' => 'Consistent attendance across 5+ games.',
    'content_tier_description_active' => 'Regular player with room to improve.',
    'content_tier_description_newcomer' => 'New to the platform — keep playing to build your track record.',

    // Stats
    'label_stat_games_played' => 'Games Played',
    'label_stat_attendance_rate' => 'Attendance Rate',
    'label_stat_no_show_count' => 'No-Shows',
    'label_stat_attended_count' => 'Attended',
    'label_stat_late_cancel_count' => 'Late Cancels',
    'label_stat_excused_count' => 'Excused',
    'label_stat_value_percent' => ':value%',
    'label_stat_value_count' => ':count',

    // Waitlist
    'content_waitlist_position' => 'You are #:position on the waitlist.',
    'content_waitlist_spot_opened' => 'A spot opened up!',
    'action_waitlist_confirm' => 'Confirm Spot',
    'action_waitlist_decline' => 'Decline Spot',
    'content_waitlist_expired' => 'Waitlist spot expired.',
    'action_waitlist_join' => 'Join Waitlist',
    'content_waitlist_full' => 'This game is full. Join the waitlist to be notified when a spot opens up.',
    'content_waitlist_confirmed' => 'You have confirmed your spot!',
    'content_waitlist_declined' => 'You have declined the spot.',
    'content_waitlist_added' => 'You have been added to the waitlist.',
    'content_waitlist_deadline' => 'Confirm before :deadline.',
    'label_waitlist_management' => 'Waitlist',
    'content_waitlist_no_players' => 'No players on the waitlist.',

    // Bench
    'label_bench_on_the_bench' => 'On the Bench',
    'content_bench_description' => 'These players are on the bench. Promote them when a spot opens up.',
    'flash_bench_promoted' => 'Player promoted from the bench.',
    'action_bench_promote' => 'Promote',
    'content_bench_placed' => 'The session is full — you\'ve been placed on the bench.',
    'content_bench_you_are_on' => 'You\'re on the Bench',

    // Attendance actions & status labels
    'action_report_attendance' => 'Report Attendance',
    'action_dispute_report' => 'Dispute Report',
    'status_attended' => 'Attended',
    'status_no_show' => 'No Show',
    'status_late_cancel' => 'Late Cancel',
    'status_excused' => 'Excused',
    'status_pending' => 'Pending',
    'status_not_reported' => 'Not yet reported',
    'label_attendance' => 'Attendance',
    'label_attendance_status' => 'Attendance Status',
    'label_reported_by' => 'Reported by :name',
    'label_reported_at' => 'Reported :time',
    'flash_attendance_reported' => 'Attendance reported successfully.',
    'flash_attendance_disputed' => 'Your dispute has been submitted for review.',
    'flash_dispute_resolved' => 'Dispute resolved — attendance updated.',
    'flash_dispute_upheld' => 'Dispute reviewed — report upheld.',

    // Dispute
    'heading_dispute_title' => 'Dispute Attendance Report',
    'content_dispute_description' => 'If you believe this attendance report is incorrect, you can submit a dispute with your reason.',
    'placeholder_dispute_reason' => 'Explain why you disagree with this report...',
    'action_dispute_submit' => 'Submit Dispute',

    // Debriefing
    'heading_debriefing_title' => 'Session Debriefing',
    'heading_debriefing_summary_title' => 'Group Debriefing',
    'content_debriefing_description' => 'Take a moment to reflect on this session. Your responses help everyone improve the experience.',
    'action_debriefing_submit' => 'Submit Debriefing',
    'content_debriefing_submitted' => 'Your debriefing has been submitted. Thank you for reflecting!',
    'content_debriefing_waiting' => 'Waiting for participants to submit their debriefing responses.',
    'label_debriefing_responses' => '{1}1 response|[2,*]:count responses',
    'content_debriefing_confidential' => 'confidential — only visible to host',
    'content_debriefing_prompt_what_went_well' => 'What went well?',
    'content_debriefing_prompt_what_to_change' => 'Anything to change next time?',
    'content_debriefing_prompt_safety_concerns' => 'Any safety concerns?',
    'content_debriefing_prompt_star' => 'Give a star — something positive about the session',
    'content_debriefing_prompt_wish' => 'A wish — something for next time',
    'label_debriefing_tool_debriefing' => 'Debriefing',
    'label_debriefing_tool_stars_and_wishes' => 'Stars & Wishes',

    // Recap
    'heading_recap_title' => 'Host Recap',
    'content_recap_by' => 'Written by :host',
    'action_recap_write' => 'Write a Recap',
    'content_recap_posted' => 'Recap Posted',
    'content_recap_activity' => 'wrote a recap for',
    'content_recap_none' => 'No recap has been written yet.',

    // Dashboard engagement
    'dashboard_games_this_week' => 'Games This Week',
    'dashboard_attended' => 'attended',
    'dashboard_pending' => 'pending',
    'dashboard_total' => 'total',
    'dashboard_hosting' => 'Hosting',
    'dashboard_no_games_this_week' => 'No games scheduled this week. Time to find your next adventure!',
    'dashboard_find_next_game' => 'Find Your Next Game',
    'dashboard_new_recaps' => 'New Recaps',
    'dashboard_recap_by' => 'By :name',
    'dashboard_attendance_summary' => ':attended attended, :pending pending — :total games this week',

    // Late cancel warning
    'content_warning_late_cancel' => 'You are cancelling within 24 hours of the game. This will be recorded as a late cancellation.',
    'content_warning_below_min_players' => 'This game now has fewer than the minimum required players.',

    // Host reliability preference
    'field_reliability_preference' => 'Attendance Preference',
    'hint_reliability_preference' => 'Optionally prefer players with a minimum attendance rate (%). This is a soft preference, not a hard filter.',
    'content_host_prefers_attendance' => 'Host prefers ≥:percent% attendance',
];
