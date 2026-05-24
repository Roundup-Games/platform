<?php

namespace App\Notifications;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * Base notification for all application notifications.
 *
 * Provides a default via() method so that Laravel's notification sender
 * can resolve channels when notifications are dispatched outside of
 * NotificationService (e.g., in tests). Production code routes all
 * notifications through NotificationService, which resolves channels
 * from user preferences and passes them explicitly to notifyNow().
 */
abstract class BaseNotification extends Notification
{
    /**
     * Default channel resolution. In production, NotificationService
     * resolves channels from user preferences and passes them explicitly,
     * so this method is not reached. It exists as a fallback for tests
     * and any direct notify() calls.
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }
}
