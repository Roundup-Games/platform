<?php

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;

describe('NotificationCategory', function () {
    it('has exactly 19 cases', function () {
        expect(NotificationCategory::cases())->toHaveCount(19);
    });

    it('returns correct values for all cases', function () {
        $expected = [
            'new_follower',
            'game_invitation', 'campaign_invitation', 'team_invitation', 'session_added_to_campaign',
            'new_application', 'application_approved', 'application_rejected',
            'participant_joined', 'participant_removed', 'team_member_removed',
            'game_cancelled', 'game_completed', 'campaign_cancelled', 'campaign_completed',
            'game_updated', 'campaign_updated',
            'game_system_request',
            'review_reported',
        ];
        expect(NotificationCategory::values())->toBe($expected);
    });

    it('returns translated labels for all cases', function () {
        foreach (NotificationCategory::cases() as $category) {
            $label = $category->label();
            expect($label)->not->toBeEmpty("{$category->value} should have a label");
            // Label should come from translation key, not be the raw value
            expect($label)->not->toBe($category->value, "{$category->value} label should be translated");
        }
    });

    it('group() returns correct group for each category', function () {
        expect(NotificationCategory::NewFollower->group())->toBe('social');

        expect(NotificationCategory::GameInvitation->group())->toBe('invitations');
        expect(NotificationCategory::CampaignInvitation->group())->toBe('invitations');
        expect(NotificationCategory::TeamInvitation->group())->toBe('invitations');
        expect(NotificationCategory::SessionAddedToCampaign->group())->toBe('invitations');

        expect(NotificationCategory::NewApplication->group())->toBe('applications');
        expect(NotificationCategory::ApplicationApproved->group())->toBe('applications');
        expect(NotificationCategory::ApplicationRejected->group())->toBe('applications');

        expect(NotificationCategory::ParticipantJoined->group())->toBe('participation');
        expect(NotificationCategory::ParticipantRemoved->group())->toBe('participation');
        expect(NotificationCategory::TeamMemberRemoved->group())->toBe('participation');

        expect(NotificationCategory::GameCancelled->group())->toBe('status');
        expect(NotificationCategory::GameCompleted->group())->toBe('status');
        expect(NotificationCategory::CampaignCancelled->group())->toBe('status');
        expect(NotificationCategory::CampaignCompleted->group())->toBe('status');

        expect(NotificationCategory::GameSystemRequest->group())->toBe('content');
    });

    it('grouped() returns all 7 groups', function () {
        $grouped = NotificationCategory::grouped();
        expect($grouped)->toHaveKeys(['social', 'invitations', 'applications', 'participation', 'status', 'content', 'moderation']);
    });

    it('grouped() contains all 19 categories across groups', function () {
        $grouped = NotificationCategory::grouped();
        $allValues = [];
        foreach ($grouped as $group) {
            $allValues = array_merge($allValues, array_keys($group['options']));
        }
        expect($allValues)->toHaveCount(19);
        expect($allValues)->toBe(NotificationCategory::values());
    });

    it('grouped() has translated group labels', function () {
        $grouped = NotificationCategory::grouped();
        expect($grouped['social']['label'])->not->toBeEmpty();
        expect($grouped['invitations']['label'])->not->toBeEmpty();
        expect($grouped['applications']['label'])->not->toBeEmpty();
        expect($grouped['participation']['label'])->not->toBeEmpty();
        expect($grouped['status']['label'])->not->toBeEmpty();
        expect($grouped['content']['label'])->not->toBeEmpty();
        expect($grouped['moderation']['label'])->not->toBeEmpty();
    });

    it('grouped() distributes categories correctly', function () {
        $grouped = NotificationCategory::grouped();
        expect($grouped['social']['options'])->toHaveCount(1);
        expect($grouped['invitations']['options'])->toHaveCount(4);
        expect($grouped['applications']['options'])->toHaveCount(3);
        expect($grouped['participation']['options'])->toHaveCount(3);
        expect($grouped['status']['options'])->toHaveCount(6);
        expect($grouped['content']['options'])->toHaveCount(1);
        expect($grouped['moderation']['options'])->toHaveCount(1);
    });

    it('channels() returns database and mail channels', function () {
        $channels = NotificationCategory::channels();
        expect($channels)->toContain(DatabaseChannel::class);
        expect($channels)->toContain(MailChannel::class);
        expect($channels)->toHaveCount(2);
    });

    it('defaultSettings() returns settings for all 19 categories', function () {
        $settings = NotificationCategory::defaultSettings();
        expect($settings)->toHaveCount(19);
        expect(array_keys($settings))->toBe(NotificationCategory::values());
    });

    it('defaultSettings() has database=true for all categories', function () {
        $settings = NotificationCategory::defaultSettings();
        foreach ($settings as $category => $channels) {
            expect($channels)->toHaveKey('database', true, "{$category} should have database=true");
        }
    });

    it('defaultSettings() mail defaults match high-priority policy', function () {
        $settings = NotificationCategory::defaultSettings();

        // Mail ON: invitations, application outcomes, cancellations, removals
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

        // Mail OFF: informational events
        $mailOff = ['new_follower', 'session_added_to_campaign', 'participant_joined'];
        foreach ($mailOff as $cat) {
            expect($settings[$cat]['mail'])->toBeFalse("{$cat} should default mail=false");
        }
    });

    it('defaultSettings() matches migration defaults', function () {
        $migrationDefault = [
            'new_follower' => ['database' => true, 'mail' => false],
            'game_invitation' => ['database' => true, 'mail' => true],
            'campaign_invitation' => ['database' => true, 'mail' => true],
            'team_invitation' => ['database' => true, 'mail' => true],
            'session_added_to_campaign' => ['database' => true, 'mail' => false],
            'new_application' => ['database' => true, 'mail' => true],
            'application_approved' => ['database' => true, 'mail' => true],
            'application_rejected' => ['database' => true, 'mail' => true],
            'participant_joined' => ['database' => true, 'mail' => false],
            'participant_removed' => ['database' => true, 'mail' => true],
            'team_member_removed' => ['database' => true, 'mail' => true],
            'game_cancelled' => ['database' => true, 'mail' => true],
            'game_completed' => ['database' => true, 'mail' => true],
            'campaign_cancelled' => ['database' => true, 'mail' => true],
            'campaign_completed' => ['database' => true, 'mail' => true],
            'game_updated' => ['database' => true, 'mail' => true],
            'campaign_updated' => ['database' => true, 'mail' => true],
            'game_system_request' => ['database' => true, 'mail' => true],
            'review_reported' => ['database' => true, 'mail' => true],
        ];

        expect(NotificationCategory::defaultSettings())->toBe($migrationDefault);
    });

    it('values() returns flat string array', function () {
        $values = NotificationCategory::values();
        expect($values)->toBeArray();
        foreach ($values as $value) {
            expect($value)->toBeString();
        }
    });
});
