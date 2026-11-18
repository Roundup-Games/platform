<?php

describe('Notification Translations', function () {
    it('resolves every notification key and interpolates its placeholder in :locale', function (string $locale, string $key, array $replace) {
        app()->setLocale($locale);

        $resolved = __($key, $replace);

        // The key resolves to a real (non-missing) string and every placeholder
        // was substituted. We deliberately do NOT pin the exact wording — that
        // made the prior matrix a content change-detector that broke on any
        // legitimate copy edit. The interpolation contract is what matters.
        expect($resolved)->not->toBe($key)
            ->and($resolved)->toBeString()
            ->and($resolved)->not->toBeEmpty();

        foreach ($replace as $token => $value) {
            expect($resolved)->not->toContain(':'.$token);
            expect($resolved)->toContain($value);
        }
    })->with([
        ['en', 'notifications.subject_new_follower',  ['follower' => 'Alice']],
        ['en', 'notifications.subject_game_invitation', ['inviter' => 'Bob', 'game' => 'Waterdeep Dungeon']],
        ['en', 'common.field_hey_name',          ['name' => 'Charlie']],
        ['de', 'notifications.subject_new_follower',  ['follower' => 'Alice']],
        ['de', 'notifications.subject_game_invitation', ['inviter' => 'Bob', 'game' => 'Waterdeep Dungeon']],
        ['de', 'common.field_hey_name',          ['name' => 'Charlie']],
        // S04/T03 — new interpolation contracts: update subjects carry the
        // change scope (:summary), game-system-request subjects carry the
        // system (:name) and, on rejection with a reason, the reason itself.
        ['en', 'notifications.subject_game_updated', ['game' => 'Waterdeep Dungeon', 'summary' => 'Date, Location']],
        ['en', 'notifications.subject_campaign_updated', ['campaign' => 'Shadow over Waterdeep', 'summary' => 'Description']],
        ['en', 'notifications.subject_game_system_request_rejected', ['name' => 'Gloomhaven']],
        ['en', 'notifications.subject_game_system_request_rejected_reason', ['name' => 'Gloomhaven', 'reason' => 'Duplicate entry']],
        ['en', 'notifications.subject_game_system_request_duplicate', ['name' => 'Gloomhaven']],
        ['en', 'notifications.subject_seat_demoted', ['entity' => 'Waterdeep Dungeon']],
        ['de', 'notifications.subject_game_updated', ['game' => 'Waterdeep Dungeon', 'summary' => 'Datum, Ort']],
        ['de', 'notifications.subject_campaign_updated', ['campaign' => 'Shadow over Waterdeep', 'summary' => 'Beschreibung']],
        ['de', 'notifications.subject_game_system_request_rejected', ['name' => 'Gloomhaven']],
        ['de', 'notifications.subject_game_system_request_rejected_reason', ['name' => 'Gloomhaven', 'reason' => 'Existiert bereits']],
        ['de', 'notifications.subject_game_system_request_duplicate', ['name' => 'Gloomhaven']],
        ['de', 'notifications.subject_seat_demoted', ['entity' => 'Waterdeep Dungeon']],
    ]);

});
