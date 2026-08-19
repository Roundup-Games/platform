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
        ['en', 'notifications.subject_game_invitation', ['inviter' => 'Bob']],
        ['en', 'common.field_hey_name',          ['name' => 'Charlie']],
        ['de', 'notifications.subject_new_follower',  ['follower' => 'Alice']],
        ['de', 'notifications.subject_game_invitation', ['inviter' => 'Bob']],
        ['de', 'common.field_hey_name',          ['name' => 'Charlie']],
    ]);

    it('renders notification layout blade view without errors', function () {
        app()->setLocale('en');

        $rendered = view('emails.notification-layout', [
            'subject' => 'Test Subject',
            'unsubscribeUrl' => 'https://example.com/unsubscribe',
            'body' => '<p>Test notification body content.</p>',
        ])->render();

        expect($rendered)->toBeString();
        expect($rendered)->toContain('Roundup Games');
        expect($rendered)->toContain('Unsubscribe');
        expect($rendered)->toContain('Test notification body content');
    });
});
