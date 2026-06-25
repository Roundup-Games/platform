<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * Base notification for all application notifications.
 *
 * All notifications implement ShouldQueue so they are dispatched to the
 * queue via NotificationService. In the test environment (QUEUE_CONNECTION=sync)
 * they run synchronously, keeping test assertions straightforward.
 *
 * Channel resolution has two layers:
 *   1. supportedChannels() — what this notification TYPE can use (e.g. a
 *      database-only notification returns just DatabaseChannel).
 *   2. resolvedChannels — the recipient-specific subset (supported ∩ enabled
 *      in their notification_settings), set by NotificationService::send()
 *      before dispatch.
 *
 * via() returns resolvedChannels when set, falling back to supportedChannels().
 * Laravel's queued dispatcher reads via() at enqueue time to decide which
 * channels get jobs, so returning the resolved subset propagates the user's
 * preferences (e.g. mail disabled) across the queue boundary.
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Channels resolved by NotificationService for a specific recipient —
     * the intersection of what this notification supports and what the
     * recipient has enabled. Null until the service sets it.
     *
     * @var array<int, string>|null
     */
    protected ?array $resolvedChannels = null;

    /**
     * Channels this notification type supports by default. Subclasses
     * override to declare a narrower set (e.g. database-only notifications).
     *
     * Push is auto-detected: a notification that implements toPush() declares
     * push intent, so PushChannel is included automatically. This keeps the
     * 30+ toPush() implementations reachable without each subclass repeating
     * the channel declaration.
     *
     * @return array<int, string>
     */
    protected function supportedChannels(): array
    {
        $channels = [DatabaseChannel::class, MailChannel::class];

        if (method_exists($this, 'toPush')) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }

    /**
     * Channels Laravel will dispatch to. Returns the recipient-specific
     * resolved set when set by NotificationService, otherwise the type's
     * supported channels (fallback for direct notify() calls / tests).
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $this->resolvedChannels ?? $this->supportedChannels();
    }

    /**
     * Restrict dispatch to the given channels. Called by
     * NotificationService::send() after intersecting the recipient's enabled
     * channels with this notification's supported channels.
     *
     * @param  array<int, string>  $channels
     */
    public function setResolvedChannels(array $channels): static
    {
        $this->resolvedChannels = $channels;

        return $this;
    }
}
