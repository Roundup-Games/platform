<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Calendar\IcsBuilder;
use App\Services\Discord\DiscordWebhookPayload;
use App\Support\ChangedFieldLabels;
use Illuminate\Notifications\Messages\MailMessage;

class EntityUpdated extends BaseNotification
{
    use RoutesGameOrCampaign;

    /**
     * @param  Game|Campaign  $entity  The game or campaign that was updated
     * @param  list<string>  $changedColumns  Machine-readable changed COLUMN names (e.g. from array_keys($changes)).
     *                                        Human labels are resolved per RECIPIENT locale at render time through
     *                                        ChangedFieldLabels — never pass display strings or a raw column can
     *                                        reach the mail ("Changes: location_id", M081 follow-up).
     *                                        Calendars only re-issue for calendar-relevant columns — an empty list
     *                                        attaches no ICS (D287).
     */
    public function __construct(
        public Game|Campaign $entity,
        public array $changedColumns = [],
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $type = $this->getEntityType();
        $fields = implode(', ', ChangedFieldLabels::forLocale($this->changedColumns, $locale));
        $actionUrl = route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]);

        $mail = (new MailMessage)
            ->subject(__("notifications.subject_{$type}_updated", [
                $type => $this->entity->name,
                'summary' => $fields,
            ], $locale))
            ->greeting($this->greetingLine($notifiable))
            ->line(__("notifications.body_{$type}_updated", [
                $type => $this->entity->name,
                'fields' => $fields,
            ], $locale));

        // A venue move is the one change whose NEW value the recipient must
        // know without clicking — name the venue, not the column.
        $venue = $this->changedVenueLabel();
        if ($venue !== null) {
            $mail->line(__('notifications.body_venue_now', ['venue' => $venue], $locale));
        }

        $mail->action(__("notifications.action_view_{$type}", [], $locale), $this->actionUrlWithMarker($notifiable, $actionUrl))
            ->line($this->unsubscribeLine($notifiable, "{$type}_updated"));

        // iTIP same-UID update REQUEST (D287): only calendar-relevant
        // columns re-issue the invite — a title-only edit must not prompt
        // recipients' calendars again.
        if (IcsBuilder::columnsAffectCalendar($this->changedColumns)) {
            $this->attachIcsRequest($mail, $this->entity, $notifiable, $actionUrl);
        }

        return $this->withPreheader($mail, "notifications.preheader_{$type}_updated");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);
        $type = $this->getEntityType();

        return [
            'type' => "{$type}_updated",
            'entity_type' => $type,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'changed_fields' => ChangedFieldLabels::forLocale($this->changedColumns, $locale),
            'action_url' => route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]),
        ];
    }

    /**
     * The entity's current venue as a human string, when this update moved
     * it: the linked venue's name, else the freeform location details. Null
     * when the change did not touch the venue or no label survives.
     */
    private function changedVenueLabel(): ?string
    {
        if (array_intersect($this->changedColumns, ['location_id', 'location']) === []) {
            return null;
        }

        $venue = $this->entity->linkedLocation?->name;

        if (is_string($venue) && $venue !== '') {
            return $venue;
        }

        $details = $this->entity->location['details'] ?? null;

        return is_string($details) && $details !== '' ? $details : null;
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): ?User
    {
        return $this->entity->owner;
    }

    /**
     * Discord opted out (D130): this type has no push surface, so it has no Discord surface either.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        return null;
    }
}
