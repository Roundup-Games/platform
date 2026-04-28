<?php

return [
    // Tier labels
    'tier_reliable' => 'Reliable',
    'tier_active' => 'Active',
    'tier_newcomer' => 'Newcomer',
    'tier_description_reliable' => 'Consistent attendance across 5+ games.',
    'tier_description_active' => 'Regular player with room to improve.',
    'tier_description_newcomer' => 'New to the platform — keep playing to build your track record.',

    // Stats
    'stat_games_played' => 'Games Played',
    'stat_attendance_rate' => 'Attendance Rate',
    'stat_no_show_count' => 'No-Shows',
    'stat_attended_count' => 'Attended',
    'stat_late_cancel_count' => 'Late Cancels',
    'stat_excused_count' => 'Excused',
    'stat_value_percent' => ':value%',
    'stat_value_count' => ':count',

    // Waitlist
    'waitlist_position' => 'You are #:position on the waitlist.',
    'waitlist_spot_opened' => 'A spot opened up!',
    'waitlist_confirm' => 'Confirm Spot',
    'waitlist_decline' => 'Decline Spot',
    'waitlist_expired' => 'Waitlist spot expired.',
    'waitlist_join' => 'Join Waitlist',
    'waitlist_full' => 'This game is full. Join the waitlist to be notified when a spot opens up.',
    'waitlist_confirmed' => 'You have confirmed your spot!',
    'waitlist_declined' => 'You have declined the spot.',
    'waitlist_added' => 'You have been added to the waitlist.',
    'waitlist_deadline' => 'Confirm before :deadline.',
    'waitlist_management' => 'Waitlist',
    'waitlist_no_players' => 'No players on the waitlist.',

    // Bench
    'bench_on_the_bench' => 'On the Bench',
    'bench_description' => 'These players are on the bench. Promote them when a spot opens up.',
    'bench_promoted' => 'Player promoted from the bench.',
    'bench_promote' => 'Promote',
    'bench_placed' => 'The session is full — you\'ve been placed on the bench.',
    'bench_you_are_on' => 'You\'re on the Bench',

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
    'dispute_title' => 'Dispute Attendance Report',
    'dispute_description' => 'If you believe this attendance report is incorrect, you can submit a dispute with your reason.',
    'dispute_reason_placeholder' => 'Explain why you disagree with this report...',
    'dispute_submit' => 'Submit Dispute',

    // Debriefing
    'debriefing_title' => 'Session Debriefing',
    'debriefing_summary_title' => 'Group Debriefing',
    'debriefing_description' => 'Take a moment to reflect on this session. Your responses help everyone improve the experience.',
    'debriefing_submit' => 'Submit Debriefing',
    'debriefing_submitted' => 'Your debriefing has been submitted. Thank you for reflecting!',
    'debriefing_waiting' => 'Waiting for participants to submit their debriefing responses.',
    'debriefing_responses' => '{1}1 response|[2,*]:count responses',
    'debriefing_confidential' => 'confidential — only visible to host',
    'debriefing_prompt_what_went_well' => 'What went well?',
    'debriefing_prompt_what_to_change' => 'Anything to change next time?',
    'debriefing_prompt_safety_concerns' => 'Any safety concerns?',
    'debriefing_prompt_star' => 'Give a star — something positive about the session',
    'debriefing_prompt_wish' => 'A wish — something for next time',
    'debriefing_tool_debriefing' => 'Debriefing',
    'debriefing_tool_stars_and_wishes' => 'Stars & Wishes',

    // Recap
    'recap_title' => 'Host Recap',
    'recap_by' => 'Written by :host',
    'recap_write' => 'Write a Recap',
    'recap_posted' => 'Recap Posted',
    'recap_activity' => 'wrote a recap for',
    'recap_none' => 'No recap has been written yet.',

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
    'warning_late_cancel' => 'You are cancelling within 24 hours of the game. This will be recorded as a late cancellation.',
    'warning_below_min_players' => 'This game now has fewer than the minimum required players.',

    // Host reliability preference
    'field_reliability_preference' => 'Attendance Preference',
    'hint_reliability_preference' => 'Optionally prefer players with a minimum attendance rate (%). This is a soft preference, not a hard filter.',
    'host_prefers_attendance' => 'Host prefers ≥:percent% attendance',
];
