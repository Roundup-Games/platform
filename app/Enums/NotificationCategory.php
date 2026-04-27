<?php

namespace App\Enums;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;

enum NotificationCategory: string
{
    // Social
    case NewFollower = 'new_follower';

    // Invitations
    case GameInvitation = 'game_invitation';
    case CampaignInvitation = 'campaign_invitation';
    case TeamInvitation = 'team_invitation';
    case SessionAddedToCampaign = 'session_added_to_campaign';

    // Applications
    case NewApplication = 'new_application';
    case ApplicationApproved = 'application_approved';
    case ApplicationRejected = 'application_rejected';

    // Participation
    case ParticipantJoined = 'participant_joined';
    case ParticipantRemoved = 'participant_removed';
    case TeamMemberRemoved = 'team_member_removed';

    // Status
    case GameCancelled = 'game_cancelled';
    case GameCompleted = 'game_completed';
    case CampaignCancelled = 'campaign_cancelled';
    case CampaignCompleted = 'campaign_completed';
    case GameUpdated = 'game_updated';
    case CampaignUpdated = 'campaign_updated';

    // Content
    case GameSystemRequest = 'game_system_request';

    // Moderation
    case ReviewReported = 'review_reported';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::NewFollower => __('notifications.category_new_follower'),
            self::GameInvitation => __('notifications.category_game_invitation'),
            self::CampaignInvitation => __('notifications.category_campaign_invitation'),
            self::TeamInvitation => __('notifications.category_team_invitation'),
            self::SessionAddedToCampaign => __('notifications.category_session_added_to_campaign'),
            self::NewApplication => __('notifications.category_new_application'),
            self::ApplicationApproved => __('notifications.category_application_approved'),
            self::ApplicationRejected => __('notifications.category_application_rejected'),
            self::ParticipantJoined => __('notifications.category_participant_joined'),
            self::ParticipantRemoved => __('notifications.category_participant_removed'),
            self::TeamMemberRemoved => __('notifications.category_team_member_removed'),
            self::GameCancelled => __('notifications.category_game_cancelled'),
            self::GameCompleted => __('notifications.category_game_completed'),
            self::CampaignCancelled => __('notifications.category_campaign_cancelled'),
            self::CampaignCompleted => __('notifications.category_campaign_completed'),
            self::GameUpdated => __('notifications.category_game_updated'),
            self::CampaignUpdated => __('notifications.category_campaign_updated'),
            self::GameSystemRequest => __('notifications.category_game_system_request'),
            self::ReviewReported => __('notifications.category_review_reported'),
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::NewFollower => 'social',
            self::GameInvitation, self::CampaignInvitation, self::TeamInvitation, self::SessionAddedToCampaign => 'invitations',
            self::NewApplication, self::ApplicationApproved, self::ApplicationRejected => 'applications',
            self::ParticipantJoined, self::ParticipantRemoved, self::TeamMemberRemoved => 'participation',
            self::GameCancelled, self::GameCompleted, self::CampaignCancelled, self::CampaignCompleted, self::GameUpdated, self::CampaignUpdated => 'status',
            self::GameSystemRequest => 'content',
            self::ReviewReported => 'moderation',
        };
    }

    /**
     * Grouped categories for UI display.
     *
     * @return array<string, array{label: string, options: array<string, string>}>
     */
    public static function grouped(): array
    {
        $groups = [
            'social' => [
                'label' => __('notifications.group_social'),
                'categories' => [self::NewFollower],
            ],
            'invitations' => [
                'label' => __('notifications.group_invitations'),
                'categories' => [self::GameInvitation, self::CampaignInvitation, self::TeamInvitation, self::SessionAddedToCampaign],
            ],
            'applications' => [
                'label' => __('notifications.group_applications'),
                'categories' => [self::NewApplication, self::ApplicationApproved, self::ApplicationRejected],
            ],
            'participation' => [
                'label' => __('notifications.group_participation'),
                'categories' => [self::ParticipantJoined, self::ParticipantRemoved, self::TeamMemberRemoved],
            ],
            'status' => [
                'label' => __('notifications.group_status'),
                'categories' => [self::GameCancelled, self::GameCompleted, self::CampaignCancelled, self::CampaignCompleted, self::GameUpdated, self::CampaignUpdated],
            ],
            'content' => [
                'label' => __('notifications.group_content'),
                'categories' => [self::GameSystemRequest],
            ],
            'moderation' => [
                'label' => __('notifications.group_moderation'),
                'categories' => [self::ReviewReported],
            ],
        ];

        $result = [];
        foreach ($groups as $key => $group) {
            $options = [];
            foreach ($group['categories'] as $category) {
                $options[$category->value] = $category->label();
            }
            $result[$key] = [
                'label' => $group['label'],
                'options' => $options,
            ];
        }

        return $result;
    }

    /**
     * The notification channels available for this category.
     *
     * @return class-string[]
     */
    public static function channels(): array
    {
        return [
            DatabaseChannel::class,
            MailChannel::class,
        ];
    }

    /**
     * Whether mail is enabled by default for this category.
     * Mail defaults to true for high-priority actionable events
     * (invitations, cancellations, application outcomes, removals).
     */
    public function defaultMailEnabled(): bool
    {
        return match ($this) {
            self::NewFollower => false,
            self::GameInvitation => true,
            self::CampaignInvitation => true,
            self::TeamInvitation => true,
            self::SessionAddedToCampaign => false,
            self::NewApplication => true,
            self::ApplicationApproved => true,
            self::ApplicationRejected => true,
            self::ParticipantJoined => false,
            self::ParticipantRemoved => true,
            self::TeamMemberRemoved => true,
            self::GameCancelled => true,
            self::GameCompleted => true,
            self::CampaignCancelled => true,
            self::CampaignCompleted => true,
            self::GameUpdated => true,
            self::CampaignUpdated => true,
            self::GameSystemRequest => true,
            self::ReviewReported => true,
        };
    }

    /**
     * Whether push is enabled by default for this category.
     * Push follows the same rule as mail — enabled for high-priority actionable events.
     */
    public function defaultPushEnabled(): bool
    {
        return $this->defaultMailEnabled();
    }

    /**
     * Build the default notification preference matrix.
     * Each category maps to database (in-app), mail, and push channel booleans.
     *
     * @return array<string, array{database: bool, mail: bool, push: bool}>
     */
    public static function defaultSettings(): array
    {
        $settings = [];
        foreach (self::cases() as $category) {
            $settings[$category->value] = [
                'database' => true,
                'mail' => $category->defaultMailEnabled(),
                'push' => $category->defaultPushEnabled(),
            ];
        }

        return $settings;
    }
}
