<?php

describe('Attendance Translation Completeness', function () {
    it('has all required keys in EN attendance translations', function () {
        app()->setLocale('en');
        $keys = trans('attendance');

        $requiredKeys = [
            // Tiers
            'tier_reliable', 'tier_active', 'tier_newcomer',
            'tier_description_reliable', 'tier_description_active', 'tier_description_newcomer',
            // Stats
            'stat_games_played', 'stat_attendance_rate', 'stat_no_show_count',
            'stat_attended_count', 'stat_late_cancel_count', 'stat_excused_count',
            // Waitlist
            'waitlist_position', 'waitlist_spot_opened', 'waitlist_confirm',
            'waitlist_decline', 'waitlist_expired', 'waitlist_join', 'waitlist_full',
            'waitlist_confirmed', 'waitlist_declined', 'waitlist_added',
            'waitlist_deadline', 'waitlist_management', 'waitlist_no_players',
            // Bench
            'bench_on_the_bench', 'bench_description', 'bench_promoted',
            'bench_promote', 'bench_placed', 'bench_you_are_on',
            // Attendance actions
            'action_report_attendance', 'action_dispute_report',
            'status_attended', 'status_no_show', 'status_late_cancel',
            'status_excused', 'status_pending', 'status_not_reported',
            // Debriefing
            'debriefing_title', 'debriefing_summary_title', 'debriefing_description',
            'debriefing_submit', 'debriefing_submitted', 'debriefing_waiting',
            'debriefing_responses', 'debriefing_confidential',
            'debriefing_prompt_what_went_well', 'debriefing_prompt_what_to_change',
            'debriefing_prompt_safety_concerns', 'debriefing_prompt_star',
            'debriefing_prompt_wish', 'debriefing_tool_debriefing',
            'debriefing_tool_stars_and_wishes',
            // Recap
            'recap_title', 'recap_by', 'recap_write', 'recap_posted',
            'recap_activity', 'recap_none',
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
            'tier_reliable', 'tier_active', 'tier_newcomer',
            'debriefing_title', 'debriefing_submit', 'debriefing_submitted',
            'debriefing_prompt_what_went_well', 'debriefing_prompt_star',
            'recap_title', 'recap_by', 'recap_write', 'recap_posted',
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
            'tier_reliable' => 'Zuverlässig',
            'tier_active' => 'Aktiv',
            'tier_newcomer' => 'Neuling',
            'debriefing_title' => 'Sitzungs-Debriefing',
            'recap_title' => 'Host-Nachbericht',
            'dashboard_games_this_week' => 'Spiele diese Woche',
            'dashboard_find_next_game' => 'Nächstes Spiel finden',
            'status_attended' => 'Teilgenommen',
            'waitlist_join' => 'Warteliste beitreten',
        ];

        foreach ($germanChecks as $key => $expected) {
            expect($de[$key])->toBe($expected, "DE key attendance.{$key} should be '{$expected}'");
        }
    });

    it('has debriefing and recap error keys in both EN and DE games translations', function () {
        $requiredGamesKeys = [
            'recap_error_game_not_completed',
            'recap_error_not_host',
            'recap_error_too_long',
            'recap_error_empty',
            'debriefing_error_game_not_completed',
            'debriefing_error_no_debriefing_tools',
            'debriefing_error_not_participant',
            'debriefing_error_host_cannot_submit',
            'debriefing_error_already_submitted',
            'debriefing_error_empty_responses',
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
