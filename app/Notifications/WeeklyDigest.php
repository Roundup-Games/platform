<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Weekly digest email summarising a user's unread in-app notifications.
 *
 * This is the cost-conscious alternative to per-category email: users who keep
 * email OFF for noisy/informational categories (the default) still receive ONE
 * weekly summary, keeping them in contact at ~1/50th the email volume.
 *
 * Deliberately bypasses the NotificationService category system — the digest is
 * not a per-category event, has no actor, and respects only the user's
 * weekly_digest_enabled flag (checked by the sender command before dispatch).
 * Sent via direct $user->notify() from SendWeeklyDigest, queued via ShouldQueue.
 */
class WeeklyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications  Unread in-app notifications from the past week.
     */
    public function __construct(
        public Collection $notifications,
    ) {}

    /**
     * Mail-only — the digest exists because these notifications were already
     * delivered in-app; the email is the supplementary summary channel.
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return [MailChannel::class];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('notifications.subject_weekly_digest'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_weekly_digest_intro', ['count' => $this->notifications->count()]));

        // Group by notification type for a scannable summary. Cap at a reasonable
        // number of line items to keep the email compact and email-render cheap.
        $grouped = $this->notifications
            ->groupBy(fn ($n) => $this->shortType($n->type))
            ->map(fn ($items) => $items->count())
            ->sortDesc();

        $shown = 0;
        foreach ($grouped as $type => $count) {
            if ($shown >= 12) {
                $remaining = $this->notifications->count() - $shown;
                $message->line(__('notifications.body_weekly_digest_more', ['count' => $remaining]));
                break;
            }
            $message->line("• {$count}× {$type}");
            $shown += $count;
        }

        return $message
            ->action(__('notifications.action_weekly_digest'), route('notifications.index', ['locale' => $notifiable->preferred_language->value ?? app()->getLocale()]))
            ->line(__('notifications.body_weekly_digest_settings'));
    }

    /**
     * Get a human-readable short label from the notification class name.
     */
    private function shortType(string $fullType): string
    {
        $short = class_basename($fullType);

        // Convert PascalCase to Title Case for readability
        return preg_replace('/(?<!^)([A-Z])/', ' $1', $short) ?: $short;
    }
}
