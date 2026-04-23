<?php

namespace App\Notifications;

use App\Models\Review;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewReported extends Notification
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
    public function toDatabase(object $notifiable): array
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
}
