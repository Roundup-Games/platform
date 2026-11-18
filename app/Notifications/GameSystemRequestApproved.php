<?php

namespace App\Notifications;

use App\Models\GameSystem;
use App\Models\User;
use App\Services\Discord\DiscordPalette;
use App\Services\Discord\DiscordWebhookPayload;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class GameSystemRequestApproved extends BaseNotification
{
    /**
     * @param  Ticket  $ticket  The Escalated ticket representing the game system request
     * @param  GameSystem  $gameSystem  The newly created game system
     */
    public function __construct(
        public Ticket $ticket,
        public GameSystem $gameSystem,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $createGameUrl = route('games.create', ['locale' => $locale]).'?game_system_id='.$this->gameSystem->id;

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_game_system_request_approved', [
                'name' => $this->gameSystem->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_game_system_request_approved', [
                'name' => $this->gameSystem->name,
            ]))
            ->action(__('notifications.action_create_game'), $this->actionUrlWithMarker($notifiable, $createGameUrl))
            ->line($this->unsubscribeLine($notifiable, 'game_system_request'));

        return $this->withPreheader($mail, 'notifications.preheader_game_system_request_approved');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => 'game_system_request_approved',
            'ticket_id' => $this->ticket->id,
            'ticket_reference' => $this->ticket->reference,
            'game_system_id' => $this->gameSystem->id,
            'game_system_name' => $this->gameSystem->name,
            'game_system_slug' => $this->gameSystem->slug,
            'message' => __('notifications.body_game_system_request_approved', [
                'name' => $this->gameSystem->name,
            ]),
            // Link to the support ticket so the requester can review the request
            // and its resolution (M054/S03). The game system page is still
            // reachable via the in-app catalog.
            'action_url' => route('escalated.customer.tickets.show', $this->ticket),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns null since this is a system notification, not triggered by a user.
     */
    public function getActor(): ?User
    {
        return null;
    }

    /**
     * Discord opted out (D130): this type has no push surface, so it has no Discord surface either.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        return null;
    }

    /**
     * D280 semantic layer: action-needed amber — the requester should review the approved outcome (toDiscord is a null opt-out today; the mapping is declared so a future DM inherits it).
     */
    protected function semanticColor(): int
    {
        return DiscordPalette::ACTION_NEEDED;
    }
}
