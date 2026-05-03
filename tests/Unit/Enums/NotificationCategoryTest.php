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

    it('defaultSettings() returns array keyed by all category values', function () {
        $settings = NotificationCategory::defaultSettings();

        expect(array_keys($settings))->toBe(NotificationCategory::values());
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
