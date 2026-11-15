<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\TaggedMailChannel;
use Illuminate\Notifications\Notification;
use ReflectionClass;

/**
 * Shared notification-category resolution for the engagement counters
 * (M080/S03, D284) — the single source of the category vocabulary that
 * mail, push, and Discord counters must agree on.
 *
 * Derived from toDatabase()['type'] (which mirrors the category value,
 * e.g. waitlist_promoted), falling back to the class basename. Framework
 * notifications without toDatabase() (VerifyEmail, ResetPassword) take
 * the basename fallback.
 *
 * Used by {@see TaggedMailChannel},
 * {@see PushChannel}, and
 * {@see DiscordChannel}. Keep this the only
 * implementation: three channel-local copies drift silently, and drift
 * corrupts cross-channel counter comparability — the exact invariant
 * D284's daily aggregates exist to preserve.
 */
trait ResolvesNotificationCategory
{
    /**
     * Best-effort notification category for counter attribution.
     */
    private function resolveCategory(Notification $notification, mixed $notifiable): string
    {
        if (method_exists($notification, 'toDatabase')) {
            $data = $notification->toDatabase($notifiable);
            if (is_array($data) && isset($data['type']) && is_string($data['type']) && $data['type'] !== '') {
                return $data['type'];
            }
        }

        return (new ReflectionClass($notification))->getShortName();
    }
}
