<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
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
            $channels = $this->resolveChannels($notifiable, $category);

            if (empty($channels)) {
                Log::info('notification.dispatch_skipped', [
                    'reason' => 'all_channels_disabled',
                    'notifiable_id' => $notifiable->id,
                    'notification_type' => $notificationType,
                    'category' => $categoryValue,
                ]);

                return;
            }

            // ── Set recipient locale for translation ──
            $previousLocale = app()->getLocale();
            $recipientLocale = $notifiable->preferred_language?->value ?? $previousLocale;
            app()->setLocale($recipientLocale);

            // ── Dispatch with channel filtering ──
            $notifiable->notifyNow($notification, $channels);

            // ── Restore previous locale ──
            app()->setLocale($previousLocale);

            Log::info('notification.dispatched', [
                'notifiable_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $categoryValue,
                'channels' => array_keys($channels),
            ]);
        } catch (\Throwable $e) {
            Log::error('notification.dispatch_failed', [
                'notifiable_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $categoryValue,
                'error' => $e->getMessage(),
            ]);
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
                $driver = $query->getQuery()->getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $query->whereRaw("CAST(data AS json)->>'{$key}' = CAST(? AS text)", [$entityId]);
                } else {
                    $query->where("data->{$key}", $entityId);
                }
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
     * @return \Illuminate\Support\Collection
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
     * Reads the user's notification_settings JSON column. Falls back to
     * the category defaults from NotificationCategory::defaultSettings()
     * when the settings are null or the specific category entry is missing/malformed.
     *
     * Maps channel names to Laravel channel class strings:
     *   - 'database' → DatabaseChannel::class
     *   - 'mail'     → MailChannel::class
     *
     * @return array<string, string> Channel name => channel class
     */
    public function resolveChannels(User $user, NotificationCategory $category): array
    {
        $settings = $user->notification_settings;
        $categoryKey = $category->value;
        $defaults = NotificationCategory::defaultSettings();

        // Use stored settings if valid, otherwise fall back to defaults
        $categorySettings = null;
        if (is_array($settings) && isset($settings[$categoryKey]) && is_array($settings[$categoryKey])) {
            $categorySettings = $settings[$categoryKey];
        } elseif (is_array($settings) && array_key_exists($categoryKey, $settings)) {
            // Key exists but value is malformed — fall back to defaults
            Log::warning('notification.malformed_settings', [
                'user_id' => $user->id,
                'category' => $categoryKey,
                'raw_value' => $settings[$categoryKey],
            ]);
        }

        if ($categorySettings === null) {
            $categorySettings = $defaults[$categoryKey] ?? ['database' => true, 'mail' => false];
        }

        // Map enabled booleans to channel class strings
        $channelMap = [
            'database' => DatabaseChannel::class,
            'mail' => MailChannel::class,
            'push' => PushChannel::class,
        ];

        $resolved = [];
        foreach ($channelMap as $name => $class) {
            if (! empty($categorySettings[$name])) {
                $resolved[$name] = $class;
            }
        }

        return $resolved;
    }
}
