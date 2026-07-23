<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\WaitlistPromoted;
use App\Services\NotificationService;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Channels\PushChannel;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dispatch proof for the Discord channel matrix wiring (D118, T03).
 *
 * Proves the three seams that carry the new channel end-to-end:
 *   1. NotificationCategory::defaultSettings() carries a 'discord' key + a
 *      defaultDiscordEnabled() default (so a missing key falls back at read
 *      time — MEM856, no data migration).
 *   2. NotificationService::resolveChannels() maps 'discord' => DiscordChannel.
 *   3. BaseNotification::supportedChannels() includes DiscordChannel
 *      unconditionally (payload auto-derived from toDatabase(), so every
 *      notification type is discord-eligible).
 *
 * Together these make NotificationService::send() dispatch DiscordChannel
 * alongside DatabaseChannel in the same parallel notification (MEM912),
 * verified via Notification::fake() recording the resolved via() channels.
 *
 * Delivery mechanics (DM creation, linked-account gating, graceful no-ops) are
 * exhaustively covered by DiscordChannelTest (T02); this file is intentionally
 * scoped to the routing/intersection wiring that T03 introduces.
 */
class DiscordChannelDispatchTest extends TestCase
{
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new NotificationService;
    }

    // ── Matrix: defaultSettings carries the discord key ──

    #[Test]
    public function default_settings_include_a_discord_key_for_every_category(): void
    {
        $defaults = NotificationCategory::defaultSettings();

        foreach (NotificationCategory::cases() as $category) {
            $this->assertArrayHasKey(
                'discord',
                $defaults[$category->value],
                "Missing discord key for {$category->value}",
            );
            $this->assertIsBool($defaults[$category->value]['discord']);
        }
    }

    #[Test]
    public function default_discord_enabled_mirrors_push_defaults_for_every_category(): void
    {
        // Discord is a free, push-like channel that inherits the push defaults
        // (on for actionable categories, off for ambient noise).
        foreach (NotificationCategory::cases() as $category) {
            $this->assertSame(
                $category->defaultPushEnabled(),
                $category->defaultDiscordEnabled(),
                "discord default drifts from push for {$category->value}",
            );
        }
    }

    // ── resolveChannels: the channelMap wiring ──

    #[Test]
    public function resolve_channels_includes_discord_when_the_recipient_has_it_enabled(): void
    {
        $user = User::factory()->create([
            'notification_settings' => [
                'waitlist_promoted' => ['database' => true, 'discord' => true],
            ],
        ]);

        $channels = $this->service->resolveChannels($user, NotificationCategory::WaitlistPromoted);

        $this->assertArrayHasKey('discord', $channels);
        $this->assertSame(DiscordChannel::class, $channels['discord']);
    }

    #[Test]
    public function resolve_channels_falls_back_to_default_discord_when_the_key_is_absent(): void
    {
        // A legacy/partial row created before discord existed — no discord key.
        // Must fall back to the category default (waitlist_promoted: push=true
        // => discord=true), NOT be treated as "off" (MEM856).
        $user = User::factory()->create([
            'notification_settings' => [
                'waitlist_promoted' => ['database' => true, 'mail' => true],
            ],
        ]);

        $channels = $this->service->resolveChannels($user, NotificationCategory::WaitlistPromoted);

        $this->assertArrayHasKey('discord', $channels);
        $this->assertSame(DiscordChannel::class, $channels['discord']);
    }

    #[Test]
    public function resolve_channels_omits_discord_when_the_recipient_has_it_disabled(): void
    {
        $user = User::factory()->create([
            'notification_settings' => [
                'waitlist_promoted' => ['database' => true, 'discord' => false],
            ],
        ]);

        $channels = $this->service->resolveChannels($user, NotificationCategory::WaitlistPromoted);

        $this->assertArrayNotHasKey('discord', $channels);
        $this->assertArrayHasKey('database', $channels);
    }

    #[Test]
    public function resolve_channels_omits_discord_for_an_ambient_category_by_default(): void
    {
        // NewFollower defaultPushEnabled = false => defaultDiscordEnabled = false.
        $user = User::factory()->create(['notification_settings' => null]);

        $channels = $this->service->resolveChannels($user, NotificationCategory::NewFollower);

        $this->assertArrayNotHasKey('discord', $channels);
    }

    // ── BaseNotification::supportedChannels (unconditional) ──

    #[Test]
    public function supported_channels_declares_discord_unconditionally_for_any_base_notification(): void
    {
        // via() returns supportedChannels() when resolvedChannels is unset.
        // WaitlistPromoted is constructed with an unsaved Game — via() never
        // touches the entity, so persistence is unnecessary.
        $notification = new WaitlistPromoted(Game::factory()->make());

        $channels = $notification->via(User::factory()->make());

        $this->assertContains(DiscordChannel::class, $channels);
        $this->assertContains(DatabaseChannel::class, $channels);
    }

    // ── End-to-end dispatch proof ──

    #[Test]
    public function send_dispatches_discord_alongside_database_in_parallel_when_enabled(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            // mail/push disabled so the dispatched set is exactly database +
            // discord, isolating the discord routing from the other channels.
            'notification_settings' => [
                'waitlist_promoted' => ['database' => true, 'mail' => false, 'push' => false, 'discord' => true],
            ],
        ]);

        $this->service->send(
            $user,
            new WaitlistPromoted(Game::factory()->make()),
            NotificationCategory::WaitlistPromoted,
        );

        // The fake records via() (the resolved intersection). Parallel dispatch
        // (MEM912): discord must fire alongside database, not replace it.
        Notification::assertSentTo(
            $user,
            WaitlistPromoted::class,
            function (WaitlistPromoted $notification, array $channels): bool {
                return in_array(DiscordChannel::class, $channels, true)
                    && in_array(DatabaseChannel::class, $channels, true)
                    && ! in_array(MailChannel::class, $channels, true)
                    && ! in_array(PushChannel::class, $channels, true);
            },
        );
    }

    #[Test]
    public function send_omits_discord_when_disabled_but_still_dispatches_database(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'notification_settings' => [
                'waitlist_promoted' => ['database' => true, 'mail' => false, 'push' => false, 'discord' => false],
            ],
        ]);

        $this->service->send(
            $user,
            new WaitlistPromoted(Game::factory()->make()),
            NotificationCategory::WaitlistPromoted,
        );

        Notification::assertSentTo(
            $user,
            WaitlistPromoted::class,
            function (WaitlistPromoted $notification, array $channels): bool {
                return ! in_array(DiscordChannel::class, $channels, true)
                    && in_array(DatabaseChannel::class, $channels, true);
            },
        );
    }

    #[Test]
    public function send_dispatches_discord_for_every_category_the_recipient_has_enabled(): void
    {
        // Cross-category smoke: game_invitation defaults to discord=true (push
        // default), so a user with no stored settings gets discord routed for
        // it alongside database — proving the wiring is uniform across types.
        Notification::fake();

        $user = User::factory()->create(['notification_settings' => null]);

        $notification = new class extends BaseNotification
        {
            public function toDatabase(object $notifiable): array
            {
                return ['type' => 'game_invitation', 'entity_name' => 'Catan Night'];
            }
        };

        $this->service->send($user, $notification, NotificationCategory::GameInvitation);

        Notification::assertSentTo(
            $user,
            $notification::class,
            fn (BaseNotification $n, array $channels): bool => in_array(DiscordChannel::class, $channels, true)
                && in_array(DatabaseChannel::class, $channels, true),
        );
    }
}
