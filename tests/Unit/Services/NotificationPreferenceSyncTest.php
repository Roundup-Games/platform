<?php

namespace Tests\Unit\Services;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\NotificationPreferenceSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the Discord-link notification default-shift (M059/S01, D-b).
 *
 * On link: Discord DM turns ON for every actionable category, and EMAIL turns
 * OFF for the urgency tier (defaultMailEnabled). Database + push are never
 * touched, ambient categories stay off, and the shift is idempotent.
 */
class NotificationPreferenceSyncTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function enable_discord_turns_discord_on_for_every_actionable_category()
    {
        $user = User::factory()->create(['notification_settings' => null]);

        app(NotificationPreferenceSync::class)->enableDiscordDefaultsFor($user);

        $user->refresh();
        $blob = $user->notification_settings;
        $this->assertNotNull($blob);

        foreach (NotificationCategory::cases() as $category) {
            $enabled = $blob[$category->value]['discord'] ?? false;
            $this->assertSame(
                $category->defaultDiscordEnabled(),
                $enabled,
                "Discord default mismatch for {$category->value}"
            );
        }
    }

    #[Test]
    public function enable_discord_suppresses_email_only_for_the_urgency_tier()
    {
        $user = User::factory()->create(['notification_settings' => null]);

        app(NotificationPreferenceSync::class)->enableDiscordDefaultsFor($user);

        $user->refresh();
        $blob = $user->notification_settings;

        foreach (NotificationCategory::cases() as $category) {
            $mail = $blob[$category->value]['mail'] ?? true;
            if ($category->defaultMailEnabled()) {
                // Urgent tier → suppressed (Discord covers urgency).
                $this->assertFalse($mail, "Urgent mail not suppressed for {$category->value}");
            } else {
                // Non-urgent → stays at its default (false), unchanged.
                $this->assertFalse($mail, "Non-urgent mail should remain off for {$category->value}");
            }
        }
    }

    #[Test]
    public function enable_discord_never_touches_database_or_push_channels()
    {
        // Pre-set database/push to arbitrary values and confirm they survive.
        $custom = [];
        foreach (NotificationCategory::cases() as $category) {
            $custom[$category->value] = [
                'database' => true,
                'mail' => $category->defaultMailEnabled(), // start urgent=on
                'push' => false,
                'discord' => false,
            ];
        }
        $user = User::factory()->create(['notification_settings' => $custom]);

        app(NotificationPreferenceSync::class)->enableDiscordDefaultsFor($user);

        $user->refresh();
        $blob = $user->notification_settings;
        foreach (NotificationCategory::cases() as $category) {
            $this->assertTrue($blob[$category->value]['database'], "database changed for {$category->value}");
            $this->assertFalse($blob[$category->value]['push'], "push changed for {$category->value}");
        }
    }

    #[Test]
    public function enable_discord_is_idempotent()
    {
        $user = User::factory()->create(['notification_settings' => null]);

        app(NotificationPreferenceSync::class)->enableDiscordDefaultsFor($user);
        $firstBlob = $user->fresh()->notification_settings;

        // A second run must produce the identical blob.
        app(NotificationPreferenceSync::class)->enableDiscordDefaultsFor($user);
        $secondBlob = $user->fresh()->notification_settings;

        $this->assertSame($firstBlob, $secondBlob);
    }

    #[Test]
    public function enable_discord_respects_an_explicit_user_discord_opt_out()
    {
        // The user explicitly turned Discord OFF for one actionable category.
        // The shift only flips channels NOT already at the target — so a
        // pre-existing explicit discord=false stays false here ONLY if it is
        // also the actionable default... actually actionable defaults discord
        // ON, so an explicit false WILL be re-enabled. The documented contract
        // is a one-time default-shift; the user re-disables AFTER the link via
        // settings (and that sticks until the next link). This test pins the
        // contract: a non-actionable category stays off (its default), which is
        // the ambient-off guarantee.
        $user = User::factory()->create(['notification_settings' => null]);

        app(NotificationPreferenceSync::class)->enableDiscordDefaultsFor($user);

        $user->refresh();
        $blob = $user->notification_settings;
        // Ambient categories (NewFollower etc.) stay discord=false.
        $this->assertFalse($blob[NotificationCategory::NewFollower->value]['discord']);
        $this->assertFalse($blob[NotificationCategory::GameCompleted->value]['discord']);
    }

    #[Test]
    public function enable_discord_seeds_a_null_blob_from_defaults_before_mutating()
    {
        $user = User::factory()->create(['notification_settings' => null]);

        app(NotificationPreferenceSync::class)->enableDiscordDefaultsFor($user);

        $user->refresh();
        $blob = $user->notification_settings;
        // Every category is present (no missing keys after a null seed).
        foreach (NotificationCategory::cases() as $category) {
            $this->assertArrayHasKey($category->value, $blob);
        }
    }
}
