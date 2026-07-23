<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushChannel;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Central dispatch service for all notifications.
 *
 * Wraps Laravel's Notifiable trait with:
 *   - Per-category channel resolution from notification_settings JSON.
 *   - Block-list filtering: suppresses notifications from blocked actors.
 *   - Structured logging of every dispatch (sent + skipped).
 *   - Error resilience: dispatch failures are logged, never thrown.
 */
class NotificationService
{
    /**
     * Send a notification to a user, respecting their channel preferences
     * and block list.
     *
     * If the notification exposes a getActor() method, the service checks
     * whether the notifiable has blocked that actor and silently skips
     * dispatch if so.
     *
     * Channels are resolved from the user's notification_settings JSON
     * for the given category. Falls back to defaults when the settings
     * are null or malformed.
     */
    public function send(User $notifiable, Notification $notification, NotificationCategory $category): void
    {
        $notificationType = get_class($notification);
        $categoryValue = $category->value;

        try {
            // ── Block-list check ──
            if (method_exists($notification, 'getActor')) {
                $actor = $notification->getActor();
                if ($actor instanceof User && $notifiable->hasBlocked($actor)) {
                    Log::info('notification.dispatch_skipped', [
                        'reason' => 'blocked_actor',
                        'notifiable_id' => $notifiable->id,
                        'actor_id' => $actor->id,
                        'notification_type' => $notificationType,
                        'category' => $categoryValue,
                    ]);

                    return;
                }
            }

            // ── Channel resolution ──
            // resolveChannels() returns the channels the recipient has ENABLED
            // for this category (name => class). Intersect that with the channels
            // the notification itself SUPPORTS (via()) so a database-only
            // notification never sends mail even if the user enabled it, and a
            // user who disabled mail never receives it even if the notification
            // supports it.
            $dispatchChannels = $this->resolveChannels($notifiable, $category);

            if ($notification instanceof BaseNotification) {
                $supportedChannels = $notification->via($notifiable);

                $dispatchChannels = array_filter(
                    $dispatchChannels,
                    fn (string $channel) => in_array($channel, $supportedChannels, true),
                );

                // Push the resolved intersection onto the instance so via() —
                // read by Laravel's queued dispatcher at enqueue time — returns
                // only these channels. This is what makes "mail: false" actually
                // suppress mail across the queue boundary.
                if (! empty($dispatchChannels)) {
                    $notification->setResolvedChannels(array_values($dispatchChannels));
                }
            }

            if (empty($dispatchChannels)) {
                Log::info('notification.dispatch_skipped', [
                    'reason' => 'all_channels_disabled',
                    'notifiable_id' => $notifiable->id,
                    'notification_type' => $notificationType,
                    'category' => $categoryValue,
                ]);

                return;
            }

            // ── Dispatch with recipient locale ──
            // User implements HasLocalePreference, so Laravel's notification sender
            // automatically resolves the correct locale via preferredLocale() before
            // calling toMail/toDatabase/toPush. No global mutation needed.
            //
            // Uses notify() (queued) — BaseNotification implements ShouldQueue so
            // all notifications are processed asynchronously by Horizon workers.
            // In tests, QUEUE_CONNECTION=sync makes this behave identically to notifyNow().
            $notifiable->notify($notification);

            Log::info('notification.dispatched', [
                'notifiable_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $categoryValue,
                'channels' => array_keys($dispatchChannels),
            ]);

            // Retention analytics: capture that a notification was sent to this
            // user, with its category and channels. Enables correlation of
            // 'received attendance nudge' with attendance outcomes.
            app(PostHogAnalytics::class)->capture(
                $notifiable,
                'notification.sent',
                [
                    'category' => $categoryValue,
                    'channels' => array_keys($dispatchChannels),
                ],
            );
        } catch (\Throwable $e) {
            Log::error('notification.dispatch_failed', [
                'notifiable_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $categoryValue,
                'error' => $e->getMessage(),
            ]);

            // Retention analytics: capture notification dispatch failures for
            // channel-health monitoring (e.g. push tokens going stale).
            app(PostHogAnalytics::class)->capture(
                $notifiable,
                'notification.failed',
                ['category' => $categoryValue, 'reason' => 'dispatch_error'],
            );
        }
    }

    /**
     * Mark all unread notifications of a specific type (and optional entity)
     * as read for a user.
     *
     * Used by accept/decline invitation flows to clear the related
     * in-app notification.
     */
    public function markReadByType(User $user, string $notificationType, int|string|null $entityId = null, ?string $dataKey = null): void
    {
        try {
            $query = $user->unreadNotifications()->where('type', $notificationType);

            if ($entityId !== null) {
                $key = $dataKey ?? 'entity_id';
                // Whitelist: only allow known JSON data keys to prevent injection
                $allowedKeys = ['entity_id', 'game_id', 'campaign_id', 'team_id', 'participant_id', 'type'];
                if (! in_array($key, $allowedKeys, true)) {
                    Log::warning('notification.mark_read_invalid_key', [
                        'key' => $key,
                        'user_id' => $user->id,
                    ]);

                    return;
                }
                $query->where("data->{$key}", $entityId);
            }

            $count = $query->update(['read_at' => now()]);

            if ($count > 0) {
                Log::debug('notification.marked_read_by_type', [
                    'user_id' => $user->id,
                    'notification_type' => $notificationType,
                    'entity_id' => $entityId,
                    'count' => $count,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('notification.mark_read_failed', [
                'user_id' => $user->id,
                'notification_type' => $notificationType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the count of unread notifications for a user.
     */
    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * Get recent notifications for a user, grouped by read status.
     *
     * Returns a collection of notifications sorted by created_at desc.
     * Grouping by read/unread is a stub for future UI categorization.

     *
     * @return Collection<(int|string), DatabaseNotificationCollection<int, DatabaseNotification>>
     */
    public function getGroupedRecent(User $user, int $limit = 10): Collection
    {
        return $user->notifications()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->groupBy(fn ($n) => $n->read_at !== null ? 'read' : 'unread');
    }

    /**
     * Resolve which notification channels are enabled for a user
     * and category combination.
     *
     * Reads the user's notification_settings JSON column and resolves each
     * channel independently: a channel key that is explicitly present (even if
     * false) is honoured, while a missing key falls back to that category's
     * default. The whole category also falls back to defaults when the blob is
     * null, the category is absent, or its value is malformed.
     *
     * This per-channel fallback keeps legacy/partial rows (e.g. created before
     * push existed, or for categories added after the user last saved prefs)
     * behaving as intended instead of silently treating a missing channel as
     * disabled.
     *
     * Maps channel names to Laravel channel class strings:
     *   - 'database' → DatabaseChannel::class
     *   - 'mail'     → MailChannel::class
     *   - 'push'     → PushChannel::class
     *
     * @return array<string, string> Channel name => channel class
     */
    public function resolveChannels(User $user, NotificationCategory $category): array
    {
        /** @var array<string, mixed>|null $settings */
        $settings = $user->notification_settings;
        $categoryKey = $category->value;
        $defaults = NotificationCategory::defaultSettings();

        $categoryDefaults = $defaults[$categoryKey] ?? ['database' => true, 'mail' => false, 'push' => false, 'discord' => false];

        // Resolve the stored per-category settings. $stored stays null when the
        // settings blob is null, the category is absent, or its value is malformed
        // — in all those cases we fall back to the full category default below.
        $stored = null;
        if (is_array($settings) && array_key_exists($categoryKey, $settings)) {
            if (is_array($settings[$categoryKey])) {
                $stored = $settings[$categoryKey];
            } else {
                // Key exists but value is malformed — fall back to defaults
                Log::warning('notification.malformed_settings', [
                    'user_id' => $user->id,
                    'category' => $categoryKey,
                    'raw_value' => $settings[$categoryKey],
                ]);
            }
        }

        // Map enabled booleans to channel class strings. Discord (D118) joins
        // the matrix as a fourth channel — its payload is auto-derived from
        // toDatabase() by DiscordChannel, so it routes through the same
        // enabled/supported intersection as the other channels.
        $channelMap = [
            'database' => DatabaseChannel::class,
            'mail' => MailChannel::class,
            'push' => PushChannel::class,
            'discord' => DiscordChannel::class,
        ];

        // Per-channel resolution: use the stored value when the channel key is
        // explicitly present (even if false), otherwise fall back to the category
        // default. This keeps behaviour correct for legacy/partial rows (e.g.
        // created before push existed, or for categories added after the user
        // last saved their preferences) instead of silently treating a missing
        // channel key as "disabled".
        $resolved = [];
        foreach ($channelMap as $name => $class) {
            $enabled = $stored !== null
                ? (bool) ($stored[$name] ?? $categoryDefaults[$name])
                : (bool) $categoryDefaults[$name];

            if ($enabled) {
                $resolved[$name] = $class;
            }
        }

        return $resolved;
    }
}
