<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class BulletinPosted extends BaseNotification
{
    /**
     * @param  Game  $game  The game the bulletin was posted to
     * @param  User  $host  The host who posted the bulletin
     * @param  GameBulletin  $bulletin  The bulletin that was posted
     */
    public function __construct(
        public Game $game,
        public User $host,
        public GameBulletin $bulletin,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.subject_bulletin_posted', [
                'game' => $this->game->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_bulletin_posted', [
                'host' => $this->host->name,
                'game' => $this->game->name,
            ]))
            ->line(__('notifications.body_bulletin_content', [
                'content' => Str::limit($this->bulletin->content, 100),
            ]))
            ->action(__('notifications.action_view_game'), route('games.show', ['locale' => $locale, 'id' => $this->game]))
            ->line($this->unsubscribeLine($notifiable, 'session_content'));
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
            'type' => 'bulletin_posted',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'actor_id' => $this->host->id,
            'bulletin_id' => $this->bulletin->id,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): ?User
    {
        return $this->host;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        return $this->pushPayload(
            __('notifications.push_title_bulletin_posted'),
            __('notifications.push_body_bulletin_posted', [
                'host' => $this->host->name,
                'game' => $this->game->name,
            ]),
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            "bulletin-{$this->bulletin->id}",
        );
    }

    /**
     * Get the Discord DM representation.
     *
     * Delivered as a rich embed card so the DM carries the event context
     * (who posted, for which session) and the bulletin content snippet —
     * not just the bare session name + link the channel's auto-derive
     * fallback produces. Mirrors the mail/push content via the same lang
     * keys so the three channels stay in lockstep.
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $url = route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]);

        return $this->discordEmbed(
            __('notifications.subject_bulletin_posted', [
                'game' => $this->game->name,
            ]),
            $url,
            __('notifications.body_bulletin_posted', [
                'host' => $this->host->name,
                'game' => $this->game->name,
            ])
                ."\n\n"
                .__('notifications.body_bulletin_content', [
                    'content' => Str::limit($this->bulletin->content, 280),
                ]),
        );
    }
}
