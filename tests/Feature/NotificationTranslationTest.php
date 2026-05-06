<?php

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Factory as ViewFactory;

describe('Notification Translations', function () {
    it('interpolates placeholder values in :locale subjects', function (string $locale, string $key, array $replace, string $expected) {
        app()->setLocale($locale);
        expect(__($key, $replace))->toBe($expected);
    })->with([
        ['en', 'notifications.subject_new_follower',  ['follower' => 'Alice'], 'Alice started following you'],
        ['en', 'notifications.subject_game_invitation', ['inviter' => 'Bob'],  'Bob invited you to a game'],
        ['en', 'notifications.email_greeting',          ['name' => 'Charlie'], 'Hey Charlie,'],
        ['de', 'notifications.subject_new_follower',  ['follower' => 'Alice'], 'Alice folgt dir jetzt'],
        ['de', 'notifications.subject_game_invitation', ['inviter' => 'Bob'],  'Bob hat dich zu einem Spiel eingeladen'],
        ['de', 'notifications.email_greeting',          ['name' => 'Charlie'], 'Hallo Charlie,'],
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
