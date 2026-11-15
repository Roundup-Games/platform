<?php

namespace App\Notifications\Channels;

use App\Notifications\ResolvesNotificationCategory;
use Illuminate\Notifications\Channels\MailChannel;
use Symfony\Component\Mailer\Header\MetadataHeader;

/**
 * Mail channel that tags every outgoing notification mail with its engagement
 * category (M080/S03, D284 per-category mail attribution).
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
 * Note: notifications whose toMail() returns a Mailable bypass
 * MailChannel::buildMessage() entirely (framework Mailable send path) and
 * carry no tag — every app notification returns a MailMessage.
 */
class TaggedMailChannel extends MailChannel
{
    use ResolvesNotificationCategory;

    /** Metadata key that carries the notification category onto the Resend API tags array. */
    public const CATEGORY_TAG = 'roundup_category';

    /**
     * {@inheritDoc}
     *
     * Adds the roundup_category MetadataHeader after the framework's own
     * header building (addressing, subject, the message's own
     * tags/metadata) so per-send attribution rides along on every
     * notification mail.
     */
    protected function buildMessage($mailMessage, $notifiable, $notification, $message): void
    {
        parent::buildMessage($mailMessage, $notifiable, $notification, $message);

        $mailMessage->getHeaders()->add(new MetadataHeader(
            self::CATEGORY_TAG,
            $this->resolveCategory($notification, $notifiable),
        ));
    }
}
