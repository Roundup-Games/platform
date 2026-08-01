<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Syncs a user's notification_settings blob when their Discord account link
 * state changes (M059/S01).
 *
 * Implements the refined Discord-priority policy (decision D-b): when a Discord
 * account is linked, Discord DM is turned ON for every actionable category
 * (defaultDiscordEnabled), and EMAIL is turned OFF for the urgency tier
 * (defaultMailEnabled — invitations, application outcomes, waitlist promotions,
 * cancellations, session reminders, confirmation-expired, moderation notices)
 * because the Discord DM now covers that urgency. The other channels
 * (database/in-app, push) are never touched, and email remains user-re-
 * enableable in settings — when re-enabled it fires in PARALLEL with Discord
 * (no suppression at dispatch time). Ambient categories (followers, completions)
 * are left on their off defaults.
 *
 * This is a one-time default-shift applied at the link moment, not a runtime
 * suppression — NotificationService still fans out to every enabled channel in
 * parallel (D118 invariant preserved). {@see enableDiscordDefaultsFor()} is
 * idempotent: a re-run only flips channels that are not already at the target.
 */
class NotificationPreferenceSync
{
    /**
     * Turn Discord DM on for every actionable category and suppress email for
     * the urgency tier, writing the result into the user's notification_settings.
     *
     * Safe to call repeatedly (idempotent): channels already at the target value
     * are left untouched. A null/empty blob is seeded from
     * {@see NotificationCategory::defaultSettings()} first.
     */
    public function enableDiscordDefaultsFor(User $user): void
    {
        /** @var array<string, array<string, mixed>>|null $stored */
        $stored = $user->notification_settings;
        $defaults = NotificationCategory::defaultSettings();

        // Seed a missing/malformed blob from the full defaults so every category
        // key exists before we mutate — mirrors how Settings\Show::mount and
        // NotificationService::resolveChannels treat a null blob.
        $blob = is_array($stored) ? $stored : [];

        $discordFlipped = 0;
        $mailSuppressed = 0;

        foreach (NotificationCategory::cases() as $category) {
            $key = $category->value;
            $categoryDefault = $defaults[$key] ?? ['database' => true, 'mail' => false, 'push' => false, 'discord' => false];
            $entry = is_array($blob[$key] ?? null) ? $blob[$key] : $categoryDefault;

            // Discord ON for actionable categories (defaultDiscordEnabled).
            if ($category->defaultDiscordEnabled() && ! (bool) ($entry['discord'] ?? false)) {
                $entry['discord'] = true;
                $discordFlipped++;
            }

            // Email OFF for the urgency tier (defaultMailEnabled) — Discord now
            // covers urgency. Only flips when mail is currently true (the
            // default for urgent categories); a user who already disabled mail
            // is unaffected, and a user who later re-enables it gets parallel
            // delivery (runtime resolution honours it).
            if ($category->defaultMailEnabled() && (bool) ($entry['mail'] ?? false)) {
                $entry['mail'] = false;
                $mailSuppressed++;
            }

            // Preserve the other channel booleans; carry the (possibly mutated)
            // entry back into the blob.
            $blob[$key] = [
                'database' => (bool) ($entry['database'] ?? $categoryDefault['database']),
                'mail' => (bool) ($entry['mail'] ?? $categoryDefault['mail']),
                'push' => (bool) ($entry['push'] ?? $categoryDefault['push']),
                'discord' => (bool) ($entry['discord'] ?? $categoryDefault['discord']),
            ];
        }

        $user->notification_settings = $blob;
        $user->save();

        Log::info('notification.discord_auto_enabled', [
            'user_id' => $user->id,
            'discord_categories_enabled' => $discordFlipped,
            'urgent_mail_suppressed' => $mailSuppressed,
        ]);
    }
}
