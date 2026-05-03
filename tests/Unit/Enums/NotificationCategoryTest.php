<?php

use App\Enums\NotificationCategory;

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

    // Note: grouped() and label() require Laravel's translator (app container).
    // Those are tested in tests/Feature/Enums/NotificationCategoryTest.php.
});
