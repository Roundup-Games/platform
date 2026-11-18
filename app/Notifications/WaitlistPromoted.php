<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

class WaitlistPromoted extends BaseNotification
{
    use RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
        /**
         * Deploy-boundary tolerance (M081 review): queued notifications
         * unserialize inside SendQueuedNotifications, and jobs serialized
         * by the pre-M081 code carry the deadline as a STRING (the old
         * `public string $confirmationDeadline = ''`). A Carbon-only type
         * makes those in-flight payloads fail unserialization — the whole
         * notification (database + mail + push + discord) is lost. Accept
         * both shapes and normalize at read time; narrow to Carbon once no
         * pre-M081 job can remain in the queue.
         *
         * @var Carbon|string|null
         */
        public Carbon|string|null $confirmationDeadline = null,
    ) {}

    /**
     * Recipient-facing deadline label (R089): the instant converts to the
     * recipient's wall clock and renders in their locale — every channel
     * shows the same label. Callers pass the Carbon; pre-formatted strings
     * froze the queue's English locale into German mail (M081 follow-up).
     * Legacy string values (queued by pre-M081 code) parse through here.
     */
    private function deadlineLabel(User $notifiable): string
    {
        $deadline = is_string($this->confirmationDeadline)
            ? ($this->confirmationDeadline === '' ? null : Carbon::parse($this->confirmationDeadline))
            : $this->confirmationDeadline;

        return $deadline !== null
            ? format_date($deadline, 'datetime', $notifiable->timezone ?? community_timezone())
            : '';
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $url = $this->getEntityRoute($locale);

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_waitlist_promoted', [
                'game' => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_waitlist_promoted', [
                'game' => $this->entity->name,
                'deadline' => $this->deadlineLabel($notifiable),
            ], $locale))
            ->action(__('notifications.action_waitlist_promoted'), $this->actionUrlWithMarker($notifiable, $url))
            ->line($this->unsubscribeLine($notifiable, 'waitlist_promoted'));

        // iTIP METHOD:REQUEST (D287): the confirmed seat carries the
        // calendar invitation for the entity.
        $this->attachIcsRequest($mail, $this->entity, $notifiable, $url);

        return $this->withPreheader($mail, 'notifications.preheader_waitlist_promoted', [
            'game' => $this->entity->name,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'waitlist_promoted',
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'confirmation_deadline' => $this->deadlineLabel($notifiable),
            'action_url' => $this->getEntityRoute($locale),
        ];
    }

    /**
     * Deadline-critical push (M081/S05, D286 rider): carries a Confirm-spot
     * action button, the event timestamp (the promotion moment), and
     * renotify + the stable per-(type, entity) tag — a second promotion
     * push for the same entity replaces the previous notification and
     * re-alerts on supporting clients instead of stacking silently.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $url = $this->getEntityRoute($this->resolveLocale($notifiable));

        return $this->pushPayload(
            __('notifications.push_title_waitlist_promoted'),
            __('notifications.push_body_waitlist_promoted', [
                'game' => $this->entity->name,
                'deadline' => $this->deadlineLabel($notifiable),
            ]),
            $this->actionUrlWithMarker($notifiable, $url),
            "waitlist-promoted-{$this->getEntityType()}-{$this->entity->id}",
            actions: [
                ['label' => __('notifications.push_action_confirm_spot'), 'url' => $this->actionUrlWithMarker($notifiable, $url)],
            ],
            timestamp: now()->getTimestamp(),
            badge: self::PUSH_BADGE,
            renotify: true,
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.push_title_waitlist_promoted'),
            $this->getEntityRoute($this->resolveLocale($notifiable)),
            __('notifications.push_body_waitlist_promoted', [
                'game' => $this->entity->name,
                'deadline' => $this->deadlineLabel($notifiable),
            ]),
        );
    }
}
