<?php

use App\Enums\ContentLanguage;
use App\Models\Game;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushChannel;
use App\Notifications\SessionReminder;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

//
// Per-user timezone rendering (R089): SessionReminder converts the UTC game
// time into the NOTIFIABLE user's stored IANA timezone, falling back to
// Europe/Berlin when none is captured — MEM220's deliberate ??-hook, now fed
// by the login-device capture.
//
// The fixture instant sits in the narrow window between the EU and US DST
// switches (EU ends Oct 25 2026, US ends Nov 1 2026), so the two zones carry
// DIFFERENT offsets and abbreviations — Berlin renders 7:00 PM CET while New
// York renders 2:00 PM EDT — making the per-user rendering unmistakable.
//

beforeEach(function () {
    Notification::fake();
});

it('renders the reminder time in each notifiable user\'s stored timezone', function () {
    // 18:00 UTC → 19:00 CET (Europe/Berlin) and 14:00 EDT (America/New_York)
    $game = Game::factory()->create([
        'date_time' => Carbon::parse('2026-10-31 18:00:00', 'UTC'),
    ]);

    $berlin = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $newYork = User::factory()->create(['timezone' => 'America/New_York']);

    $berlin->notify(new SessionReminder($game));
    $newYork->notify(new SessionReminder($game));

    Notification::assertSentTo($berlin, SessionReminder::class,
        function (SessionReminder $notification, array $channels) use ($berlin, $game): bool {
            // Channel contract: database + push + discord, no mail
            if (! in_array(DatabaseChannel::class, $channels, true)
                || ! in_array(PushChannel::class, $channels, true)
                || ! in_array(DiscordChannel::class, $channels, true)
                || in_array('mail', $channels, true)) {
                return false;
            }

            $pushBody = $notification->toPush($berlin)->body;
            $discordBody = $notification->toDiscord($berlin)->embeds[0]['description'] ?? '';

            // Local time AND abbreviation are the Berlin pair, and differ per user
            return str_contains($pushBody, '7:00 PM CET')
                && str_contains($discordBody, '7:00 PM CET')
                && $notification->game->id === $game->id;
        });

    Notification::assertSentTo($newYork, SessionReminder::class,
        function (SessionReminder $notification) use ($newYork): bool {
            $pushBody = $notification->toPush($newYork)->body;

            // Different local time AND different abbreviation than the Berlin user
            return str_contains($pushBody, '2:00 PM EDT')
                && ! str_contains($pushBody, '7:00 PM CET');
        });
});

it('renders the reminder time on the locale dial — 24h for de, 12h AM/PM for en (R089)', function () {
    $game = Game::factory()->create([
        'date_time' => Carbon::parse('2026-10-31 18:00:00', 'UTC'),
    ]);

    $berlin = User::factory()->create([
        'timezone' => 'Europe/Berlin',
        'preferred_language' => ContentLanguage::De,
    ]);

    $berlin->notify(new SessionReminder($game));

    Notification::assertSentTo($berlin, SessionReminder::class,
        function (SessionReminder $notification) use ($berlin): bool {
            $body = $notification->toPush($berlin)->body;

            // 18:00 UTC = 19:00 CET — German users see the 24-hour dial
            // with the timezone abbreviation, no AM/PM.
            return str_contains($body, '19:00 CET')
                && ! str_contains($body, 'PM')
                && ! str_contains($body, 'AM');
        });
});

it('falls back to Europe/Berlin when the user has no stored timezone', function () {
    $game = Game::factory()->create([
        'date_time' => Carbon::parse('2026-10-31 18:00:00', 'UTC'),
    ]);

    $noTimezone = User::factory()->create(); // timezone null — "not captured yet"

    $noTimezone->notify(new SessionReminder($game));

    Notification::assertSentTo($noTimezone, SessionReminder::class,
        function (SessionReminder $notification) use ($noTimezone): bool {
            return str_contains($notification->toPush($noTimezone)->body, '7:00 PM CET');
        });
});
