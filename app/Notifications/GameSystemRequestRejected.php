<?php

namespace App\Notifications;

use App\Dto\PushPayload;

use App\Models\GameSystemRequest;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GameSystemRequestRejected extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  GameSystemRequest  $request  The rejected game system request
     */
    public function __construct(
        public GameSystemRequest $request,
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
        $mail = (new MailMessage)
            ->subject(__('notifications.subject_game_system_request_rejected'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_game_system_request_rejected', [
                'name' => $this->request->name,
            ]));

        if ($this->request->rejection_reason) {
            $mail->line(__('notifications.body_rejection_reason', [
                'reason' => $this->request->rejection_reason,
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
        $data = [
            'type' => 'game_system_request_rejected',
            'request_id' => $this->request->id,
            'game_system_name' => $this->request->name,
            'rejection_reason' => $this->request->rejection_reason,
            'message' => __('notifications.body_game_system_request_rejected', [
                'name' => $this->request->name,
            ]),
        ];

        if ($this->request->rejection_reason) {
            $data['message'] .= ' ' . __('notifications.body_rejection_reason', [
                'reason' => $this->request->rejection_reason,
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
