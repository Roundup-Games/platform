<?php

namespace App\Enums;

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
    case SeatDemoted = 'seat_demoted';
    case TeamMemberRemoved = 'team_member_removed';
    case WaitlistPromoted = 'waitlist_promoted';
    case WaitlistPlacement = 'waitlist_placement';
    case BenchUpdates = 'bench_updates';

    // Attendance
    case AttendanceReported = 'attendance_reported';
    case DisputeResolved = 'dispute_resolved';
    case AttendanceNudge = 'attendance_nudge';
    case AttendanceResolved = 'attendance_resolved';

    // Status
    case GameCancelled = 'game_cancelled';
    case GameCompleted = 'game_completed';
    case CampaignCancelled = 'campaign_cancelled';
    case CampaignCompleted = 'campaign_completed';
    case GameUpdated = 'game_updated';
    case CampaignUpdated = 'campaign_updated';
    case SessionContent = 'session_content';

    // Content
    case GameSystemRequest = 'game_system_request';

    // Scheduling
    case BelowMinPlayers = 'below_min_players';
    case ConfirmationExpired = 'confirmation_expired';
    case SessionReminder = 'session_reminder';

    // Moderation
    case ReviewReported = 'review_reported';
    case ModerationNotice = 'moderation_notice';

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
            self::SeatDemoted => __('notifications.category_seat_demoted'),
            self::TeamMemberRemoved => __('notifications.category_team_member_removed'),
            self::WaitlistPromoted => __('notifications.category_waitlist_promoted'),
            self::WaitlistPlacement => __('notifications.category_waitlist_placement'),
            self::BenchUpdates => __('notifications.category_bench_updates'),
            self::AttendanceReported => __('notifications.category_attendance_reported'),
            self::DisputeResolved => __('notifications.category_dispute_resolved'),
            self::AttendanceNudge => __('notifications.category_attendance_nudge'),
            self::AttendanceResolved => __('notifications.category_attendance_resolved'),
            self::GameCancelled => __('notifications.category_game_cancelled'),
            self::GameCompleted => __('notifications.category_game_completed'),
            self::CampaignCancelled => __('notifications.category_campaign_cancelled'),
            self::CampaignCompleted => __('notifications.category_campaign_completed'),
            self::GameUpdated => __('notifications.category_game_updated'),
            self::CampaignUpdated => __('notifications.category_campaign_updated'),
            self::SessionContent => __('notifications.category_session_content'),
            self::GameSystemRequest => __('notifications.category_game_system_request'),
            self::BelowMinPlayers => __('notifications.category_below_min_players'),
            self::ConfirmationExpired => __('notifications.category_confirmation_expired'),
            self::SessionReminder => __('notifications.category_session_reminder'),
            self::ReviewReported => __('notifications.category_review_reported'),
            self::ModerationNotice => __('notifications.category_moderation_notice'),
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::NewFollower => 'social',
            self::GameInvitation, self::CampaignInvitation, self::TeamInvitation, self::SessionAddedToCampaign => 'invitations',
            self::NewApplication, self::ApplicationApproved, self::ApplicationRejected => 'applications',
            self::ParticipantJoined, self::ParticipantRemoved, self::SeatDemoted, self::TeamMemberRemoved, self::WaitlistPromoted, self::WaitlistPlacement, self::BenchUpdates, self::AttendanceReported, self::DisputeResolved, self::AttendanceNudge, self::AttendanceResolved => 'participation',
            self::GameCancelled, self::GameCompleted, self::CampaignCancelled, self::CampaignCompleted, self::GameUpdated, self::CampaignUpdated, self::SessionContent => 'status',
            self::GameSystemRequest => 'content',
            self::BelowMinPlayers, self::ConfirmationExpired, self::SessionReminder => 'scheduling',
            self::ReviewReported, self::ModerationNotice => 'moderation',
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
                'categories' => [self::ParticipantJoined, self::ParticipantRemoved, self::SeatDemoted, self::TeamMemberRemoved, self::WaitlistPromoted, self::WaitlistPlacement, self::BenchUpdates, self::AttendanceReported, self::DisputeResolved, self::AttendanceNudge, self::AttendanceResolved],
            ],
            'status' => [
                'label' => __('notifications.group_status'),
                'categories' => [self::GameCancelled, self::GameCompleted, self::CampaignCancelled, self::CampaignCompleted, self::GameUpdated, self::CampaignUpdated, self::SessionContent],
            ],
            'content' => [
                'label' => __('notifications.group_content'),
                'categories' => [self::GameSystemRequest],
            ],
            'scheduling' => [
                'label' => __('notifications.group_scheduling'),
                'categories' => [self::BelowMinPlayers, self::ConfirmationExpired, self::SessionReminder],
            ],
            'moderation' => [
                'label' => __('notifications.group_moderation'),
                'categories' => [self::ReviewReported, self::ModerationNotice],
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
     * Whether mail is enabled by default for this category.
     *
     * Cost-aware policy: email (the only per-message channel — Resend) is on by
     * default ONLY for time-critical, actionable events the user cannot recover
     * by glancing at the bell — i.e. where missing it costs them a seat, a
     * decision, or their plans. Everything else is in-app + push only; users
     * who want those by email opt in explicitly. The weekly digest covers the
     * in-app-only events so players still hear from us cheaply.
     */
    public function defaultMailEnabled(): bool
    {
        return match ($this) {
            // Invitations that expire / need a decision
            self::GameInvitation,
            self::CampaignInvitation,
            self::TeamInvitation => true,
            self::SessionAddedToCampaign => false,

            // Application outcomes the host or applicant must act on
            self::NewApplication,
            self::ApplicationApproved => true,
            self::ApplicationRejected => false,

            // Participation changes that affect access
            self::ParticipantRemoved,
            self::SeatDemoted,
            self::TeamMemberRemoved,
            self::WaitlistPromoted => true,
            self::ParticipantJoined,
            self::WaitlistPlacement,
            self::BenchUpdates => false,

            // Attendance — push is the right channel for these
            self::AttendanceReported,
            self::DisputeResolved,
            self::AttendanceNudge,
            self::AttendanceResolved => false,

            // Entity status — cancellations change plans; the rest is ambient
            self::GameCancelled,
            self::CampaignCancelled => true,
            self::GameCompleted,
            self::CampaignCompleted,
            self::GameUpdated,
            self::CampaignUpdated,
            self::SessionContent => false,

            // Content / scheduling
            self::GameSystemRequest => false,
            self::BelowMinPlayers => false,
            self::ConfirmationExpired,
            self::SessionReminder => true,

            // Social / moderation
            self::NewFollower => false,
            self::ReviewReported => false,
            self::ModerationNotice => true,
        };
    }

    /**
     * Whether push is enabled by default for this category.
     *
     * Push is effectively free (compute only) and is the natural channel for
     * immediate-but-not-email-worthy events, so it is on by default for every
     * category except purely ambient noise (followers, completions, moderator
     * queue) where a push would feel like spam. It is also on for every
     * mail-defaulted category, since a user who granted push permission expects
     * to be reached there.
     */
    public function defaultPushEnabled(): bool
    {
        return match ($this) {
            // Ambient — no push by default
            self::NewFollower,
            self::GameCompleted,
            self::CampaignCompleted,
            self::ReviewReported => false,

            default => true,
        };
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
