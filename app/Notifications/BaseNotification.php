<?php

namespace App\Notifications;

use App\Models\User;
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
 * Provides a default via() method so that Laravel's notification sender
 * can resolve channels when notifications are dispatched outside of
 * NotificationService (e.g., in tests). Production code routes all
 * notifications through NotificationService, which resolves channels
 * from user preferences and passes them explicitly to notify().
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Default channel resolution. In production, NotificationService
     * resolves channels from user preferences and passes them explicitly,
     * so this method is not reached. It exists as a fallback for tests
     * and any direct notify() calls.
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }
}
