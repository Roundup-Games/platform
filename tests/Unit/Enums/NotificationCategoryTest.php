<?php

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;

describe('NotificationCategory Unit Tests', function () {
    it('has exactly 17 cases', function () {
        expect(NotificationCategory::cases())->toHaveCount(18);
    });

    it('values() returns all 17 string values in declaration order', function () {
        $expected = [
            'new_follower',
            'game_invitation', 'campaign_invitation', 'team_invitation', 'session_added_to_campaign',
            'new_application', 'application_approved', 'application_rejected',
            'participant_joined', 'participant_removed', 'team_member_removed',
            'game_cancelled', 'game_completed', 'campaign_cancelled', 'campaign_completed',
            'game_updated', 'campaign_updated',
            'review_reported',
        ];
        expect(NotificationCategory::values())->toBe($expected);
    });

    it('values() returns flat string array', function () {
        $values = NotificationCategory::values();
        foreach ($values as $value) {
            expect($value)->toBeString();
        }
    });

    it('is a backed string enum', function () {
        $reflection = new ReflectionEnum(NotificationCategory::class);
        expect($reflection->getBackingType()?->getName())->toBe('string');
    });

    it('each case maps to a distinct snake_case value', function () {
        $values = NotificationCategory::values();
        expect($values)->toHaveCount(count(array_unique($values)));
    });

    it('group() returns a valid group for every case', function () {
        $validGroups = ['social', 'invitations', 'applications', 'participation', 'status', 'moderation'];        foreach (NotificationCategory::cases() as $case) {
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
    });

    it('channels() returns database and mail channel classes', function () {
        $channels = NotificationCategory::channels();
        expect($channels)->toBe([DatabaseChannel::class, MailChannel::class]);
    });

    it('defaultSettings() returns array keyed by all 17 category values', function () {
        $settings = NotificationCategory::defaultSettings();

        expect($settings)->toHaveCount(18);
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
            'game_cancelled', 'campaign_cancelled',
            'game_updated', 'campaign_updated',
        ];
        foreach ($mailOn as $cat) {
            expect($settings[$cat]['mail'])->toBeTrue("{$cat} should default mail=true");
        }

        // Informational events: mail OFF
        $mailOff = ['new_follower', 'session_added_to_campaign', 'participant_joined', 'game_completed', 'campaign_completed'];
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

    it('defaultSettings() each entry has exactly database and mail keys', function () {
        $settings = NotificationCategory::defaultSettings();
        foreach ($settings as $category => $channels) {
            expect(array_keys($channels))->toBe(['database', 'mail'], "{$category} should have exactly database and mail keys");
            expect($channels['database'])->toBeBool();
            expect($channels['mail'])->toBeBool();
        }
    });

    // Note: grouped() and label() require Laravel's translator (app container).
    // Those are tested in tests/Feature/Enums/NotificationCategoryTest.php.
});
