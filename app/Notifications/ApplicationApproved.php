<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class ApplicationApproved extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $entity  The Game or Campaign entity
     * @param  string  $entityType  'game' or 'campaign'
     * @param  User  $approver  The user who approved the application
     */
    public function __construct(
        public $entity,
        public string $entityType,
        public User $approver,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $actionUrl = $this->resolveEntityUrl($locale);
        $entityTypeLabel = $this->entityTypeLabel();

        return (new MailMessage)
            ->subject(__('notifications.subject_application_approved', [
                'entity' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_application_approved', [
                'entity' => $this->entity->name,
            ]))
            ->action(__('notifications.action_application_approved', ['entity_type' => ucfirst($entityTypeLabel)]), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, 'application_approved'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'application_approved',
            'entity_type' => $this->entityType,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'approver_id' => $this->approver->id,
            'approver_name' => $this->approver->name,
            'action_url' => $this->resolveEntityUrl($locale),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->approver;
    }

    /**
     * Resolve the entity detail URL from the entity type and ID.
     */
    protected function resolveEntityUrl(string $locale): string
    {
        return match ($this->entityType) {
            'campaign' => route('campaigns.show', ['locale' => $locale, 'id' => $this->entity->id]),
            default => route('games.show', ['locale' => $locale, 'id' => $this->entity->id]),
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
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(object $notifiable): ?PushPayload
    {
        return null;
    }
}
