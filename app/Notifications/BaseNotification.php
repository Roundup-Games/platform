<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushChannel;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;

/**
 * Base notification for all application notifications.
 *
 * All notifications implement ShouldQueue so they are dispatched to the
 * queue via NotificationService. In the test environment (QUEUE_CONNECTION=sync)
 * they run synchronously, keeping test assertions straightforward.
 *
 * Channel resolution has two layers:
 *   1. supportedChannels() — what this notification TYPE can use (e.g. a
 *      database-only notification returns just DatabaseChannel).
 *   2. resolvedChannels — the recipient-specific subset (supported ∩ enabled
 *      in their notification_settings), set by NotificationService::send()
 *      before dispatch.
 *
 * via() returns resolvedChannels when set, falling back to supportedChannels().
 * Laravel's queued dispatcher reads via() at enqueue time to decide which
 * channels get jobs, so returning the resolved subset propagates the user's
 * preferences (e.g. mail disabled) across the queue boundary.
 *
 * Envelope helpers (M072/S04): the shared notification envelope — locale
 * resolution, greeting, default actor, the push payload wrap (icon/tag), the
 * Discord embed wrap (blurple color), and the signed unsubscribe line — is
 * concentrated in this class so a wording or branding tweak lands here
 * instead of 40+ subclass files. The base deliberately does NOT define
 * toDiscord()/toPush(): every subclass keeps an explicit per-channel method
 * (payload = DM/push; null = deliberate opt-out, D130/D247) — a channel's
 * voice must always be written, not derived.
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Discord blurple accent shared by every notification embed (canonical home: DiscordWebhookPayload::BLURPLE). */
    public const DISCORD_EMBED_COLOR = DiscordWebhookPayload::BLURPLE;

    /** PWA icon shared by every push payload. */
    public const PUSH_ICON = '/icons/pwa-192x192.png';

    /**
     * Channels resolved by NotificationService for a specific recipient —
     * the intersection of what this notification supports and what the
     * recipient has enabled. Null until the service sets it.
     *
     * @var array<int, string>|null
     */
    protected ?array $resolvedChannels = null;

    /**
     * Channels this notification type supports by default. Subclasses
     * override to declare a narrower set (e.g. database-only notifications).
     *
     * Push is auto-detected: a notification that implements toPush() declares
     * push intent, so PushChannel is included automatically. This keeps the
     * 30+ toPush() implementations reachable without each subclass repeating
     * the channel declaration.
     *
     * Discord (D118) is unconditionally supported, but its payload is
     * NEVER synthesized: every notification defines an explicit
     * toDiscord() (payload = DM; null = deliberate opt-out, D130). A
     * class missing toDiscord() is skipped with a warning at send time
     * (D247) — the platform's DM voice must always be written, not
     * derived. DiscordChannel is a graceful no-op when unlinked.
     *
     * @return array<int, string>
     */
    protected function supportedChannels(): array
    {
        $channels = [DatabaseChannel::class, MailChannel::class, DiscordChannel::class];

        if (method_exists($this, 'toPush')) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }

    /**
     * Channels Laravel will dispatch to. Returns the recipient-specific
     * resolved set when set by NotificationService, otherwise the type's
     * supported channels (fallback for direct notify() calls / tests).
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $this->resolvedChannels ?? $this->supportedChannels();
    }

    /**
     * Restrict dispatch to the given channels. Called by
     * NotificationService::send() after intersecting the recipient's enabled
     * channels with this notification's supported channels.
     *
     * @param  array<int, string>  $channels
     */
    public function setResolvedChannels(array $channels): static
    {
        $this->resolvedChannels = $channels;

        return $this;
    }

    // ── Envelope helpers ───────────────────────────────────────────────
    // The shared envelope every channel copy/payload is wrapped in. Use
    // these instead of re-stating the literal locale/greeting/icon/color
    // lines per subclass.

    /**
     * Resolve the recipient's locale for URLs and copy: their explicit
     * preferred_language when set, otherwise the application default.
     */
    protected function resolveLocale(User $notifiable): string
    {
        return $notifiable->preferred_language->value ?? app()->getLocale();
    }

    /**
     * Localized mail greeting addressing the recipient by name, falling
     * back to their email address when no name is set.
     */
    protected function greetingLine(User $notifiable): string
    {
        return __('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]);
    }

    /**
     * Default actor for block-list checking by NotificationService.
     *
     * The default is null (system notification, nothing to check against a
     * block list). Subclasses with a triggering user override this to return
     * them, so blocked-actor filtering applies.
     */
    public function getActor(): ?User
    {
        return null;
    }

    /**
     * Push payload wrapped in the shared envelope: platform PWA icon plus
     * the caller's title/body/url/tag.
     */
    protected function pushPayload(string $title, string $body, string $url, ?string $tag = null): PushPayload
    {
        return new PushPayload(
            title: $title,
            body: $body,
            icon: self::PUSH_ICON,
            url: $url,
            tag: $tag,
        );
    }

    /**
     * Discord DM wrapped in the shared envelope: one blurple embed with the
     * caller's title/url/description (D130: Discord mirrors push). Classes
     * with a semantic accent (event lifecycle green/red) pass an explicit
     * $color; everything else inherits the blurple default.
     */
    protected function discordEmbed(string $title, string $url, string $description, ?int $color = null): DiscordWebhookPayload
    {
        return DiscordWebhookPayload::embed([
            'title' => $title,
            'url' => $url,
            'description' => $description,
            'color' => $color ?? self::DISCORD_EMBED_COLOR,
        ]);
    }

    /**
     * Generate a signed unsubscribe URL for the given user and category.
     */
    protected function unsubscribeUrl(User $notifiable, string $category): string
    {
        return URL::signedRoute('notifications.unsubscribe', [
            'locale' => $this->resolveLocale($notifiable),
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
