<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class DisputeResolved extends BaseNotification
{
    /**
     * @param  Game  $game  The game the dispute was about
     * @param  string  $resolution  The dispute resolution: 'resolved_favor' or 'upheld'
     */
    public function __construct(
        public Game $game,
        public string $resolution,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        // Recipient-zone date (R089): format_date converts the stored UTC
        // instant to the notifiable's wall clock — the calendar day differs
        // across zones.
        $date = format_date($this->game->date_time, 'date', $notifiable->timezone ?? community_timezone());
        $resolved = $this->resolution === 'resolved_favor';

        $subject = $resolved
            ? __('notifications.subject_dispute_resolved_favor', ['game' => $this->game->name])
            : __('notifications.subject_dispute_upheld', ['game' => $this->game->name]);

        $body = $resolved
            ? __('notifications.body_dispute_resolved_favor', ['game' => $this->game->name, 'date' => $date])
            : __('notifications.body_dispute_upheld', ['game' => $this->game->name, 'date' => $date]);

        return (new MailMessage)
            ->subject($subject)
            ->greeting($this->greetingLine($notifiable))
            ->line($body)
            ->action(__('notifications.action_view_game'), route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]))
            ->line($this->unsubscribeLine($notifiable, 'dispute_resolved'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'dispute_resolved',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'resolution' => $this->resolution,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'action_url' => route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Dispute resolution is system-initiated, so no actor to block.
     */
    public function getActor(): ?User
    {
        return null;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $copy = $this->resolutionCopy();

        return $this->pushPayload(
            $copy['title'],
            $copy['body'],
            route('games.show', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->game->id,
            ]),
            "dispute-resolved-{$this->game->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $copy = $this->resolutionCopy();

        return $this->discordEmbed(
            $copy['title'],
            route('games.show', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->game->id,
            ]),
            $copy['body'],
        );
    }

    /**
     * Title/body pair for the resolution branch, shared by toPush() and
     * toDiscord() so both channels speak the identical copy (mail keeps
     * its own subject/body keys).
     *
     * @return array{title: string, body: string}
     */
    private function resolutionCopy(): array
    {
        $resolved = $this->resolution === 'resolved_favor';

        return [
            'title' => $resolved
                ? __('notifications.category_dispute_resolved')
                : __('notifications.push_title_dispute_upheld'),
            'body' => $resolved
                ? __('notifications.push_body_dispute_resolved_favor', ['game' => $this->game->name])
                : __('notifications.push_body_dispute_upheld', ['game' => $this->game->name]),
        ];
    }
}
