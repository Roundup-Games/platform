<?php

namespace App\Enums;

enum NotificationCategory: string
{
    /**
     * data.type values that are deliberately NOT enum cases, mapped to the
     * preference family that governs them — the counter vocabulary (D284)
     * translated into the settings vocabulary. Mirrors the "preference family"
     * annotations in NotificationCorpusRenderTest::corpusTypeOnlyCategories().
     *
     * The moderation cluster (account_suspended, content_removed,
     * cover_image_removed, content_report_warning) IS family-mapped: all
     * four are dispatched through NotificationService::send() under
     * NotificationCategory::ModerationNotice, so the user's moderation_notice
     * preference row (mail ON by default) genuinely gates these mails —
     * surfaces reading preferenceFamilyForType() must offer the settings
     * shortcut for them, not silently omit it (M081 fifth-pass review;
     * previously mis-documented as "no preference row to tune"). 'digest' is
     * the one true family-less entry: the weekly digest is governed by the
     * separate weekly_digest_enabled flag, not any category preference.
     */
    private const TYPE_ONLY_PREFERENCE_FAMILIES = [
        'account_suspended' => self::ModerationNotice,
        'badge_upgraded' => self::BadgeAwarded,
        'bulletin_posted' => self::SessionContent,
        'content_removed' => self::ModerationNotice,
        'content_report_warning' => self::ModerationNotice,
        'cover_image_removed' => self::ModerationNotice,
        'debriefing_available' => self::SessionContent,
        'game_system_request_approved' => self::GameSystemRequest,
        'game_system_request_duplicate' => self::GameSystemRequest,
        'game_system_request_rejected' => self::GameSystemRequest,
        'player_benched' => self::BenchUpdates,
        'promoted_from_bench' => self::BenchUpdates,
        'recap_posted' => self::SessionContent,
        'waitlist_expired_rejected' => self::ConfirmationExpired,
        'waitlist_placed' => self::WaitlistPlacement,
    ];

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

    // Campaign charter lifecycle (M070/D234)
    case CharterPublished = 'charter_published';
    case CharterAmended = 'charter_amended';
    case CharterFullySigned = 'charter_fully_signed';

    // Events (M063 — registrant communications)
    case EventRegistration = 'event_registration';

    // Content
    case GameSystemRequest = 'game_system_request';

    // Scheduling
    case BelowMinPlayers = 'below_min_players';
    case ConfirmationExpired = 'confirmation_expired';
    case SessionReminder = 'session_reminder';

    // Moderation
    case ReviewReported = 'review_reported';
    case ModerationNotice = 'moderation_notice';

    // Achievements (M069 — badges)
    case BadgeAwarded = 'badge_awarded';

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
            // Lifecycle-event labels are shared with the community activity feed
            // (ActivityType enum) via the common.activity_type_* family; the
            // remaining categories are notification-specific.
            self::GameCancelled => __('common.activity_type_game_canceled'),
            self::GameCompleted => __('common.activity_type_game_completed'),
            self::CampaignCancelled => __('common.activity_type_campaign_canceled'),
            self::CampaignCompleted => __('common.activity_type_campaign_completed'),
            self::GameUpdated => __('notifications.category_game_updated'),
            self::CampaignUpdated => __('notifications.category_campaign_updated'),
            self::SessionContent => __('notifications.category_session_content'),
            self::CharterPublished => __('notifications.category_charter_published'),
            self::CharterAmended => __('notifications.category_charter_amended'),
            self::CharterFullySigned => __('notifications.category_charter_fully_signed'),
            self::EventRegistration => __('notifications.category_event_registration'),
            self::GameSystemRequest => __('notifications.category_game_system_request'),
            self::BelowMinPlayers => __('notifications.category_below_min_players'),
            self::ConfirmationExpired => __('notifications.category_confirmation_expired'),
            self::SessionReminder => __('notifications.category_session_reminder'),
            self::ReviewReported => __('notifications.category_review_reported'),
            self::ModerationNotice => __('notifications.category_moderation_notice'),
            self::BadgeAwarded => __('notifications.category_badge_awarded'),
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::NewFollower => 'social',
            self::GameInvitation, self::CampaignInvitation, self::TeamInvitation, self::SessionAddedToCampaign => 'invitations',
            self::NewApplication, self::ApplicationApproved, self::ApplicationRejected => 'applications',
            self::ParticipantJoined, self::ParticipantRemoved, self::SeatDemoted, self::TeamMemberRemoved, self::WaitlistPromoted, self::WaitlistPlacement, self::BenchUpdates, self::AttendanceReported, self::DisputeResolved, self::AttendanceNudge, self::AttendanceResolved => 'participation',
            self::GameCancelled, self::GameCompleted, self::CampaignCancelled, self::CampaignCompleted, self::GameUpdated, self::CampaignUpdated, self::SessionContent, self::CharterPublished, self::CharterAmended,
            self::CharterFullySigned => 'status',
            self::EventRegistration => 'events',
            self::GameSystemRequest => 'content',
            self::BelowMinPlayers, self::ConfirmationExpired, self::SessionReminder => 'scheduling',
            self::ReviewReported, self::ModerationNotice => 'moderation',
            self::BadgeAwarded => 'achievements',
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
                'label' => __('common.content_social'),
                'categories' => [self::NewFollower],
            ],
            'invitations' => [
                'label' => __('notifications.group_invitations'),
                'categories' => [self::GameInvitation, self::CampaignInvitation, self::TeamInvitation, self::SessionAddedToCampaign],
            ],
            'applications' => [
                'label' => __('common.content_applications'),
                'categories' => [self::NewApplication, self::ApplicationApproved, self::ApplicationRejected],
            ],
            'participation' => [
                'label' => __('notifications.group_participation'),
                'categories' => [self::ParticipantJoined, self::ParticipantRemoved, self::SeatDemoted, self::TeamMemberRemoved, self::WaitlistPromoted, self::WaitlistPlacement, self::BenchUpdates, self::AttendanceReported, self::DisputeResolved, self::AttendanceNudge, self::AttendanceResolved],
            ],
            'status' => [
                'label' => __('common.content_status'),
                'categories' => [self::GameCancelled, self::GameCompleted, self::CampaignCancelled, self::CampaignCompleted, self::GameUpdated, self::CampaignUpdated, self::SessionContent, self::CharterPublished, self::CharterAmended,
                    self::CharterFullySigned],
            ],
            'events' => [
                'label' => __('notifications.group_events'),
                'categories' => [self::EventRegistration],
            ],
            'content' => [
                'label' => __('common.content_content'),
                'categories' => [self::GameSystemRequest],
            ],
            'scheduling' => [
                'label' => __('notifications.group_scheduling'),
                'categories' => [self::BelowMinPlayers, self::ConfirmationExpired, self::SessionReminder],
            ],
            'moderation' => [
                'label' => __('common.content_moderation'),
                'categories' => [self::ReviewReported, self::ModerationNotice],
            ],
            'achievements' => [
                'label' => __('notifications.group_achievements'),
                'categories' => [self::BadgeAwarded],
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
     * Resolve a stored data.type (the counter vocabulary) to the
     * NotificationCategory whose preference row governs it.
     *
     * Enum values resolve to themselves; deliberate non-enum variants
     * (TYPE_ONLY_PREFERENCE_FAMILIES) resolve to their family; anything
     * else — administrative one-offs and unknown future types — resolves
     * to null, so callers can omit the settings affordance entirely.
     */
    public static function preferenceFamilyForType(string $type): ?self
    {
        return self::tryFrom($type)
            ?? self::TYPE_ONLY_PREFERENCE_FAMILIES[$type] ?? null;
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
            self::SessionContent,
            self::CharterPublished,
            self::CharterAmended => false,

            // A governance action the GM owes — the one charter notice whose
            // recipient must DO something next (countersign)
            self::CharterFullySigned => true,

            // Events — a registration confirmation is the receipt for a seat
            // the registrant just paid for or claimed; missing it costs them
            // their plans (no bell-glance recovery once the browser closes).
            self::EventRegistration => true,

            // Content / scheduling
            self::GameSystemRequest => false,
            self::BelowMinPlayers => false,
            self::ConfirmationExpired,
            self::SessionReminder => true,

            // Social / moderation
            self::NewFollower => false,
            self::ReviewReported => false,
            self::ModerationNotice => true,

            // Achievements — celebratory, not time-critical: in-app + push
            // by default, weekly digest covers the email surface (spec §2.2).
            self::BadgeAwarded => false,
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
     * Whether Discord DM is enabled by default for this category.
     *
     * Discord is a free, push-like channel that requires the member to have
     * explicitly linked a Discord account, so it inherits the push defaults:
     * on for every actionable category (where a DM alongside the in-app
     * notification is valuable to a linked user), off for pure ambient noise
     * (followers, completions, review queue) where a DM would feel like spam.
     * Linked members get value immediately; unlinked members never see the
     * column (T04) and the channel is a graceful no-op (T02).
     */
    public function defaultDiscordEnabled(): bool
    {
        return $this->defaultPushEnabled();
    }

    /**
     * Whether events of this category batch into the burst defer window
     * (D296, M082/S01): NotificationService::send() buffers them into a
     * 5-minute Postgres batch (keyed recipient × category × entity) that
     * flushes as ONE notification, instead of dispatching each event
     * immediately.
     *
     * Semantic rationale — the v1 set is exactly the host-side,
     * non-deadline, structurally bursty categories: ParticipantJoined and
     * NewApplication (an open game collects joins and applications in
     * clusters while it circulates) and SessionAddedToCampaign (a host
     * typically schedules several sessions at once). They are awareness
     * signals for the host: nothing expires and no seat is lost when
     * delivery waits for the window to close. Every deadline- or
     * plan-critical category — invitations, waitlist promotions,
     * cancellations, moderation, attendance nudges/resolutions, session
     * reminders, confirmation expiry — NEVER batches, mirroring the
     * mail-tier urgency philosophy of defaultMailEnabled().
     *
     * The Filament policy matrix page (S03, NotificationBatchingPage)
     * renders this method's verdict per category with rationale and live
     * batch-vs-immediate counter columns, so the visible policy can never
     * drift from what the dispatch path enforces. Widening the set is
     * data-gated (D296 deferrals) — revisit with real counter volumes.
     */
    public function isBatchable(): bool
    {
        return match ($this) {
            self::ParticipantJoined,
            self::NewApplication,
            self::SessionAddedToCampaign => true,

            default => false,
        };
    }

    /**
     * Semantic rationale for this category's batching verdict (M082/S03,
     * D296) — the copy the admin Notification Batching matrix renders next
     * to the isBatchable() status. EN by design: it documents an admin-only
     * System surface (filament views are EN-only, K002) and lives in code so
     * the rationale and the verdict it explains are reviewed together.
     *
     * Every string states WHY the verdict is safe or necessary: batched rows
     * name the burst shape and the no-deadline argument; never-batched rows
     * name the deadline/plan/access cost of deferral (or, for ambient
     * categories, the absence of a burst to collapse — v1 scope, widening is
     * data-gated per D296).
     */
    public function batchingRationale(): string
    {
        return match ($this) {
            // Batched (v1) — host-side, non-deadline, structurally bursty
            self::ParticipantJoined => 'Host-side awareness signal: an open game collects joins in clusters while it circulates. Nothing expires and no seat is lost when delivery waits — five joins land as one notification.',
            self::NewApplication => 'Host-side decision signal arriving in bursts while a game is open; the defer window collapses a burst into one application digest and no applicant loses a seat to the wait.',
            self::SessionAddedToCampaign => 'Hosts schedule sessions in clusters; the burst collapses into one per-campaign update. New sessions are awareness, not a decision with a deadline.',

            // Social — ambient, no burst shape
            self::NewFollower => 'Ambient social awareness — a single per-follower notice with no per-entity burst to collapse in v1. Data-gated for widening (D296).',

            // Invitations — deadline-critical by construction
            self::GameInvitation => 'Deadline-critical: the invitation expires. The defer window would consume reply time the sender already paid for.',
            self::CampaignInvitation => 'Deadline-critical: campaign invitations expire — deferral trades the recipient\'s decision time for nothing.',
            self::TeamInvitation => 'Deadline-critical: team invitations expire — the recipient must see it the moment it lands.',

            // Applications — outcome events the applicant is waiting on
            self::ApplicationApproved => 'The applicant is waiting on this outcome to plan — every deferred minute is avoidable uncertainty.',
            self::ApplicationRejected => 'Outcome event the applicant is actively waiting for — immediate, so the decision loop closes now.',

            // Participation access changes — effective immediately
            self::ParticipantRemoved => 'Access change: the recipient just lost a seat and needs the context to react immediately.',
            self::SeatDemoted => 'Access change (bench demotion) — the recipient\'s standing changed now; the notice must too.',
            self::TeamMemberRemoved => 'Access change: removal from a team is effective immediately, and so is the notice.',
            self::WaitlistPromoted => 'The most time-sensitive participant event: a seat is gained and must be confirmed before it expires. Hard never-batch guarantee — fires immediately even while an open batch exists.',
            self::WaitlistPlacement => 'Single placement notice — not deadline-critical, but no per-entity burst shape in v1. Widening the batched set is data-gated (D296).',
            self::BenchUpdates => 'Bench movements are plan-adjacent (confirmation clocks) and arrive as single notices in v1 — data-gated for any widening.',

            // Attendance — time-bounded accountability loops
            self::AttendanceReported => 'Starts the confirmation clock on the record — deferral shortens the window to respond or dispute.',
            self::DisputeResolved => 'The disputant is waiting for the verdict; disputes resolve one at a time, so there is no burst to collapse.',
            self::AttendanceNudge => 'The nudge IS the deadline signal — buffering it for five minutes defeats its purpose.',
            self::AttendanceResolved => 'Closes the loop AttendanceNudge opens — a waiting state ends now, so delivery is now.',

            // Entity status — cancellations rewrite plans; the rest is ambient
            self::GameCancelled => 'Cancellation rewrites the recipient\'s plans; five minutes of silence is plan-critical dead time. Hard never-batch guarantee.',
            self::CampaignCancelled => 'Cancellation rewrites plans for every campaign member — immediate. Hard never-batch guarantee.',
            self::GameCompleted => 'Ambient lifecycle milestone — a single event per game end, no burst shape. Data-gated for widening.',
            self::CampaignCompleted => 'Ambient lifecycle milestone — a single event, no burst shape. Data-gated for widening.',
            self::GameUpdated => 'Edits can change plans (time, venue, roster); v1 ships every update immediately. Collapse is a data-gated candidate once counter volumes show edit bursts.',
            self::CampaignUpdated => 'Campaign edits can change commitments; v1 ships immediately — widening is data-gated.',
            self::SessionContent => 'Recaps, bulletins and debriefings are ambient reading with no deadline — single posts with no burst shape in v1. Data-gated.',
            self::CharterPublished => 'Governance: the charter is the contract members signed up for — publication ships immediately.',
            self::CharterAmended => 'Governance: an amendment changes the campaign contract — members must see the delta now, not next window.',
            self::CharterFullySigned => 'Governance: the roster just completed and the GM owes the countersignature that completes the agreement — one recipient, one event, nothing to batch.',

            // Events
            self::EventRegistration => 'The receipt for a seat just claimed — confirmation is part of the transaction, not an optimization candidate.',

            // Content
            self::GameSystemRequest => 'The requester is waiting on the outcome — single response events, no burst shape. Immediate.',

            // Scheduling — the window IS the deadline
            self::BelowMinPlayers => 'The game needs players inside a shrinking window — buffering costs exactly the time the game needs.',
            self::ConfirmationExpired => 'The expiry IS the deadline event — a delayed expiry notice is a contradiction.',
            self::SessionReminder => 'Reminder timing is the product — a reminder delivered late by design is not a reminder. Never buffered.',

            // Moderation — trust and account consequences
            self::ReviewReported => 'Moderator-queue intake and a trust signal to the reporter — the report must be visible now.',
            self::ModerationNotice => 'Account-level consequences (suspension, content removal) — the recipient must learn immediately. Hard never-batch guarantee.',

            // Achievements
            self::BadgeAwarded => 'Celebratory single event — no deadline, no burst shape per recipient. Data-gated for widening.',
        };
    }

    /**
     * Build the default notification preference matrix.
     * Each category maps to database (in-app), mail, push, and discord booleans.
     *
     * A missing discord key in a stored row falls back to
     * defaultDiscordEnabled() at read time (NotificationService::resolveChannels),
     * so no data migration is required for existing users (MEM856).
     *
     * @return array<string, array{database: bool, mail: bool, push: bool, discord: bool}>
     */
    public static function defaultSettings(): array
    {
        $settings = [];
        foreach (self::cases() as $category) {
            $settings[$category->value] = [
                'database' => true,
                'mail' => $category->defaultMailEnabled(),
                'push' => $category->defaultPushEnabled(),
                'discord' => $category->defaultDiscordEnabled(),
            ];
        }

        return $settings;
    }
}
