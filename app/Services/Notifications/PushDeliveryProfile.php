<?php

namespace App\Services\Notifications;

use App\Notifications\ResolvesNotificationCategory;
use Minishlink\WebPush\WebPush;

/**
 * Per-category push delivery policy (M081/S05): urgency and TTL for every
 * push send, resolved from the notification category vocabulary of
 * {@see ResolvesNotificationCategory} — the
 * toDatabase()['type'] wire values, not just enum cases.
 *
 * Deadline-critical categories (the recipient must act before a fixed
 * moment — waitlist confirmations, session start, entity invitations,
 * cancellations) go out with 'high' urgency and a short TTL: push services
 * may delay 'normal' delivery, and a deadline notification rendered after
 * the deadline is worse than none. Everything else — social, digests,
 * ambient updates — is 'normal' urgency with a 24h TTL.
 *
 * Event notices (EventRegistrationConfirmed / EventCancelled, M063/S03)
 * share the 'event_registration' wire type, so the pair resolves together:
 * a cancelled event someone holds a registration for is the canonical
 * wake-the-device case (travel plans change), and the just-confirmed
 * registration is time-relevant in the same window — the same family logic
 * that already puts waitlist PROMOTIONS (a confirmation) in this tier.
 *
 * Unknown categories (future types, the class-basename fallback of
 * ResolvesNotificationCategory, defensive entries like 'digest') resolve to
 * the ambient profile: new categories are never silently deadline-critical.
 */
final readonly class PushDeliveryProfile
{
    public const string URGENCY_HIGH = 'high';

    public const string URGENCY_NORMAL = 'normal';

    /**
     * Deadline-critical sends live for 12 hours — the longest confirmation
     * window they serve (WaitlistService gives 12h when the game is far
     * out; a shorter TTL would silently drop the push while the seat is
     * still confirmable, e.g. a phone offline overnight). Urgency — not
     * TTL — is what wakes devices, so extending the TTL costs nothing on
     * the happy path and only broadens the offline-delivery window.
     */
    public const int HIGH_TTL_SECONDS = 12 * 3600;

    /** Ambient sends live for 24 hours. */
    public const int NORMAL_TTL_SECONDS = 24 * 3600;

    /**
     * Deadline-critical category wire values. Entity invitation and
     * cancellation types are composed dynamically ("{$type}_invitation" /
     * "{$type}_cancelled" with $type ∈ {game, campaign}), so the concrete
     * values are listed explicitly; event confirmations and cancellations
     * share the 'event_registration' wire type (see class docblock).
     */
    private const DEADLINE_CRITICAL = [
        'waitlist_promoted',
        'session_reminder',
        'game_invitation',
        'campaign_invitation',
        'game_cancelled',
        'campaign_cancelled',
        'event_registration',
    ];

    public function __construct(
        public string $urgency,
        public int $ttl,
    ) {}

    /**
     * Resolve the delivery profile for a notification category wire value.
     */
    public static function forCategory(string $category): self
    {
        return in_array($category, self::DEADLINE_CRITICAL, true)
            ? new self(self::URGENCY_HIGH, self::HIGH_TTL_SECONDS)
            : new self(self::URGENCY_NORMAL, self::NORMAL_TTL_SECONDS);
    }

    /**
     * Web Push per-notification options accepted by
     * {@see WebPush::queueNotification()}. The option keys are the library's
     * Notification::getOptions() contract — case-sensitive and NOT the header
     * names: 'urgency' is lowercase (Minishlink maps it onto the RFC 8030
     * 'Urgency' request header), 'TTL' is capital. Sending 'Urgency' would be
     * silently ignored by the merge and no header would reach the wire.
     *
     * @return array{urgency: string, TTL: int}
     */
    public function toWebPushOptions(): array
    {
        return [
            'urgency' => $this->urgency,
            'TTL' => $this->ttl,
        ];
    }
}
