<?php

namespace Tests\Feature\Services\Discord;

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Services\Discord\DiscordDigestContext;
use App\Services\Discord\DiscordDigestRenderer;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure-transformer tests for {@see DiscordDigestRenderer}.
 *
 * The renderer's contract is explicitly "no Discord I/O, no database queries,
 * no filesystem access" (MEM917) — it reads only the Games' attributes and
 * pre-loaded relations plus a {@see DiscordDigestContext} DTO. These tests
 * prove that contract by building fixtures with factory `make()` +
 * `setRelation()` (no DB persistence) and asserting zero queries are issued
 * during render (see {@see renderer_issuing_no_database_queries}).
 */
class DiscordDigestRendererTest extends TestCase
{
    private DiscordDigestRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new DiscordDigestRenderer;
        app()->setLocale('en');
    }

    // ── Grouping: date → venue, multi-table nights ──────

    #[Test]
    public function groups_games_by_date_with_one_embed_per_date()
    {
        $venue = $this->makeVenue(['name' => 'The Dragon']);
        $day1 = $this->makeGameOn('2026-08-15 19:00:00', venue: $venue);
        $day2 = $this->makeGameOn('2026-08-16 18:00:00', venue: $venue);
        $games = collect([$day2, $day1]); // intentionally unsorted

        $payload = $this->renderer->render($games, $this->context());

        $embeds = $payload->toArray()['embeds'];
        $this->assertCount(2, $embeds);
        $this->assertStringContainsString('15', $embeds[0]['title']); // sorted ascending
        $this->assertStringContainsString('16', $embeds[1]['title']);
    }

    #[Test]
    public function multi_table_night_collapses_under_one_venue_heading()
    {
        $venue = $this->makeVenue(['id' => 'loc-1', 'name' => 'The Dragon']);
        $gameA = $this->makeGameOn('2026-08-15 19:00:00', venue: $venue, name: 'Catan');
        $gameB = $this->makeGameOn('2026-08-15 19:00:00', venue: $venue, name: 'Carcassonne');
        $gameC = $this->makeGameOn('2026-08-15 21:00:00', venue: $venue, name: 'Azul');

        $payload = $this->renderer->render(collect([$gameC, $gameA, $gameB]), $this->context());

        $embed = $payload->toArray()['embeds'][0];
        $this->assertCount(1, $embed['fields'], 'one venue field for the multi-table night');
        $this->assertSame('The Dragon', $embed['fields'][0]['name']);

        // All three games appear under the single heading, ordered by time.
        $value = $embed['fields'][0]['value'];
        $this->assertStringContainsString('Catan', $value);
        $this->assertStringContainsString('Carcassonne', $value);
        $this->assertStringContainsString('Azul', $value);
        $this->assertStringContainsString('19:00', $value);
        $this->assertStringContainsString('21:00', $value);
    }

    #[Test]
    public function distinct_venues_on_same_date_render_as_separate_fields()
    {
        $venueA = $this->makeVenue(['id' => 'loc-a', 'name' => 'The Dragon']);
        $venueB = $this->makeVenue(['id' => 'loc-b', 'name' => 'Café Berlin']);
        $gameA = $this->makeGameOn('2026-08-15 19:00:00', venue: $venueA, name: 'Catan');
        $gameB = $this->makeGameOn('2026-08-15 20:00:00', venue: $venueB, name: 'Azul');

        $payload = $this->renderer->render(collect([$gameB, $gameA]), $this->context());

        $embed = $payload->toArray()['embeds'][0];
        $this->assertCount(2, $embed['fields']);
        $this->assertSame(['The Dragon', 'Café Berlin'], array_column($embed['fields'], 'name'));
    }

    #[Test]
    public function games_without_a_venue_collapse_into_a_single_no_venue_field()
    {
        $gameA = $this->makeGameOn('2026-08-15 19:00:00', venue: null, name: 'Online Game');
        $gameB = $this->makeGameOn('2026-08-15 20:00:00', venue: null, name: 'Another Online');

        $payload = $this->renderer->render(collect([$gameB, $gameA]), $this->context());

        $embed = $payload->toArray()['embeds'][0];
        $this->assertCount(1, $embed['fields']);
        $this->assertSame('Online / no venue', $embed['fields'][0]['name']);
        $this->assertStringContainsString('Online Game', $embed['fields'][0]['value']);
        $this->assertStringContainsString('Another Online', $embed['fields'][0]['value']);
    }

    // ── One-liner shape ──────────────────────────────────

    #[Test]
    public function game_line_links_game_name_to_roundup_and_shows_time_systems_roster()
    {
        $system = $this->makeSystem(['name' => 'Catan']);
        $game = $this->makeGameOn(
            '2026-08-15 19:00:00',
            name: 'Catan Night',
            systems: collect([$system]),
            overrides: ['max_players' => 5],
        );
        $game->id = '01234567-89ab-cdef-0123-456789abcdef';

        $context = new DiscordDigestContext(
            approvedCounts: ['01234567-89ab-cdef-0123-456789abcdef' => 3],
            appUrl: 'https://roundup.test',
        );

        $payload = $this->renderer->render(collect([$game]), $context);
        $line = $payload->toArray()['embeds'][0]['fields'][0]['value'];

        $this->assertStringContainsString('19:00', $line);
        $this->assertStringContainsString('[Catan Night](https://roundup.test/games/01234567-89ab-cdef-0123-456789abcdef)', $line);
        $this->assertStringContainsString('Catan', $line);
        $this->assertStringContainsString('3/5', $line);
    }

    #[Test]
    public function full_roster_line_shows_full_marker()
    {
        $game = $this->makeGameOn('2026-08-15 19:00:00', overrides: ['max_players' => 4]);
        $game->id = 'game-full';
        $context = new DiscordDigestContext(approvedCounts: ['game-full' => 4]);

        $line = $this->lineFor($game, $context);

        $this->assertStringContainsString('4/4', $line);
        $this->assertStringContainsString('full', $line);
    }

    #[Test]
    public function unlimited_capacity_renders_open_roster()
    {
        $game = $this->makeGameOn('2026-08-15 19:00:00', overrides: ['max_players' => 0]);

        $line = $this->lineFor($game, $this->context());

        $this->assertStringContainsString('open', $line);
        $this->assertStringNotContainsString('/0', $line);
    }

    #[Test]
    public function game_without_approved_count_in_context_defaults_to_zero()
    {
        $game = $this->makeGameOn('2026-08-15 19:00:00', overrides: ['max_players' => 6]);

        $line = $this->lineFor($game, new DiscordDigestContext);

        $this->assertStringContainsString('0/6', $line);
    }

    #[Test]
    public function game_line_omits_systems_segment_when_none_loaded()
    {
        $game = $this->makeGameOn('2026-08-15 19:00:00', systems: null);

        $line = $this->lineFor($game, $this->context());

        $this->assertStringContainsString('19:00', $line);
        // Still a valid roster segment; no systems segment.
        $this->assertStringNotContainsString('· ·', $line);
    }

    // ── Date heading ─────────────────────────────────────

    #[Test]
    public function date_heading_is_locale_aware_day_and_month()
    {
        $game = $this->makeGameOn('2026-08-15 19:00:00'); // 2026-08-15 is a Saturday
        $payload = $this->renderer->render(collect([$game]), new DiscordDigestContext(locale: 'en'));

        $this->assertSame('Saturday 15 Aug', $payload->toArray()['embeds'][0]['title']);
    }

    #[Test]
    public function game_with_null_date_time_lands_in_an_undated_bucket()
    {
        $game = $this->makeGameOn('2026-08-15 19:00:00');
        $undated = Game::factory()->make(['id' => Str::uuid()->toString(), 'date_time' => null]);

        $payload = $this->renderer->render(collect([$undated, $game]), $this->context());

        $titles = array_column($payload->toArray()['embeds'], 'title');
        $this->assertContains('Undated', $titles);
    }

    // ── Empty state ──────────────────────────────────────

    #[Test]
    public function empty_collection_renders_a_single_empty_state_embed()
    {
        $payload = $this->renderer->render(collect(), $this->context());

        $embeds = $payload->toArray()['embeds'];
        $this->assertCount(1, $embeds);
        $this->assertSame('No public events scheduled', $embeds[0]['title']);
        $this->assertSame(DiscordDigestRenderer::COLOR_EMPTY, $embeds[0]['color']);
        $this->assertStringContainsString('next two weeks', $embeds[0]['description']);
    }

    #[Test]
    public function empty_state_payload_is_a_valid_webhook_payload()
    {
        $payload = $this->renderer->render(collect(), $this->context());

        $this->assertInstanceOf(DiscordWebhookPayload::class, $payload);
        $array = $payload->toArray();
        $this->assertArrayHasKey('embeds', $array);
        $this->assertArrayNotHasKey('content', $array);
    }

    // ── Purity (MEM917) ──────────────────────────────────

    #[Test]
    public function renderer_issuing_no_database_queries()
    {
        $venue = $this->makeVenue();
        $system = $this->makeSystem();
        $game = $this->makeGameOn('2026-08-15 19:00:00', venue: $venue, systems: collect([$system]));

        DB::enableQueryLog();
        $this->renderer->render(collect([$game]), new DiscordDigestContext(approvedCounts: [(string) $game->id => 2]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'DiscordDigestRenderer must not issue any database queries.');
    }

    #[Test]
    public function renderer_degrades_gracefully_when_relations_are_not_loaded()
    {
        // A game with no relations set must not lazy-load (which would query)
        // or throw — it simply omits the dependent segments.
        $game = $this->makeGameOn('2026-08-15 19:00:00', venue: null, systems: null);

        $payload = $this->renderer->render(collect([$game]), $this->context());

        $embed = $payload->toArray()['embeds'][0];
        $this->assertCount(1, $embed['fields']);
        $this->assertSame('Online / no venue', $embed['fields'][0]['name']);
        $this->assertNotEmpty($embed['fields'][0]['value']);
    }

    // ── Embed-limit enforcement (the highest-risk seam) ─

    #[Test]
    public function never_exceeds_ten_embeds_per_message()
    {
        // 12 distinct dates, each one game → would be 12 embeds without the cap.
        $games = collect();
        for ($d = 1; $d <= 12; $d++) {
            $date = sprintf('2026-08-%02d 19:00:00', $d);
            $games->push($this->makeGameOn($date));
        }

        $embeds = $this->renderer->render($games, $this->context())->toArray()['embeds'];

        $this->assertLessThanOrEqual(
            DiscordDigestRenderer::MAX_EMBEDS,
            count($embeds),
            'Digest must never exceed the 10-embed Discord cap.',
        );
    }

    #[Test]
    public function overflow_appends_a_see_full_calendar_note_embed()
    {
        $games = collect();
        for ($d = 1; $d <= 12; $d++) {
            $date = sprintf('2026-08-%02d 19:00:00', $d);
            $games->push($this->makeGameOn($date));
        }

        $embeds = $this->renderer->render($games, $this->context())->toArray()['embeds'];

        $lastEmbed = end($embeds);
        $this->assertSame('More events ahead', $lastEmbed['title']);
        $this->assertStringContainsString('see roundup for the full', $lastEmbed['description']);
    }

    #[Test]
    public function never_exceeds_twenty_five_fields_per_embed()
    {
        // 30 distinct venues on one date → would be 30 fields without the cap.
        $games = collect();
        for ($v = 1; $v <= 30; $v++) {
            $venue = $this->makeVenue(['id' => "loc-{$v}", 'name' => "Venue {$v}"]);
            $games->push($this->makeGameOn('2026-08-15 19:00:00', venue: $venue, name: "Game {$v}"));
        }

        $embeds = $this->renderer->render($games, $this->context())->toArray()['embeds'];

        foreach ($embeds as $embed) {
            if (! isset($embed['fields'])) {
                continue;
            }
            $this->assertLessThanOrEqual(
                DiscordDigestRenderer::MAX_FIELDS_PER_EMBED,
                count($embed['fields']),
                'No embed may exceed the 25-field Discord cap.',
            );
        }
    }

    #[Test]
    public function busy_date_splits_across_continued_embeds()
    {
        $games = collect();
        for ($v = 1; $v <= 30; $v++) {
            $venue = $this->makeVenue(['id' => "loc-{$v}", 'name' => "Venue {$v}"]);
            $games->push($this->makeGameOn('2026-08-15 19:00:00', venue: $venue, name: "Game {$v}"));
        }

        $embeds = $this->renderer->render($games, $this->context())->toArray()['embeds'];

        // First embed is the date; a continued embed carries the overflow.
        $titles = array_column($embeds, 'title');
        $this->assertTrue(
            collect($titles)->contains(fn ($t) => str_contains((string) $t, 'continued')),
            'A busy date must split into a (continued) embed.',
        );
    }

    #[Test]
    public function field_value_never_exceeds_discord_char_limit()
    {
        // One venue with many long-named games → value would overflow without
        // the field-value cap.
        $venue = $this->makeVenue(['id' => 'loc-1', 'name' => 'The Dragon']);
        $games = collect();
        for ($i = 1; $i <= 60; $i++) {
            $games->push($this->makeGameOn(
                '2026-08-15 19:00:00',
                venue: $venue,
                name: str_repeat('Game Name With Substantial Length ', 3).$i,
                overrides: ['max_players' => 6],
            ));
        }

        $embeds = $this->renderer->render($games, $this->context())->toArray()['embeds'];

        foreach ($embeds as $embed) {
            foreach ($embed['fields'] ?? [] as $field) {
                $this->assertLessThanOrEqual(
                    DiscordDigestRenderer::MAX_FIELD_VALUE_CHARS,
                    mb_strlen($field['value']),
                    'Field value must never exceed the 1024-char Discord cap.',
                );
            }
        }
    }

    #[Test]
    public function truncated_field_value_carries_a_more_marker()
    {
        $venue = $this->makeVenue(['id' => 'loc-1', 'name' => 'The Dragon']);
        $games = collect();
        for ($i = 1; $i <= 60; $i++) {
            $games->push($this->makeGameOn(
                '2026-08-15 19:00:00',
                venue: $venue,
                name: str_repeat('LongGameName ', 6).$i,
                overrides: ['max_players' => 6],
            ));
        }

        $embeds = $this->renderer->render($games, $this->context())->toArray()['embeds'];

        $allValues = collect($embeds)
            ->flatMap(fn (array $e) => array_map(fn (array $f) => $f['value'], $e['fields'] ?? []))
            ->implode("\n");

        $this->assertStringContainsString('more)', $allValues, 'Truncation must record the hidden count.');
    }

    // ── Structural validity ──────────────────────────────

    #[Test]
    public function every_embed_has_a_color_and_footer()
    {
        $venue = $this->makeVenue(['name' => 'The Dragon']);
        $game = $this->makeGameOn('2026-08-15 19:00:00', venue: $venue);

        $embeds = $this->renderer->render(collect([$game]), $this->context())->toArray()['embeds'];

        foreach ($embeds as $embed) {
            $this->assertArrayHasKey('color', $embed);
            $this->assertSame(['text' => 'roundup · cross-community tabletop'], $embed['footer']);
        }
    }

    #[Test]
    public function venue_fields_are_non_inline()
    {
        $venue = $this->makeVenue(['name' => 'The Dragon']);
        $game = $this->makeGameOn('2026-08-15 19:00:00', venue: $venue);

        $embeds = $this->renderer->render(collect([$game]), $this->context())->toArray()['embeds'];

        foreach ($embeds as $embed) {
            foreach ($embed['fields'] ?? [] as $field) {
                $this->assertFalse($field['inline'], 'Venue listing fields must be non-inline.');
            }
        }
    }

    #[Test]
    public function render_returns_a_postable_webhook_payload()
    {
        $game = $this->makeGameOn('2026-08-15 19:00:00');

        $payload = $this->renderer->render(collect([$game]), $this->context());

        $this->assertInstanceOf(DiscordWebhookPayload::class, $payload);
        $array = $payload->toArray();
        $this->assertIsArray($array['embeds']);
        $this->assertNotEmpty($array['embeds']);
    }

    // ── Fixture builders (pure — factory make(), no DB) ─

    private function context(array $approvedCounts = []): DiscordDigestContext
    {
        return new DiscordDigestContext(approvedCounts: $approvedCounts, appUrl: 'https://roundup.test');
    }

    private function lineFor(Game $game, DiscordDigestContext $context): string
    {
        $embeds = $this->renderer->render(collect([$game]), $context)->toArray()['embeds'];

        return $embeds[0]['fields'][0]['value'];
    }

    private function makeGameOn(
        string $dateTime,
        ?Location $venue = null,
        ?Collection $systems = null,
        string $name = 'Test Game',
        array $overrides = [],
    ): Game {
        $game = Game::factory()->make(array_merge([
            'id' => Str::uuid()->toString(),
            'name' => ['en' => $name],
            'date_time' => $dateTime,
            'min_players' => 2,
            'max_players' => 5,
            'status' => 'scheduled',
            'visibility' => 'public',
            'location_id' => $venue?->id,
            'owner_id' => Str::uuid()->toString(),
        ], $overrides));

        if ($venue !== null) {
            $game->setRelation('linkedLocation', $venue);
        }
        if ($systems !== null) {
            $game->setRelation('gameSystems', $systems);
        } elseif ($systems === null) {
            // Mirror the publisher's eager-load by defaulting to an empty
            // loaded collection so the "no systems" path is explicit.
            $game->setRelation('gameSystems', new EloquentCollection);
        }

        return $game;
    }

    private function makeVenue(array $overrides = []): Location
    {
        return Location::factory()->make(array_merge([
            'id' => 'loc-default',
            'name' => 'Test Venue',
        ], $overrides));
    }

    private function makeSystem(array $overrides = []): GameSystem
    {
        return GameSystem::factory()->make(array_merge([
            'id' => Str::uuid()->toString(),
            'name' => ['en' => 'Test System'],
        ], $overrides));
    }
}
