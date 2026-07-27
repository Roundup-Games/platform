<?php

namespace App\Notifications\Channels;

use App\Exceptions\DiscordApiException;
use App\Models\User;
use App\Services\Discord\DiscordWebhookClient;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Laravel notification channel that delivers a Discord DM (D118).
 *
 * Mirrors {@see PushChannel}: a {@see send()} that degrades gracefully and
 * never throws, so a Discord failure can never suppress the parallel
 * in-app/mail/push channels dispatched in the same NotificationService::send()
 * call (MEM912 — parallel dispatch, no suppression).
 *
 * Delivery is two-step Discord bot REST:
 *   1. {@see DiscordWebhookClient::createDmChannel()} opens a DM channel with
 *      the recipient's snowflake (LinkedAccount::provider_user_id, MEM875),
 *      returning the DM channel id.
 *   2. {@see DiscordWebhookClient::postMessage()} posts the derived payload.
 *
 * Payload is auto-derived from the notification's toDatabase() array — the
 * same in-app data every notification already produces — so every
 * notification type gains a Discord surface without per-class toDiscord()
 * implementations. A notification MAY override by defining toDiscord() (return
 * null to opt out, return a DiscordWebhookPayload to use it verbatim).
 *
 * Every no-op path emits a structured notification.discord_dm_skipped log with
 * a typed reason; success emits notification.discord_dm_sent. DiscordApiException
 * (notably the shared-guild DM-creation 403, research §10) is logged at warning
 * level so it is diagnosable without surfacing as a dispatch failure.
 */
class DiscordChannel
{
    public function __construct(
        private ?DiscordWebhookClient $client = null,
    ) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        $notificationType = get_class($notification);
        $category = $this->resolveCategory($notification, $notifiable);

        // Graceful degradation when the Discord client is unavailable
        // (parallel to PushChannel's null-WebPush guard). Treats an absent
        // client as "not configured" — every no-op path is logged below.
        if ($this->client === null) {
            $this->skipped($notifiable, $notificationType, $category, 'bot_token_missing');

            return;
        }

        // Master gate (MEM918): services.discord.publishing_enabled keeps the
        // entire Discord output path off until ops enable it. Default false.
        if (! (bool) config('services.discord.publishing_enabled', false)) {
            $this->skipped($notifiable, $notificationType, $category, 'publishing_disabled');

            return;
        }

        // Bot-token gate — a missing token means no DM is possible.
        $botToken = config('services.discord.bot_token');
        if (! is_string($botToken) || $botToken === '') {
            $this->skipped($notifiable, $notificationType, $category, 'bot_token_missing');

            return;
        }

        // Resolve the recipient's Discord snowflake. No linked account (or a
        // linked account missing its snowflake) is a graceful no-op.
        if (! $notifiable instanceof User) {
            return;
        }

        $linkedAccount = $notifiable->discordLinkedAccount();
        $snowflake = $linkedAccount?->provider_user_id;
        if (! is_string($snowflake) || $snowflake === '') {
            $this->skipped($notifiable, $notificationType, $category, 'no_linked_account');

            return;
        }

        // Build the payload — honour an optional toDiscord() override (null
        // opts out), otherwise auto-derive from toDatabase().
        $payload = $this->resolvePayload($notification, $notifiable);
        if ($payload === null) {
            $this->skipped($notifiable, $notificationType, $category, 'dm_opt_out');

            return;
        }

        // Step 1 — open the DM channel. The shared-guild 403 (40007) lands
        // here as a non-retryable DiscordApiException and MUST be a graceful
        // no-op, never a thrown dispatch error.
        try {
            $dmChannelId = $this->client->createDmChannel($snowflake);
        } catch (DiscordApiException $e) {
            Log::warning('notification.discord_dm_api_error', [
                'user_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $category,
                'step' => 'create_dm_channel',
                'error' => $e->getMessage(),
            ]);
            $this->skipped($notifiable, $notificationType, $category, 'dm_channel_failed');

            return;
        }

        // Step 2 — post the derived DM message.
        try {
            $this->client->postMessage($dmChannelId, $payload);
        } catch (DiscordApiException $e) {
            Log::warning('notification.discord_dm_api_error', [
                'user_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $category,
                'step' => 'post_message',
                'dm_channel_id' => $dmChannelId,
                'error' => $e->getMessage(),
            ]);
            $this->skipped($notifiable, $notificationType, $category, 'send_failed');

            return;
        }

        Log::info('notification.discord_dm_sent', [
            'user_id' => $notifiable->id,
            'notification_type' => $notificationType,
            'category' => $category,
            'dm_channel_id' => $dmChannelId,
        ]);
    }

    /**
     * Resolve the DM payload. An explicit toDiscord() override wins (null =
     * opt-out); otherwise the payload is auto-derived from toDatabase().
     */
    private function resolvePayload(Notification $notification, User $notifiable): ?DiscordWebhookPayload
    {
        if (method_exists($notification, 'toDiscord')) {
            return $notification->toDiscord($notifiable);
        }

        if (! method_exists($notification, 'toDatabase')) {
            return null;
        }

        $data = $notification->toDatabase($notifiable);
        if (! is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $this->derivePayload($data);
    }

    /**
     * Auto-derive a minimal DiscordWebhookPayload from a notification's
     * toDatabase() array. Reads the common keys (entity_name, action_url)
     * shared across notification types (MEM093), degrading gracefully when
     * keys are absent so heterogeneous shapes never crash delivery.
     *
     * @param  array<string, mixed>  $data
     */
    private function derivePayload(array $data): DiscordWebhookPayload
    {
        $lines = [];
        $name = $data['entity_name'] ?? null;
        if (is_string($name) && $name !== '') {
            $lines[] = $name;
        }

        $actionUrl = $data['action_url'] ?? null;
        if (is_string($actionUrl) && $actionUrl !== '') {
            $lines[] = $actionUrl;
        }

        // Guarantee non-empty content (Discord rejects empty message bodies).
        // No lang key here (lang keys are T04's job); a generic literal keeps
        // the auto-derive path locale-agnostic and resilient to shapes that
        // carry neither entity_name nor action_url.
        if ($lines === []) {
            $lines[] = 'Notification';
        }

        return new DiscordWebhookPayload(
            content: implode("\n", $lines),
        );
    }

    /**
     * Best-effort notification category for log correlation. NotificationService
     * receives the category as a separate argument (not on the instance), so
     * derive from toDatabase()['type'] (which mirrors the category value, e.g.
     * waitlist_promoted), falling back to the class basename.
     */
    private function resolveCategory(Notification $notification, mixed $notifiable): string
    {
        if (method_exists($notification, 'toDatabase')) {
            $data = $notification->toDatabase($notifiable);
            if (is_array($data) && isset($data['type']) && is_string($data['type']) && $data['type'] !== '') {
                return $data['type'];
            }
        }

        return (new \ReflectionClass($notification))->getShortName();
    }

    /**
     * Emit the structured skip log mirroring PushChannel's graceful-degradation
     * logging contract.
     */
    private function skipped(mixed $notifiable, string $notificationType, string $category, string $reason): void
    {
        Log::info('notification.discord_dm_skipped', [
            'user_id' => $notifiable instanceof User ? $notifiable->id : null,
            'notification_type' => $notificationType,
            'category' => $category,
            'reason' => $reason,
        ]);
    }
}
