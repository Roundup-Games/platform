<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Review;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class ReviewReported extends BaseNotification
{
    /**
     * @param  Review  $review  The review that was reported
     * @param  User  $reporter  The user who reported it
     */
    public function __construct(
        public Review $review,
        public User $reporter,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.subject_review_reported'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_review_reported', [
                'reporter' => $this->reporter->name,
                'reason' => $this->review->report_reason,
            ]))
            ->line(__('notifications.body_review_rating', ['rating' => $this->review->rating]))
            ->line(__('notifications.body_review_content', ['body' => Str::limit($this->review->body ?? '', 200)]));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => 'review_reported',
            'review_id' => $this->review->id,
            'reporter_id' => $this->reporter->id,
            'reporter_name' => $this->reporter->name,
            'reason' => $this->review->report_reason,
            'rating' => $this->review->rating,
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->reporter;
    }

    /**
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        return null;
    }
}
