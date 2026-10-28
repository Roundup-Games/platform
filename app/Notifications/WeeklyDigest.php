<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

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
 * Sent via direct $user->notify() from SendWeeklyDigest, queued via ShouldQueue
 * (inherited from BaseNotification, which also supplies the shared envelope:
 * resolveLocale/greetingLine). Its opt-out URL is a distinct digest route with
 * no category, so it does NOT reuse the base's unsubscribeUrl/unsubscribeLine.
 */
class WeeklyDigest extends BaseNotification
{
    /**
     * @param  Collection<int, DatabaseNotification>  $notifications  Unread in-app notifications from the past week.
     */
    public function __construct(
        public Collection $notifications,
    ) {}

    /**
     * Mail-only — the digest exists because these notifications were already
     * delivered in-app; the email is the supplementary summary channel.
     * Overrides the base's default support set (database + mail + discord):
     * the digest deliberately has no in-app bell row and no DM surface.
     *
     * @return array<int, string>
     */
    protected function supportedChannels(): array
    {
        return [MailChannel::class];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        $message = (new MailMessage)
            ->subject(__('notifications.subject_weekly_digest'))
            ->greeting($this->greetingLine($notifiable))
            ->line(trans_choice('notifications.body_weekly_digest_intro', $this->notifications->count(), ['count' => $this->notifications->count()]));

        // Group by notification type for a scannable summary. Cap at a reasonable
        // number of line items to keep the email compact and email-render cheap.
        $grouped = $this->notifications
            ->groupBy(fn ($n) => $this->shortType($n, $locale))
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
            ->action(__('notifications.action_weekly_digest'), route('notifications.index', ['locale' => $locale]))
            ->line(__('notifications.body_weekly_digest_unsubscribe'))
            ->action(__('notifications.action_unsubscribe_digest'), $this->digestUnsubscribeUrl($notifiable))
            ->line(__('notifications.body_weekly_digest_settings'));
    }

    /**
     * Discord opted out (D130): mail-only by design — the digest summarizes
     * in-app notifications, so a DM would duplicate the channel it exists
     * to supplement.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        return null;
    }

    /**
     * Signed one-click opt-out URL for the weekly digest (a distinct route
     * from the base's per-category unsubscribe — the digest has no category).
     */
    private function digestUnsubscribeUrl(User $notifiable): string
    {
        return URL::signedRoute('notifications.unsubscribe-digest', [
            'locale' => $this->resolveLocale($notifiable),
            'user' => $notifiable->id,
        ]);
    }

    /**
     * Resolve a localized digest label for a notification row.
     *
     * Prefers the per-notification data.type discriminator (e.g. 'game_invitation'
     * vs 'campaign_invitation' from EntityInvitation) — the value the
     * label_digest_* translation keys are actually keyed by — falling back to
     * the snake_cased class basename when data.type is absent, and finally to a
     * Title-Case basename so an unmapped/legacy type never breaks the digest.
     */
    private function shortType(DatabaseNotification $notification, string $locale): string
    {
        $classBase = class_basename((string) $notification->type);

        $data = is_array($notification->data ?? null) ? $notification->data : [];
        $key = is_string($data['type'] ?? null) && $data['type'] !== ''
            ? (string) $data['type']
            : strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $classBase));

        // Flat lang-file convention: label_digest_{type} (i18n:check enforces
        // flat prefixed keys — no nested digest_labels array). The
        // concatenation lives INSIDE __() so i18n:dead-strings' dynamic-prefix
        // detector registers 'label_digest_' as a live prefix.
        $expected = 'notifications.label_digest_'.$key;
        $label = __('notifications.label_digest_'.$key, [], $locale);

        // __('...') returns the key itself when absent; fall back to a
        // Title-Case class basename so the digest still renders.
        if ($label !== $expected) {
            return $label;
        }

        return preg_replace('/(?<!^)([A-Z])/', ' $1', $classBase) ?: $classBase;
    }
}
