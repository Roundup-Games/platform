<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class NewApplication extends BaseNotification
{
    /**
     * @param  User  $applicant  The user who applied
     * @param  Game|Campaign  $entity  The Game or Campaign entity
     * @param  string  $entityType  'game' or 'campaign'
     */
    public function __construct(
        public User $applicant,
        public Game|Campaign $entity,
        public string $entityType,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $actionUrl = $this->resolveManageParticipantsUrl($locale);
        $entityTypeLabel = $this->entityTypeLabel();

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_new_application', [
                'applicant' => $this->applicant->name,
                'entity' => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_new_application', [
                'applicant' => $this->applicant->name,
                'entity_type' => $entityTypeLabel,
                'entity' => $this->entity->name,
            ]))
            ->action(__('notifications.action_new_application'), $this->actionUrlWithMarker($notifiable, $actionUrl))
            ->line($this->unsubscribeLine($notifiable, 'new_application'));

        return $this->withPreheader($mail, 'notifications.preheader_new_application');
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
            'type' => 'new_application',
            'applicant_id' => $this->applicant->id,
            'applicant_name' => $this->applicant->name,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => $this->resolveManageParticipantsUrl($locale),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->applicant;
    }

    /**
     * Burst batch key (D296): the entity's burst — every application to
     * the same game (or campaign) within the window lands in ONE batch
     * for the host, so a cluster of applicants reads as one review ping.
     */
    public function batchKey(User $notifiable): string
    {
        return 'new_application:'.$this->entity->id;
    }

    /**
     * Resolve the manage-participants URL from the entity type and ID.
     */
    protected function resolveManageParticipantsUrl(string $locale): string
    {
        return match ($this->entityType) {
            'campaign' => route('campaigns.manage-participants', ['locale' => $locale, 'id' => $this->entity]),
            default => route('games.manage-participants', ['locale' => $locale, 'id' => $this->entity]),
        };
    }

    /**
     * Get a human-readable entity type label for the current locale.
     */
    protected function entityTypeLabel(): string
    {
        return match ($this->entityType) {
            'campaign' => __('notifications.entity_type_campaign'),
            'team' => __('notifications.entity_type_team'),
            default => __('notifications.entity_type_game'),
        };
    }

    /**
     * Discord opted out for the immediate single-event surface (D130: this
     * type has no push surface, so it has no Discord surface either). The
     * batched path is separate: a burst of two or more events flushes as
     * BatchedNotification, which carries its own push + Discord voice —
     * deliberately, per D286's per-channel batch rendering.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        return null;
    }
}
