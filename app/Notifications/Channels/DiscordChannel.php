<?php

namespace App\Notifications\Channels;

use App\Exceptions\DiscordApiException;
use App\Models\User;
use App\Notifications\ResolvesNotificationCategory;
use App\Services\Discord\DiscordWebhookClient;
use App\Services\Discord\DiscordWebhookPayload;
use App\Services\NotificationEngagementCounters;
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
 * Payload requires an explicit toDiscord() on the notification (D247):
 * return a DiscordWebhookPayload to post it verbatim; return null for a
 * deliberate opt-out (D130). A notification without toDiscord() at all
 * is a GAP — the DM is skipped with a warning naming the class, and no
 * content is ever synthesized. The auto-derive fallback this replaced
 * built bodies from entity_name + action_url (or a bare URL when the
 * shape carried neither name key), which read as bot spam and burned
 * the channel's trust (2026-10-24 badge DM incident).
 *
 * Every no-op path emits a structured notification.discord_dm_skipped log with
 * a typed reason; success emits notification.discord_dm_sent. DiscordApiException
 * (notably the shared-guild DM-creation 403, research §10) is logged at warning
 * level so it is diagnosable without surfacing as a dispatch failure.
 *
 * Every log context carries app_id_short (last 4 of
 * services.discord.bot_application_id, D282): a dedicated roundup-dev Discord
 * application is live in non-prod environments alongside the production bot,
 * and the marker makes dev-app vs prod-app activity distinguishable in any
 * shared log stream without echoing the full application snowflake.
 *
 * Engagement counters (D284, M080/S03): every outcome bumps the
 * per-(date, category, channel, event) daily aggregate in
 * notification_engagement_counters — dm_sent on delivery, dm_skipped:<reason>
 * on every skip outcome, dm_failed on each Discord API failure. An API
 * failure deliberately lands in TWO rows (dm_failed AND the subsequent
 * dm_skipped:<reason>): they answer different questions ("did the Discord
 * API fail?" vs "what was the send outcome?") and are independent daily
 * aggregates, not a per-event ledger. Counter writes are best-effort: a
 * failure is logged and swallowed, never allowed to break delivery.
 */
class DiscordChannel
{
    use ResolvesNotificationCategory;

    public function __construct(
        private readonly NotificationEngagementCounters $counters,
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

        // Build the payload — an explicit toDiscord() is REQUIRED (D247):
        // the returned payload posts verbatim; returning null is the
        // deliberate opt-out (D130). A notification without toDiscord()
        // at all is a GAP, not an opt-out: skip with a warning naming
        // the class, and never synthesize content. The auto-derive this
        // replaced built DM bodies from entity_name + action_url — and
        // a bare URL when the shape carried neither — which read as bot
        // spam and burned the channel's trust (2026-10-24 badge DM
        // incident: four identical link-only DMs from the official bot).
        if (! method_exists($notification, 'toDiscord')) {
            Log::warning('notification.discord_dm_missing_payload', [
                'user_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $category,
                'app_id_short' => $this->appIdShort(),
            ]);

            $this->skipped($notifiable, $notificationType, $category, 'no_discord_payload');

            return;
        }

        $payload = $notification->toDiscord($notifiable);
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
                'app_id_short' => $this->appIdShort(),
                'step' => 'create_dm_channel',
                'error' => $e->getMessage(),
            ]);
            $this->count($category, 'dm_failed');
            $this->skipped($notifiable, $notificationType, $category, 'dm_channel_failed');

            return;
        }

        // Step 2 — post the derived DM message.
        try {
            $messageId = $this->client->postMessage($dmChannelId, $payload);
        } catch (DiscordApiException $e) {
            Log::warning('notification.discord_dm_api_error', [
                'user_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $category,
                'app_id_short' => $this->appIdShort(),
                'step' => 'post_message',
                'dm_channel_id' => $dmChannelId,
                'error' => $e->getMessage(),
            ]);
            $this->count($category, 'dm_failed');
            $this->skipped($notifiable, $notificationType, $category, 'send_failed');

            return;
        }

        Log::info('notification.discord_dm_sent', [
            'user_id' => $notifiable->id,
            'notification_type' => $notificationType,
            'category' => $category,
            'app_id_short' => $this->appIdShort(),
            'dm_channel_id' => $dmChannelId,
            // Discord message snowflake — retained so a delivery can be traced
            // to the specific message (lookup, uniqueness audit, support).
            'message_id' => $messageId,
        ]);

        $this->count($category, 'dm_sent');
    }

    /**
     * Identity marker for D282 dev-application verification: the last 4
     * characters of the configured application id, so any notification.discord_dm_*
     * log line can be attributed to the dev or the production application in a
     * shared log stream. Null when no application id is configured (marker
     * present with a null value keeps the context shape uniform).
     */
    private function appIdShort(): ?string
    {
        $applicationId = config('services.discord.bot_application_id');
        if (! is_string($applicationId) || $applicationId === '') {
            return null;
        }

        return substr($applicationId, -4);
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
            'app_id_short' => $this->appIdShort(),
        ]);

        $this->count($category, 'dm_skipped:'.$reason);
    }

    /**
     * Best-effort engagement counter increment (D284): the DM delivery (or
     * graceful skip) above is the load-bearing behavior, so a counter write
     * failure is logged and swallowed — it must never break delivery.
     */
    private function count(string $category, string $event): void
    {
        try {
            $this->counters->incrementFor($category, 'discord', $event);
        } catch (\Throwable $e) {
            Log::warning('notifications.engagement_counter_failed', [
                'category' => $category,
                'channel' => 'discord',
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
