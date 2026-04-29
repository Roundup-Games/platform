<?php

describe('Attendance Translation Completeness', function () {
    it('has all required keys in EN attendance translations', function () {
        app()->setLocale('en');
        $keys = trans('attendance');

        $requiredKeys = [
            // Tiers
            'label_tier_reliable', 'label_tier_active', 'label_tier_newcomer',
            'content_tier_description_reliable', 'content_tier_description_active', 'content_tier_description_newcomer',
            // Stats
            'label_stat_games_played', 'label_stat_attendance_rate', 'label_stat_no_show_count',
            'label_stat_attended_count', 'label_stat_late_cancel_count', 'label_stat_excused_count',
            // Waitlist
            'content_waitlist_position', 'content_waitlist_spot_opened', 'action_waitlist_confirm',
            'action_waitlist_decline', 'content_waitlist_expired', 'action_waitlist_join', 'content_waitlist_full',
            'content_waitlist_confirmed', 'content_waitlist_declined', 'content_waitlist_added',
            'content_waitlist_deadline', 'label_waitlist_management', 'content_waitlist_no_players',
            // Bench
            'label_bench_on_the_bench', 'content_bench_description', 'flash_bench_promoted',
            'action_bench_promote', 'content_bench_placed', 'content_bench_you_are_on',
            // Attendance actions
            'action_report_attendance', 'action_dispute_report',
            'status_attended', 'status_no_show', 'status_late_cancel',
            'status_excused', 'status_pending', 'status_not_reported',
            // Debriefing
            'heading_debriefing_title', 'heading_debriefing_summary_title', 'content_debriefing_description',
            'action_debriefing_submit', 'content_debriefing_submitted', 'content_debriefing_waiting',
            'label_debriefing_responses', 'content_debriefing_confidential',
            'content_debriefing_prompt_what_went_well', 'content_debriefing_prompt_what_to_change',
            'content_debriefing_prompt_safety_concerns', 'content_debriefing_prompt_star',
            'content_debriefing_prompt_wish', 'label_debriefing_tool_debriefing',
            'label_debriefing_tool_stars_and_wishes',
            // Recap
            'heading_recap_title', 'content_recap_by', 'action_recap_write', 'content_recap_posted',
            'content_recap_activity', 'content_recap_none',
            // Dashboard engagement
            'dashboard_games_this_week', 'dashboard_attended', 'dashboard_pending',
            'dashboard_total', 'dashboard_hosting', 'dashboard_no_games_this_week',
            'dashboard_find_next_game', 'dashboard_new_recaps', 'dashboard_recap_by',
            'dashboard_attendance_summary',
        ];

        foreach ($requiredKeys as $key) {
            expect(array_key_exists($key, $keys))->toBeTrue("Missing EN key: attendance.{$key}");
        }
    });

    it('has all required keys in DE attendance translations', function () {
        app()->setLocale('de');
        $keys = trans('attendance');

        $requiredKeys = [
            'label_tier_reliable', 'label_tier_active', 'label_tier_newcomer',
            'heading_debriefing_title', 'action_debriefing_submit', 'content_debriefing_submitted',
            'content_debriefing_prompt_what_went_well', 'content_debriefing_prompt_star',
            'heading_recap_title', 'content_recap_by', 'action_recap_write', 'content_recap_posted',
            'dashboard_games_this_week', 'dashboard_no_games_this_week',
            'dashboard_find_next_game', 'dashboard_new_recaps',
        ];

        foreach ($requiredKeys as $key) {
            expect(array_key_exists($key, $keys))->toBeTrue("Missing DE key: attendance.{$key}");
        }
    });

    it('has identical key sets in EN and DE attendance files', function () {
        app()->setLocale('en');
        $enKeys = array_keys(trans('attendance'));
        app()->setLocale('de');
        $deKeys = array_keys(trans('attendance'));

        sort($enKeys);
        sort($deKeys);

        expect($enKeys)->toBe($deKeys, 'EN and DE attendance files have different key sets');
    });

    it('has German values not English copies in DE attendance file', function () {
        app()->setLocale('de');
        $de = trans('attendance');

        $germanChecks = [
            'label_tier_reliable' => 'Zuverlässig',
            'label_tier_active' => 'Aktiv',
            'label_tier_newcomer' => 'Neuling',
            'heading_debriefing_title' => 'Sitzungs-Debriefing',
            'heading_recap_title' => 'Host-Nachbericht',
            'dashboard_games_this_week' => 'Spiele diese Woche',
            'dashboard_find_next_game' => 'Nächstes Spiel finden',
            'status_attended' => 'Teilgenommen',
            'action_waitlist_join' => 'Warteliste beitreten',
        ];

        foreach ($germanChecks as $key => $expected) {
            expect($de[$key])->toBe($expected, "DE key attendance.{$key} should be '{$expected}'");
        }
    });

    it('has debriefing and recap error keys in both EN and DE games translations', function () {
        $requiredGamesKeys = [
            'error_recap_game_not_completed',
            'error_recap_not_host',
            'error_recap_too_long',
            'error_recap_empty',
            'error_debriefing_game_not_completed',
            'error_debriefing_no_debriefing_tools',
            'error_debriefing_not_participant',
            'error_debriefing_host_cannot_submit',
            'error_debriefing_already_submitted',
            'error_debriefing_empty_responses',
        ];

        app()->setLocale('en');
        $enGames = trans('games');
        app()->setLocale('de');
        $deGames = trans('games');

        foreach ($requiredGamesKeys as $key) {
            expect(array_key_exists($key, $enGames))->toBeTrue("Missing EN games key: {$key}");
            expect(array_key_exists($key, $deGames))->toBeTrue("Missing DE games key: {$key}");
        }
    });
});
