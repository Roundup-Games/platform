<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class EntityInvitation extends BaseNotification
{
    use RoutesGameOrCampaign;

    /**
     * @param  Game|Campaign  $entity  The game or campaign the user is invited to
     * @param  User  $inviter  The user who sent the invitation
     */
    public function __construct(
        public Game|Campaign $entity,
        public User $inviter,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $type = $this->getEntityType();
        $actionUrl = route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]);

        return (new MailMessage)
            ->subject(__("notifications.subject_{$type}_invitation", [
                'inviter' => $this->inviter->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__("notifications.body_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ]))
            ->action(__("notifications.action_view_{$type}"), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, "{$type}_invitation"));
    }

    /**
     * Get the array representation of the notification.
     *
     * Preserves backward-compatible keys: {entity_type}_id / {entity_type}_name
     * for existing database records and frontend code.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);
        $type = $this->getEntityType();

        return [
            'type' => "{$type}_invitation",
            "{$type}_id" => $this->entity->id,
            "{$type}_name" => $this->entity->name,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'action_url' => route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->inviter;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $type = $this->getEntityType();

        return $this->pushPayload(
            __("notifications.category_{$type}_invitation"),
            __("notifications.push_body_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ]),
            route("{$type}s.show", ['locale' => $this->resolveLocale($notifiable), 'id' => $this->entity]),
            "{$type}-invitation-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $type = $this->getEntityType();

        return $this->discordEmbed(
            __("notifications.category_{$type}_invitation"),
            route("{$type}s.show", ['locale' => $this->resolveLocale($notifiable), 'id' => $this->entity]),
            __("notifications.push_body_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ]),
        );
    }
}
