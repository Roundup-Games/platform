<?php

namespace Tests\Feature\Notifications\Fixtures;

use App\Dto\PushPayload;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Support\HtmlString;

/**
 * Minimal concrete subclass exposing BaseNotification's protected envelope
 * helpers for direct assertion in NotificationEnvelopeTest (M072/S04).
 *
 * Never dispatched: it exists purely as a probe surface, so the envelope
 * contract can be pinned before subclasses convert to the helpers.
 */
class EnvelopeProbeNotification extends BaseNotification
{
    public function probeLocale(User $notifiable): string
    {
        return $this->resolveLocale($notifiable);
    }

    public function probeGreeting(User $notifiable): string
    {
        return $this->greetingLine($notifiable);
    }

    public function probePushPayload(string $title, string $body, string $url, ?string $tag = null): PushPayload
    {
        return $this->pushPayload($title, $body, $url, $tag);
    }

    public function probeDiscordEmbed(string $title, string $url, string $description, ?int $color = null): DiscordWebhookPayload
    {
        return $this->discordEmbed($title, $url, $description, $color);
    }

    public function probeUnsubscribeLine(User $notifiable, string $category): HtmlString
    {
        return $this->unsubscribeLine($notifiable, $category);
    }
}
