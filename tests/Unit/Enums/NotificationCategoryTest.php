<?php

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;

describe('NotificationCategory Unit Tests', function () {
    it('group() returns a valid group for every case', function () {
        $validGroups = ['social', 'invitations', 'applications', 'participation', 'status', 'content', 'scheduling', 'moderation'];        foreach (NotificationCategory::cases() as $case) {
            expect($case->group())->toBeIn($validGroups, "{$case->value} group should be valid");
        }
    });

    it('group() assignments match expected categories', function () {
        expect(NotificationCategory::NewFollower->group())->toBe('social');

        foreach ([NotificationCategory::GameInvitation, NotificationCategory::CampaignInvitation, NotificationCategory::TeamInvitation, NotificationCategory::SessionAddedToCampaign] as $case) {
            expect($case->group())->toBe('invitations');
        }

        foreach ([NotificationCategory::NewApplication, NotificationCategory::ApplicationApproved, NotificationCategory::ApplicationRejected] as $case) {
            expect($case->group())->toBe('applications');
        }

        foreach ([NotificationCategory::ParticipantJoined, NotificationCategory::ParticipantRemoved, NotificationCategory::TeamMemberRemoved] as $case) {
            expect($case->group())->toBe('participation');
        }

        foreach ([NotificationCategory::GameCancelled, NotificationCategory::GameCompleted, NotificationCategory::CampaignCancelled, NotificationCategory::CampaignCompleted, NotificationCategory::GameUpdated, NotificationCategory::CampaignUpdated] as $case) {
            expect($case->group())->toBe('status');
        }

        expect(NotificationCategory::GameSystemRequest->group())->toBe('content');
    });

    it('channels() returns database and mail channel classes', function () {
        $channels = NotificationCategory::channels();
        expect($channels)->toBe([DatabaseChannel::class, MailChannel::class]);
    });

    it('defaultSettings() returns array keyed by all 23 category values', function () {
        $settings = NotificationCategory::defaultSettings();

        expect($settings)->toHaveCount(24);
        expect(array_keys($settings))->toBe(NotificationCategory::values());
    });

    it('defaultSettings() has database=true for every category', function () {
        $settings = NotificationCategory::defaultSettings();
        foreach ($settings as $category => $channels) {
            expect($channels)->toHaveKey('database');
            expect($channels['database'])->toBeTrue("{$category} should default database=true");
        }
    });

    it('defaultSettings() mail defaults match high-priority policy', function () {
        $settings = NotificationCategory::defaultSettings();

        // High-priority actionable events: mail ON
        $mailOn = [
            'game_invitation', 'campaign_invitation', 'team_invitation',
            'new_application', 'application_approved', 'application_rejected',
            'participant_removed', 'team_member_removed',
            'attendance_reported', 'dispute_resolved',
            'game_cancelled', 'game_completed', 'campaign_cancelled', 'campaign_completed',
            'game_updated', 'campaign_updated',
            'game_system_request',
            'below_min_players',
            'session_reminder',
            'review_reported',
        ];
        foreach ($mailOn as $cat) {
            expect($settings[$cat]['mail'])->toBeTrue("{$cat} should default mail=true");
        }

        // Informational events: mail OFF
        $mailOff = ['new_follower', 'session_added_to_campaign', 'participant_joined', 'confirmation_expired'];
        foreach ($mailOff as $cat) {
            expect($settings[$cat]['mail'])->toBeFalse("{$cat} should default mail=false");
        }
    });

    it('defaultMailEnabled() is consistent with defaultSettings() mail values', function () {
        $settings = NotificationCategory::defaultSettings();
        foreach (NotificationCategory::cases() as $case) {
            $expected = $settings[$case->value]['mail'];
            expect($case->defaultMailEnabled())->toBe($expected, "{$case->value}::defaultMailEnabled() should match defaultSettings() mail value");
        }
    });

    it('defaultPushEnabled() is consistent with defaultSettings() push values', function () {
        $settings = NotificationCategory::defaultSettings();
        foreach (NotificationCategory::cases() as $case) {
            $expected = $settings[$case->value]['push'];
            expect($case->defaultPushEnabled())->toBe($expected, "{$case->value}::defaultPushEnabled() should match defaultSettings() push value");
        }
    });

    it('defaultSettings() has push=true for high-priority categories and push=false for informational', function () {
        $settings = NotificationCategory::defaultSettings();

        $pushOn = [
            'game_invitation', 'campaign_invitation', 'team_invitation',
            'new_application', 'application_approved', 'application_rejected',
            'participant_removed', 'team_member_removed',
            'attendance_reported', 'dispute_resolved',
            'game_cancelled', 'game_completed', 'campaign_cancelled', 'campaign_completed',
            'game_updated', 'campaign_updated',
            'game_system_request',
            'review_reported',
        ];
        foreach ($pushOn as $cat) {
            expect($settings[$cat]['push'])->toBeTrue("{$cat} should default push=true");
        }

        $pushOff = ['new_follower', 'session_added_to_campaign', 'participant_joined'];
        foreach ($pushOff as $cat) {
            expect($settings[$cat]['push'])->toBeFalse("{$cat} should default push=false");
        }
    });

    it('defaultSettings() each entry has exactly database, mail, and push keys', function () {
        $settings = NotificationCategory::defaultSettings();
        foreach ($settings as $category => $channels) {
            expect(array_keys($channels))->toBe(['database', 'mail', 'push'], "{$category} should have exactly database, mail, and push keys");
            expect($channels['database'])->toBeBool();
            expect($channels['mail'])->toBeBool();
            expect($channels['push'])->toBeBool();
        }
    });

    // Note: grouped() and label() require Laravel's translator (app container).
    // Those are tested in tests/Feature/Enums/NotificationCategoryTest.php.
});
