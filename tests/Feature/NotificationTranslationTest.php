<?php

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Factory as ViewFactory;

describe('Notification Translations', function () {
    it('loads EN notification category labels for all 15 types', function () {
        app()->setLocale('en');

        $categories = [
            'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
            'session_added_to_campaign', 'new_application', 'application_approved',
            'application_rejected', 'participant_joined', 'participant_removed',
            'team_member_removed', 'game_cancelled', 'game_completed',
            'campaign_cancelled', 'campaign_completed',
        ];

        foreach ($categories as $cat) {
            $key = "notifications.category_{$cat}";
            expect(__($key))->not->toBe($key, "EN translation missing for {$key}");
        }
    });

    it('loads DE notification category labels for all 15 types', function () {
        app()->setLocale('de');

        $categories = [
            'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
            'session_added_to_campaign', 'new_application', 'application_approved',
            'application_rejected', 'participant_joined', 'participant_removed',
            'team_member_removed', 'game_cancelled', 'game_completed',
            'campaign_cancelled', 'campaign_completed',
        ];

        foreach ($categories as $cat) {
            $key = "notifications.category_{$cat}";
            expect(__($key))->not->toBe($key, "DE translation missing for {$key}");
        }
    });

    it('loads EN group labels for all 5 groups', function () {
        app()->setLocale('en');

        foreach (['social', 'invitations', 'applications', 'participation', 'status'] as $group) {
            $key = "notifications.group_{$group}";
            expect(__($key))->not->toBe($key, "EN group translation missing for {$key}");
        }
    });

    it('loads DE group labels for all 5 groups', function () {
        app()->setLocale('de');

        foreach (['social', 'invitations', 'applications', 'participation', 'status'] as $group) {
            $key = "notifications.group_{$group}";
            expect(__($key))->not->toBe($key, "DE group translation missing for {$key}");
        }
    });

    it('loads EN subject lines for all 15 notification types', function () {
        app()->setLocale('en');

        $types = [
            'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
            'session_added_to_campaign', 'new_application', 'application_approved',
            'application_rejected', 'participant_joined', 'participant_removed',
            'team_member_removed', 'game_cancelled', 'game_completed',
            'campaign_cancelled', 'campaign_completed',
        ];

        foreach ($types as $type) {
            $key = "notifications.subject_{$type}";
            $translated = __($key);
            expect($translated)->not->toBe($key, "EN subject translation missing for {$key}");
            expect($translated)->toBeString();
        }
    });

    it('loads DE subject lines for all 15 notification types', function () {
        app()->setLocale('de');

        $types = [
            'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
            'session_added_to_campaign', 'new_application', 'application_approved',
            'application_rejected', 'participant_joined', 'participant_removed',
            'team_member_removed', 'game_cancelled', 'game_completed',
            'campaign_cancelled', 'campaign_completed',
        ];

        foreach ($types as $type) {
            $key = "notifications.subject_{$type}";
            $translated = __($key);
            expect($translated)->not->toBe($key, "DE subject translation missing for {$key}");
            expect($translated)->toBeString();
        }
    });

    it('loads EN body text for all 15 notification types', function () {
        app()->setLocale('en');

        $types = [
            'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
            'session_added_to_campaign', 'new_application', 'application_approved',
            'application_rejected', 'participant_joined', 'participant_removed',
            'team_member_removed', 'game_cancelled', 'game_completed',
            'campaign_cancelled', 'campaign_completed',
        ];

        foreach ($types as $type) {
            $key = "notifications.body_{$type}";
            expect(__($key))->not->toBe($key, "EN body translation missing for {$key}");
        }
    });

    it('loads DE body text for all 15 notification types', function () {
        app()->setLocale('de');

        $types = [
            'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
            'session_added_to_campaign', 'new_application', 'application_approved',
            'application_rejected', 'participant_joined', 'participant_removed',
            'team_member_removed', 'game_cancelled', 'game_completed',
            'campaign_cancelled', 'campaign_completed',
        ];

        foreach ($types as $type) {
            $key = "notifications.body_{$type}";
            expect(__($key))->not->toBe($key, "DE body translation missing for {$key}");
        }
    });

    it('loads EN action labels for all 15 notification types', function () {
        app()->setLocale('en');

        $types = [
            'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
            'session_added_to_campaign', 'new_application', 'application_approved',
            'application_rejected', 'participant_joined', 'participant_removed',
            'team_member_removed', 'game_cancelled', 'game_completed',
            'campaign_cancelled', 'campaign_completed',
        ];

        foreach ($types as $type) {
            $key = "notifications.action_{$type}";
            expect(__($key))->not->toBe($key, "EN action translation missing for {$key}");
        }
    });

    it('loads DE action labels for all 15 notification types', function () {
        app()->setLocale('de');

        $types = [
            'new_follower', 'game_invitation', 'campaign_invitation', 'team_invitation',
            'session_added_to_campaign', 'new_application', 'application_approved',
            'application_rejected', 'participant_joined', 'participant_removed',
            'team_member_removed', 'game_cancelled', 'game_completed',
            'campaign_cancelled', 'campaign_completed',
        ];

        foreach ($types as $type) {
            $key = "notifications.action_{$type}";
            expect(__($key))->not->toBe($key, "DE action translation missing for {$key}");
        }
    });

    it('loads common email strings in EN', function () {
        app()->setLocale('en');

        $commonKeys = [
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

        foreach ($commonKeys as $key) {
            expect(__($key))->not->toBe($key, "EN common email translation missing for {$key}");
        }
    });

    it('loads common email strings in DE', function () {
        app()->setLocale('de');

        $commonKeys = [
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

        foreach ($commonKeys as $key) {
            expect(__($key))->not->toBe($key, "DE common email translation missing for {$key}");
        }
    });

    it('interpolates placeholder values in EN subjects', function () {
        app()->setLocale('en');

        expect(__('notifications.subject_new_follower', ['follower' => 'Alice']))
            ->toBe('Alice started following you');

        expect(__('notifications.subject_game_invitation', ['inviter' => 'Bob']))
            ->toBe('Bob invited you to a game');

        expect(__('notifications.email_greeting', ['name' => 'Charlie']))
            ->toBe('Hey Charlie,');
    });

    it('interpolates placeholder values in DE subjects', function () {
        app()->setLocale('de');

        expect(__('notifications.subject_new_follower', ['follower' => 'Alice']))
            ->toBe('Alice folgt dir jetzt');

        expect(__('notifications.subject_game_invitation', ['inviter' => 'Bob']))
            ->toBe('Bob hat dich zu einem Spiel eingeladen');

        expect(__('notifications.email_greeting', ['name' => 'Charlie']))
            ->toBe('Hallo Charlie,');
    });

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
        expect($rendered)->toContain('#835500'); // amber primary
        expect($rendered)->toContain('#fbf9f1'); // cream surface
        expect($rendered)->toContain('Test notification body content');
    });
});
