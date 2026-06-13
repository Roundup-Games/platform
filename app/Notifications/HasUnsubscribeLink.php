<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;

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
    protected function unsubscribeUrl(User $notifiable, string $category): string
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return URL::signedRoute('notifications.unsubscribe', [
            'locale' => $locale,
            'user' => $notifiable->id,
            'category' => $category,
        ]);
    }

    /**
     * Generate a styled unsubscribe line suitable for markdown emails.
     */
    protected function unsubscribeLine(User $notifiable, string $category): HtmlString
    {
        $url = $this->unsubscribeUrl($notifiable, $category);
        $label = __('notifications.email_unsubscribe');

        return new HtmlString('<small><a href="'.$url.'" style="color: #8a846e;">'.$label.'</a></small>');
    }
}
