<?php

namespace App\Notifications;

use Illuminate\Support\Facades\URL;

/**
 * Trait for generating signed unsubscribe URLs in notification emails.
 *
 * Usage in toMail():
 *   return (new MailMessage)
 *       ->subject(...)
 *       ->greeting(...)
 *       ->line(...)
 *       ->action(...)
 *       ->line($this->unsubscribeLine($notifiable, 'game_invitation'));
 */
trait HasUnsubscribeLink
{
    /**
     * Generate a signed unsubscribe URL for the given user and category.
     */
    protected function unsubscribeUrl(object $notifiable, string $category): string
    {
        return URL::signedRoute('notifications.unsubscribe', [
            'locale' => app()->getLocale(),
            'user' => $notifiable->id,
            'category' => $category,
        ]);
    }

    /**
     * Generate a styled unsubscribe line suitable for markdown emails.
     */
    protected function unsubscribeLine(object $notifiable, string $category): string
    {
        $url = $this->unsubscribeUrl($notifiable, $category);
        $label = __('notifications.email_unsubscribe');

        return '<small><a href="' . $url . '" style="color: #8a846e;">' . $label . '</a></small>';
    }
}
