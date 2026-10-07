<?php

use App\Enums\DiscordCardStatus;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\Discord\DiscordCardContext;
use App\Services\Discord\DiscordCardRenderer;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * M063 / S06 / T04 — Discord publisher with attached tables present.
 *
 * A "table" is an ordinary Game with games.event_id set (S05). The Discord
 * bridge must behave identically whether or not a game happens to be hosted
 * at an event: a hosted table posts through the standard chokepoint like any
 * public game — same card shape, same post/edit lifecycle — and the card
 * keeps per-table system honesty (it names only the table's own systems,
 * never the umbrella's derived offering or its name; the derived union is a
 * roundup-web surface only).
 *
 * The ordinary-game publisher suites live at
 * tests/Feature/Services/Discord/*PublisherTest.php; the city-hub half of
 * the cross-pollination regression lives at
 * tests/Feature/Events/EventCrossPollinationRegressionTest.php.
 */

beforeEach(function () {
    Cache::flush();
    // Off during fixture setup so GameObserver never dispatches the (sync
    // queue) publish job mid-factory — mirrors DiscordPublisherTest's leak
    // guard. Tests that publish flip it back on explicitly.
    config(['services.discord.publishing_enabled' => false]);
});

/**
 * Publisher wired to an Http::fake()-intercepted webhook client (same shape
 * as DiscordPublisherTest::makePublisher — instant sleep keeps 429 backoff
 * from stalling the test).
 */
function crossPollinationPublisher(): DiscordPublisher
{
    $client = new DiscordWebhookClient(
        baseUrl: 'https://discord.test/api/v10',
        botToken: 'test-bot-token',
        timeout: 5,
        maxAttempts: 3,
        maxRetryAfterSeconds: 30.0,
        serverErrorBackoffSeconds: 0.0,
        sleep: static fn (float $s) => null,
    );

    return new DiscordPublisher($client, new DiscordCardRenderer);
}

/**
 * Http::fake for a successful card POST + thread lifecycle (most-specific
 * pattern first, matching DiscordPublisherTest).
 */
function crossPollinationFakeDiscord(): void
{
    Http::fake([
        'https://discord.test/api/v10/channels/*/messages/*/threads' => Http::response(['id' => 'thread-1', 'type' => 11], 200),
        'https://discord.test/api/v10/channels/*/messages/*' => Http::response(['id' => '999888777666555444'], 200), // edit (PATCH)
        'https://discord.test/api/v10/channels/*/messages' => Http::response(['id' => '999888777666555444', 'channel_id' => '111222333444555666'], 200),
        'https://discord.test/api/v10/channels/*' => Http::response([], 204),
    ]);
}

describe('Discord bridge with attached tables present', function () {
    it('renders an identical card for a hosted table and its standalone twin', function () {
        $systems = GameSystem::factory()->count(2)->create();
        $owner = User::factory()->create();
        // Distinctive umbrella name: its absence from the card payload is the
        // no-leakage assertion below.
        $event = Event::factory()->create(['name' => ['en' => 'Umbrella Game Day Xyzzyq']]);

        $attributes = [
            'owner_id' => $owner->id,
            'name' => ['en' => 'Twin Table'],
            'description' => ['en' => 'A table of friendly games.'],
            'date_time' => now()->addDays(10)->setTime(18, 0),
            'min_players' => 3,
            'max_players' => 6,
            'expected_duration' => 4.0,
            'price' => 0,
            'language' => 'en',
            'status' => 'scheduled',
            'visibility' => 'public',
        ];
        $systemIds = $systems->pluck('id')->all();

        $standalone = Game::factory()
            ->gathering()
            ->withGameSystems($systemIds)
            ->create($attributes);
        $table = Game::factory()
            ->gathering()
            ->withGameSystems($systemIds)
            ->event($event)
            ->create($attributes);

        $renderer = new DiscordCardRenderer;
        $context = new DiscordCardContext(appUrl: 'https://roundup.test', approvedCount: 2);

        $standaloneEmbed = $renderer->render($standalone->loadMissing(['owner', 'linkedLocation', 'gameSystems']), $context)->embed;
        $tableEmbed = $renderer->render($table->loadMissing(['owner', 'linkedLocation', 'gameSystems']), $context)->embed;

        // Normalize the two per-game differences that must exist (the deep
        // link carries each game's own id) and the one order that is not
        // contractually pinned (pivot sync order of equally-ranked systems),
        // then demand byte-identical cards.
        $normalize = function (array $embed, string $gameId): array {
            $data = json_decode(str_replace($gameId, '<game-id>', json_encode($embed)), true);

            foreach ($data['fields'] ?? [] as $index => $field) {
                if (($field['name'] ?? '') === 'System') {
                    $parts = explode(' · ', $field['value']);
                    sort($parts);
                    $data['fields'][$index]['value'] = implode(' · ', $parts);
                }
            }

            return $data;
        };

        expect($normalize($tableEmbed, (string) $table->id))
            ->toBe($normalize($standaloneEmbed, (string) $standalone->id))
            // The umbrella never leaks into the table's card: no event name,
            // no event deep link.
            ->and(json_encode($tableEmbed))->not->toContain('Xyzzyq')
            ->and(json_encode($tableEmbed))->not->toContain('/events/');
    });

    it('publishes a hosted table through the chokepoint with per-table system honesty', function () {
        $owner = User::factory()->create();
        $event = Event::factory()->create(['name' => ['en' => 'Umbrella Game Day Xyzzyq']]);

        [$catan, $wingspan, $dune] = [
            GameSystem::factory()->create(['name' => ['en' => 'Catan']]),
            GameSystem::factory()->create(['name' => ['en' => 'Wingspan']]),
            GameSystem::factory()->create(['name' => ['en' => 'Dune']]),
        ];

        $table = Game::factory()
            ->gathering()
            ->withGameSystems([$catan->id, $wingspan->id])
            ->event($event)
            ->create([
                'owner_id' => $owner->id,
                'name' => ['en' => 'Hosted Catan Table'],
                'visibility' => 'public',
                'status' => 'scheduled',
                'date_time' => now()->addDays(10)->setTime(12, 0),
            ]);
        // A sibling table at the same umbrella offering a different system —
        // the derived offering would union it, but the Discord card must not.
        Game::factory()
            ->gathering()
            ->withGameSystems([$dune->id])
            ->event($event)
            ->create(['owner_id' => $owner->id, 'visibility' => 'public']);

        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['owner_user_id' => User::factory()->create()->id]);
        DiscordGuildOrganizer::factory()
            ->optedIn()
            ->create(['guild_id' => $guild->id, 'user_id' => $owner->id]);

        config(['services.discord.publishing_enabled' => true]);
        crossPollinationFakeDiscord();

        crossPollinationPublisher()->publish($table);

        // Standard chokepoint contract: exactly one tracked card with
        // Discord's echoed message id at status Posted.
        $card = DiscordCardMessage::where('game_id', $table->id)
            ->where('guild_id', $guild->id)
            ->first();
        expect($card)->not->toBeNull()
            ->and($card->message_id)->toBe('999888777666555444')
            ->and($card->status)->toBe(DiscordCardStatus::Posted);

        // The posted card names ONLY this table's systems — the sibling
        // table's Dune and the umbrella itself stay out of the bridge.
        $channel = $guild->games_channel_id;
        $cardPosted = false;
        Http::assertSent(function (Request $request) use (&$cardPosted, $channel): bool {
            if ($request->method() !== 'POST'
                || $request->url() !== "https://discord.test/api/v10/channels/{$channel}/messages") {
                return false;
            }

            $embed = $request->data()['embeds'][0] ?? [];
            $payload = json_encode($embed);

            expect($payload)->toContain('Hosted Catan Table')
                ->toContain('Catan')
                ->toContain('Wingspan')
                ->not->toContain('Dune')
                ->not->toContain('Xyzzyq');
            $cardPosted = true;

            return true;
        });
        expect($cardPosted)->toBeTrue();

        // Republish converges on the single tracked card (edit-in-place
        // idempotency applies to tables exactly as to ordinary games).
        crossPollinationPublisher()->publish($table);
        expect(DiscordCardMessage::where('game_id', $table->id)->count())->toBe(1);
    });
});
