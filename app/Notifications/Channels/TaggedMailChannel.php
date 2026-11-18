<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\ResolvesNotificationCategory;
use App\Services\Mail\SignedUnsubscribeUrls;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Channels\MailChannel;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Mail channel that tags every outgoing notification mail with its engagement
 * category (M080/S03, D284 per-category mail attribution) and stamps the
 * List-Unsubscribe trust headers on every category mail (M081/S02/T02).
 *
 * ── Why a channel swap (the chokepoint decision) ──────────────────────
 * The category each mail belongs to is only observable where the Symfony
 * message exists. BaseNotification deliberately has no shared toMail path
 * (every subclass keeps an explicit per-channel method), and
 * NotificationService::send() only calls $notifiable->notify() — before any
 * MailMessage exists. Scattering ->withSymfonyMessage() across the 40+
 * subclasses was rejected; instead this class replaces the framework channel
 * via a single container binding in AppServiceProvider::register
 * (MailChannel::class → self). ChannelManager::createDriver resolves
 * class-string drivers through the container, so both sync dispatch and
 * queued SendQueuedNotifications hit this override; via() keeps returning
 * MailChannel::class everywhere, preserving NotificationService's strict
 * in_array channel intersect (swapping the class-string in supportedChannels
 * instead would silently drop all mail dispatch).
 *
 * ── How the tag reaches Resend and comes back ──────────────────────────
 * resend/resend-laravel v1.7.0's ResendTransportFactory maps every Symfony
 * MetadataHeader on the message into the Resend API send's `tags` array as
 * {name, value} (the generic headers field is not the attribution channel —
 * vendor-confirmed 2026-11-14). Resend's engagement webhooks then echo the
 * tags at `data.tags` as a name→value map — e.g. for email.delivered:
 * {"data": {..., "tags": {"roundup_category": "waitlist_promoted"}}}
 * (payload shape per resend.com/docs/webhooks/emails/delivered) — which the
 * Resend webhook handler reads back to attribute the outcome to a category.
 *
 * Tag constraints: the name is fixed lowercase-snake (Resend requires
 * lowercase letters/numbers/dashes in tag names); the value is the category
 * string, which NotificationCategory keeps lowercase snake.
 *
 * ── List-Unsubscribe + RFC 8058 one-click headers (M081/S02/T02) ──────
 * Every CATEGORY mail additionally carries:
 *   List-Unsubscribe: <https one-click URL>, <mailto fallback>
 *   List-Unsubscribe-Post: List-Unsubscribe=One-Click
 * so mailbox providers (Gmail et al.) surface a native unsubscribe affordance
 * and may POST the URL for RFC 8058 one-click opt-out (the URL serves both
 * GET and POST — NotificationController::unsubscribe[Digest], M081/S02/T01).
 * The https URL comes from SignedUnsubscribeUrls — the single generator
 * behind the mail body's unsubscribe line — so the header link, the body
 * link, and the one-click POST validate one identical signature. The mailto
 * entry (mail.unsubscribe_mailto, defaulting to the company support address)
 * is the human-managed fallback, with the raw category token in the subject
 * for support triage.
 *
 * SKIP RULE — transactional mail gets NO unsubscribe headers. Only category
 * mail qualifies: the resolved category is a NotificationCategory enum value
 * (category URL), or 'digest' for the weekly digest (digest URL). When the
 * resolved category falls back to the class basename — framework
 * notifications without a category payload (VerifyEmail, ResetPassword), app
 * classes whose toDatabase() carries no type, or the escalated support-desk
 * notifications (Escalated\Laravel\*Notification: they define toArray(), not
 * toDatabase(), so their payload types like 'new_ticket' are never consulted
 * and the tag value is the class basename, e.g. NewTicketNotification) — the
 * mail is transactional, not category mail: there is no category setting the
 * signed URL could flip, so the headers are deliberately omitted. Non-User
 * notifiables are skipped the same way (no signed-URL target exists).
 *
 * Header values are header-injection-safe by construction: the category
 * must pass enum/digest validation, the mailto subject is percent-encoded,
 * and the assembled value is stripped of CR/LF/NUL defensively.
 *
 * Note: notifications whose toMail() returns a Mailable bypass
 * MailChannel::buildMessage() entirely (framework Mailable send path) and
 * carry no tag — every app notification returns a MailMessage.
 */
class TaggedMailChannel extends MailChannel
{
    use ResolvesNotificationCategory;

    /** Metadata key that carries the notification category onto the Resend API tags array. */
    public const CATEGORY_TAG = 'roundup_category';

    /** Category the weekly digest mail resolves to (WeeklyDigest::toDatabase type — not a NotificationCategory case). */
    public const DIGEST_CATEGORY = 'digest';

    /**
     * {@inheritDoc}
     *
     * Adds the roundup_category MetadataHeader after the framework's own
     * header building (addressing, subject, the message's own
     * tags/metadata) so per-send attribution rides along on every
     * notification mail, then stamps the List-Unsubscribe trust headers
     * on category mail (see the class docblock for the skip rule).
     */
    protected function buildMessage($mailMessage, $notifiable, $notification, $message): void
    {
        parent::buildMessage($mailMessage, $notifiable, $notification, $message);

        $category = $this->resolveCategory($notification, $notifiable);

        $mailMessage->getHeaders()->add(new MetadataHeader(
            self::CATEGORY_TAG,
            $category,
        ));

        $this->stampUnsubscribeHeaders($mailMessage, $notifiable, $category);
    }

    /**
     * Stamp List-Unsubscribe + List-Unsubscribe-Post on category mail. Runs
     * after the category MetadataHeader so the trust headers and the
     * attribution tag always derive from the same resolved category string.
     *
     * Locale mirrors BaseNotification::resolveLocale — preferred_language,
     * else the current app locale — so the header URL and the mail body's
     * unsubscribe line are byte-identical (the signature covers the
     * locale-bearing path, so a locale mismatch would be a broken link).
     */
    private function stampUnsubscribeHeaders(Message $mailMessage, mixed $notifiable, string $category): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $urls = app(SignedUnsubscribeUrls::class);
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        $url = match (true) {
            $category === self::DIGEST_CATEGORY => $urls->forDigest($notifiable, $locale),
            NotificationCategory::tryFrom($category) instanceof NotificationCategory => $urls->forCategory($notifiable, $locale, $category),
            default => null, // Basename fallback — transactional mail, no unsubscribe headers.
        };

        if ($url === null) {
            return;
        }

        $mailMessage->getHeaders()->add(new UnstructuredHeader(
            'List-Unsubscribe',
            $this->sanitizeHeaderValue($this->unsubscribeHeaderValue($url, $category)),
        ));
        // RFC 8058 §3 ABNF pins the field name: List-Unsubscribe-Post
        // (an "Unsubscribe-Post" spelling is read by no provider and
        // silently disables the one-click surface — sixth-pass review).
        $mailMessage->getHeaders()->add(new UnstructuredHeader(
            'List-Unsubscribe-Post',
            'List-Unsubscribe=One-Click',
        ));
    }

    /**
     * Assemble the List-Unsubscribe value: the signed https one-click URL,
     * plus the mailto fallback when an address is configured. The mailto
     * entry carries the mailto: scheme (RFC 2369 requires URIs) and its
     * subject carries the raw category token (what support needs to act on
     * the opt-out) percent-encoded for the mailto URI.
     */
    private function unsubscribeHeaderValue(string $url, string $category): string
    {
        $mailto = config('mail.unsubscribe_mailto');

        if (! is_string($mailto) || $mailto === '') {
            return '<'.$url.'>';
        }

        // RFC 2369 entries must be URIs — tolerate a configured bare
        // address by prefixing the mailto scheme (the S02 human-review
        // resolution: strict mailbox providers ignore scheme-less legs).
        if (! str_starts_with($mailto, 'mailto:')) {
            $mailto = 'mailto:'.$mailto;
        }

        return '<'.$url.'>, <'.$mailto.'?subject='.rawurlencode('Unsubscribe: '.$category).'>';
    }

    /**
     * Defense-in-depth against header injection: everything reaching this
     * point is already safe by construction (enum/digest-validated
     * category, framework-generated signed URL, percent-encoded mailto
     * subject), but CR/LF/NUL still never reach the wire.
     */
    private function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }
}
