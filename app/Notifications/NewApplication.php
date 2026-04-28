<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewApplication extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  User  $applicant  The user who applied
     * @param  \Illuminate\Database\Eloquent\Model  $entity  The Game or Campaign entity
     * @param  string  $entityType  'game' or 'campaign'
     */
    public function __construct(
        public User $applicant,
        public $entity,
        public string $entityType,
    ) {}

    /**
     * Get the notification's delivery channels.
     * When dispatched via NotificationService, channels are resolved
     * from user preferences; this serves as a fallback default.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $actionUrl = $this->resolveManageParticipantsUrl($locale);
        $entityTypeLabel = $this->entityTypeLabel();

        return (new MailMessage)
            ->subject(__('notifications.subject_new_application', [
                'applicant' => $this->applicant->name,
                'entity' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_new_application', [
                'applicant' => $this->applicant->name,
                'entity_type' => $entityTypeLabel,
                'entity' => $this->entity->name,
            ]))
            ->action(__('notifications.action_new_application'), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, 'new_application'));
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
     * Resolve the manage-participants URL from the entity type and ID.
     */
    protected function resolveManageParticipantsUrl(string $locale): string
    {
        return match ($this->entityType) {
            'campaign' => route('campaigns.manage-participants', ['locale' => $locale, 'id' => $this->entity->id]),
            default => route('games.manage-participants', ['locale' => $locale, 'id' => $this->entity->id]),
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
