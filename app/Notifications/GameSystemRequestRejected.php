<?php

namespace App\Notifications;

use App\Dto\PushPayload;

use App\Models\User;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class GameSystemRequestRejected extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  Ticket  $ticket  The Escalated ticket representing the rejected game system request
     */
    public function __construct(
        public Ticket $ticket,
    ) {}

    /**
     * Get the game system name from the ticket subject.
     */
    protected function getGameSystemName(): string
    {
        $subject = $this->ticket->subject ?? '';

        if (str_starts_with($subject, 'Game System Request: ')) {
            return trim(Str::after($subject, 'Game System Request: '));
        }

        return trim($subject);
    }

    /**
     * Get the rejection reason from ticket metadata.
     */
    protected function getRejectionReason(): ?string
    {
        return $this->ticket->metadata['rejection_reason'] ?? null;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->getGameSystemName();
        $rejectionReason = $this->getRejectionReason();

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_game_system_request_rejected'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_game_system_request_rejected', [
                'name' => $name,
            ]));

        if ($rejectionReason) {
            $mail->line(__('notifications.body_rejection_reason', [
                'reason' => $rejectionReason,
            ]));
        }

        $mail->line($this->unsubscribeLine($notifiable, 'game_system_request'));

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $name = $this->getGameSystemName();
        $rejectionReason = $this->getRejectionReason();

        $data = [
            'type' => 'game_system_request_rejected',
            'ticket_id' => $this->ticket->id,
            'game_system_name' => $name,
            'rejection_reason' => $rejectionReason,
            'message' => __('notifications.body_game_system_request_rejected', [
                'name' => $name,
            ]),
        ];

        if ($rejectionReason) {
            $data['message'] .= ' ' . __('notifications.body_rejection_reason', [
                'reason' => $rejectionReason,
            ]);
        }

        return $data;
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
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(object $notifiable): ?PushPayload
    {
        return null;
    }
}
