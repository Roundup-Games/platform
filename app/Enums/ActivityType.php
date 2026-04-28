<?php

namespace App\Enums;

enum ActivityType: string
{
    // Game lifecycle
    case GameCreated = 'game_created';
    case GameCompleted = 'game_completed';
    case GameCanceled = 'game_canceled';

    // Campaign lifecycle
    case CampaignCreated = 'campaign_created';
    case CampaignCompleted = 'campaign_completed';
    case CampaignCanceled = 'campaign_canceled';

    // Participation
    case PlayerJoined = 'player_joined';

    // Social
    case ReviewReceived = 'review_received';
    case FollowReceived = 'follow_received';

    // Invitations
    case InvitationReceived = 'invitation_received';
    case InvitationAccepted = 'invitation_accepted';

    // Scheduling
    case SessionScheduled = 'session_scheduled';

    // Updates
    case GameUpdated = 'game_updated';
    case CampaignUpdated = 'campaign_updated';

    // Post-session engagement
    case SessionRecapped = 'session_recapped';

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
            self::GameCreated => __('common.activity_type_game_created'),
            self::GameCompleted => __('common.activity_type_game_completed'),
            self::GameCanceled => __('common.activity_type_game_canceled'),
            self::CampaignCreated => __('common.activity_type_campaign_created'),
            self::CampaignCompleted => __('common.activity_type_campaign_completed'),
            self::CampaignCanceled => __('common.activity_type_campaign_canceled'),
            self::PlayerJoined => __('common.activity_type_player_joined'),
            self::ReviewReceived => __('common.activity_type_review_received'),
            self::FollowReceived => __('common.activity_type_follow_received'),
            self::InvitationReceived => __('common.activity_type_invitation_received'),
            self::InvitationAccepted => __('common.activity_type_invitation_accepted'),
            self::SessionScheduled => __('common.activity_type_session_scheduled'),
            self::GameUpdated => __('common.activity_type_game_updated'),
            self::CampaignUpdated => __('common.activity_type_campaign_updated'),
            self::SessionRecapped => __('common.activity_type_session_recapped'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GameCreated => 'casino',
            self::GameCompleted => 'emoji_events',
            self::GameCanceled => 'cancel',
            self::CampaignCreated => 'flag',
            self::CampaignCompleted => 'verified',
            self::CampaignCanceled => 'block',
            self::PlayerJoined => 'person_add',
            self::ReviewReceived => 'rate_review',
            self::FollowReceived => 'group_add',
            self::InvitationReceived => 'mail',
            self::InvitationAccepted => 'how_to_reg',
            self::SessionScheduled => 'event',
            self::GameUpdated => 'edit',
            self::CampaignUpdated => 'edit',
            self::SessionRecapped => 'auto_stories',
        };
    }
}
