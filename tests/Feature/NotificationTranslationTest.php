<?php

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Factory as ViewFactory;

describe('Notification Translations', function () {
    $notificationTypes = [
        'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
        'session_added_to_campaign', 'new_application', 'application_approved',
        'application_rejected', 'participant_joined', 'participant_removed',
        'team_member_removed', 'game_cancelled', 'game_completed',
        'campaign_cancelled', 'campaign_completed',
    ];

    $groups = ['social', 'invitations', 'applications', 'participation', 'status'];

    $commonEmailKeys = [
        'notifications.email_brand_name',
        'notifications.email_default_subject',
        'notifications.email_unsubscribe',
        'notifications.email_manage_settings',
        'notifications.email_footer_reason',
        'notifications.email_greeting',
        'notifications.email_greeting_plain',
        'notifications.email_view_details',
        'notifications.email_manage_participants',
    ];

    it('loads notification category labels for all 15 types in :locale', function (string $locale) use ($notificationTypes) {
        app()->setLocale($locale);
        $label = $locale === 'en' ? 'EN' : 'DE';

        foreach ($notificationTypes as $cat) {
            $key = "notifications.category_{$cat}";
            expect(__($key))->not->toBe($key, "{$label} translation missing for {$key}");
        }
    })->with(['en', 'de']);

    it('loads group labels for all 5 groups in :locale', function (string $locale) use ($groups) {
        app()->setLocale($locale);
        $label = $locale === 'en' ? 'EN' : 'DE';

        foreach ($groups as $group) {
            $key = "notifications.group_{$group}";
            expect(__($key))->not->toBe($key, "{$label} group translation missing for {$key}");
        }
    })->with(['en', 'de']);

    it('loads subject lines for all 15 notification types in :locale', function (string $locale) use ($notificationTypes) {
        app()->setLocale($locale);
        $label = $locale === 'en' ? 'EN' : 'DE';

        foreach ($notificationTypes as $type) {
            $key = "notifications.subject_{$type}";
            $translated = __($key);
            expect($translated)->not->toBe($key, "{$label} subject translation missing for {$key}");
            expect($translated)->toBeString();
        }
    })->with(['en', 'de']);

    it('loads body text for all 15 notification types in :locale', function (string $locale) use ($notificationTypes) {
        app()->setLocale($locale);
        $label = $locale === 'en' ? 'EN' : 'DE';

        foreach ($notificationTypes as $type) {
            $key = "notifications.body_{$type}";
            expect(__($key))->not->toBe($key, "{$label} body translation missing for {$key}");
        }
    })->with(['en', 'de']);

    it('loads action labels for all 15 notification types in :locale', function (string $locale) use ($notificationTypes) {
        app()->setLocale($locale);
        $label = $locale === 'en' ? 'EN' : 'DE';

        foreach ($notificationTypes as $type) {
            $key = "notifications.action_{$type}";
            expect(__($key))->not->toBe($key, "{$label} action translation missing for {$key}");
        }
    })->with(['en', 'de']);

    it('loads common email strings in :locale', function (string $locale) use ($commonEmailKeys) {
        app()->setLocale($locale);
        $label = $locale === 'en' ? 'EN' : 'DE';

        foreach ($commonEmailKeys as $key) {
            expect(__($key))->not->toBe($key, "{$label} common email translation missing for {$key}");
        }
    })->with(['en', 'de']);

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
