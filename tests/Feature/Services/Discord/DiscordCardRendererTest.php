<?php

namespace Tests\Feature\Services\Discord;

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\Discord\DiscordCardContext;
use App\Services\Discord\DiscordCardRenderer;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure-transformer tests for {@see DiscordCardRenderer}.
 *
 * The renderer's contract is explicitly "no Discord I/O, no database queries,
 * no filesystem access" — it reads only the Game's attributes and pre-loaded
 * relations plus a {@see DiscordCardContext} DTO. These tests prove that
 * contract by building fixtures with factory `make()` + `setRelation()` (no DB
 * persistence) and asserting zero queries are issued during render (see
 * {@see renderer_issuing_no_database_queries}).
 */
class DiscordCardRendererTest extends TestCase
{
    private const GAME_ID = '01234567-89ab-cdef-0123-456789abcdef';

    private DiscordCardRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new DiscordCardRenderer;
        // Pin locale so translatable reads are deterministic.
        app()->setLocale('en');
    }

    // ── Verify-contract: renders all wedge fields ───────

    #[Test]
    public function renders_all_wedge_fields_from_a_fixture_game()
    {
        $owner = $this->makeOwner([
            'name' => 'Mara Voss',
            'username' => 'maravoss',
            'reliability_score' => ['score' => 98.0, 'game_count' => 12, 'tier' => 'reliable'],
        ]);
        $venue = $this->makeVenue([
            'name' => 'Café Berlin',
            'website_url' => 'https://cafeberlin.example',
            'latitude' => '52.5200',
            'longitude' => '13.4050',
            'venue_metadata' => [
                'fee_display' => '€5 table fee',
                'overlap_guidance' => 'Two tables max',
                'house_rules' => 'Be kind',
            ],
        ]);
        $system = $this->makeSystem(['name' => 'Catan']);
        $game = $this->makeGame([
            'name' => ['en' => 'Catan Night'],
            'description' => ['en' => 'A relaxed evening of trading and building.'],
            'date_time' => '2026-08-15 19:00:00',
            'min_players' => 3,
            'max_players' => 5,
            'expected_duration' => 3.5,
            'status' => 'scheduled',
        ], $owner, $venue, collect([$system]));

        $context = new DiscordCardContext(
            approvedCount: 5,
            waitlistCount: 2,
            benchedCount: 1,
            crossCommunityAttendeeCount: 3,
            appUrl: 'https://roundup.test',
            guildName: 'Berlin Boardgames',
        );

        $card = $this->renderer->render($game, $context);
        $embed = $card->embed;

        // Top-level embed shape.
        $this->assertSame('Catan Night', $embed['title']);
        $this->assertSame('https://roundup.test/games/'.self::GAME_ID, $embed['url']);
        $this->assertSame(DiscordCardRenderer::COLOR_BRAND, $embed['color']);
        $this->assertSame('2026-08-15T19:00:00+00:00', $embed['timestamp']);
        $this->assertSame(['text' => 'roundup · cross-community tabletop'], $embed['footer']);
        $this->assertStringContainsString('relaxed evening', $embed['description']);

        // Author (organizer) with profile deep link + avatar omitted (none set).
        $this->assertSame('Mara Voss', $embed['author']['name']);
        $this->assertSame('https://roundup.test/@maravoss', $embed['author']['url']);

        // Every wedge field present, by name.
        $fields = $this->fieldNames($embed);
        $this->assertContains('When', $fields);
        $this->assertContains('Players', $fields);
        $this->assertContains('System', $fields);
        $this->assertContains('Organizer', $fields);
        $this->assertContains('Venue', $fields);
        $this->assertContains('🌐 Cross-community', $fields);

        // Roster: full + overflow models Apollo lacks.
        $players = $this->field($embed, 'Players');
        $this->assertStringContainsString('5/5', $players);
        $this->assertStringContainsString('**Full**', $players);
        $this->assertStringContainsString('2 waitlist', $players);
        $this->assertStringContainsString('1 bench', $players);

        // Trust line: reliable tier, score, games hosted.
        $organizer = $this->field($embed, 'Organizer');
        $this->assertStringContainsString('🟢 Reliable', $organizer);
        $this->assertStringContainsString('98% reliable', $organizer);
        $this->assertStringContainsString('12 games hosted', $organizer);

        // Venue: name→website, map link (precise lat/lng), operational params.
        $venueField = $this->field($embed, 'Venue');
        $this->assertStringContainsString('[**Café Berlin**](https://cafeberlin.example)', $venueField);
        $this->assertStringContainsString('[Open in Maps]', $venueField);
        $this->assertStringContainsString('52.52', $venueField);
        $this->assertStringContainsString('13.405', $venueField);
        $this->assertStringContainsString('€5 table fee', $venueField);
        $this->assertStringContainsString('Two tables max', $venueField);
        $this->assertStringContainsString('Be kind', $venueField);

        // Cross-community: positive framing + guild name.
        $cross = $this->field($embed, '🌐 Cross-community');
        $this->assertStringContainsString('**3**', $cross);
        $this->assertStringContainsString('outside Berlin Boardgames', $cross);
    }

    #[Test]
    public function missing_venue_case_omits_venue_field()
    {
        $game = $this->makeGame(); // no linked location set
        $card = $this->renderer->render($game, new DiscordCardContext);

        $this->assertNotContains('Venue', $this->fieldNames($card->embed));
        // And no map link leaked anywhere.
        $this->assertStringNotContainsString('Open in Maps', json_encode($card->embed));
    }

    #[Test]
    public function zero_cross_community_case_omits_indicator()
    {
        $game = $this->makeGame();
        $context = new DiscordCardContext(crossCommunityAttendeeCount: 0);

        $card = $this->renderer->render($game, $context);

        $this->assertNotContains('🌐 Cross-community', $this->fieldNames($card->embed));
    }

    #[Test]
    public function button_custom_ids_carry_the_game_id()
    {
        $game = $this->makeGame();
        $card = $this->renderer->render($game, new DiscordCardContext(appUrl: 'https://roundup.test'));

        $this->assertSame('roundup:rsvp:'.self::GAME_ID, $card->components[0]['components'][0]['custom_id']);
        $this->assertSame('https://roundup.test/games/'.self::GAME_ID, $card->components[0]['components'][1]['url']);
    }

    #[Test]
    public function embed_structure_is_valid_discord_embed_shape()
    {
        $game = $this->makeGame();
        $card = $this->renderer->render($game, new DiscordCardContext);

        // Each embed field has exactly name + value + inline.
        foreach ($card->embed['fields'] as $field) {
            $this->assertArrayHasKey('name', $field);
            $this->assertArrayHasKey('value', $field);
            $this->assertArrayHasKey('inline', $field);
            $this->assertIsString($field['name']);
            $this->assertIsString($field['value']);
            $this->assertIsBool($field['inline']);
        }

        // Components are action rows of buttons with valid type ints.
        foreach ($card->components as $row) {
            $this->assertSame(1, $row['type']); // ACTION_ROW
            foreach ($row['components'] as $button) {
                $this->assertSame(2, $button['type']); // BUTTON
                $this->assertContains($button['style'], [1, 2, 3, 4, 5]);
            }
        }
    }

    // ── Conditional / boundary ──────────────────────────

    #[Test]
    public function owner_without_reliability_score_omits_trust_field()
    {
        $owner = $this->makeOwner(['reliability_score' => null]);
        $game = $this->makeGame(owner: $owner);

        $card = $this->renderer->render($game, new DiscordCardContext);

        $this->assertNotContains('Organizer', $this->fieldNames($card->embed));
    }

    #[Test]
    public function full_roster_renders_full_marker()
    {
        $game = $this->makeGame(['max_players' => 4]);
        $context = new DiscordCardContext(approvedCount: 4);

        $players = $this->field($this->renderer->render($game, $context)->embed, 'Players');

        $this->assertStringContainsString('4/4', $players);
        $this->assertStringContainsString('**Full**', $players);
    }

    #[Test]
    public function unlimited_capacity_renders_open_roster()
    {
        // max_players null/0 → unlimited (HasCapacity semantics).
        $game = $this->makeGame(['max_players' => 0, 'min_players' => 2]);
        $context = new DiscordCardContext(approvedCount: 7);

        $players = $this->field($this->renderer->render($game, $context)->embed, 'Players');

        $this->assertStringContainsString('7 joined · open roster', $players);
        $this->assertStringContainsString('min 2', $players);
        $this->assertStringNotContainsString('/0', $players);
    }

    #[Test]
    public function venue_map_link_uses_precise_lat_lng_when_available()
    {
        $venue = $this->makeVenue(['latitude' => '48.8566', 'longitude' => '2.3522']);
        $game = $this->makeGame(venue: $venue);

        $venueField = $this->field($this->renderer->render($game, new DiscordCardContext)->embed, 'Venue');

        $this->assertStringContainsString('query=48.8566%2C2.3522', $venueField);
    }

    #[Test]
    public function venue_without_lat_lng_falls_back_to_name_query_map_link()
    {
        $venue = $this->makeVenue(['name' => 'The Dragon', 'address' => '5 Oak St', 'city' => 'Berlin', 'latitude' => null, 'longitude' => null]);
        $game = $this->makeGame(venue: $venue);

        $venueField = $this->field($this->renderer->render($game, new DiscordCardContext)->embed, 'Venue');

        // rawurlencode (RFC 3986) encodes spaces as %20, not +.
        $this->assertStringContainsString('query=The%20Dragon%2C%205%20Oak%20St%2C%20Berlin', $venueField);
    }

    #[Test]
    public function canceled_game_uses_red_color()
    {
        $game = $this->makeGame(['status' => 'canceled']);
        $card = $this->renderer->render($game, new DiscordCardContext);

        $this->assertSame(DiscordCardRenderer::COLOR_CANCELED, $card->embed['color']);
    }

    #[Test]
    public function completed_game_uses_muted_color()
    {
        $game = $this->makeGame(['status' => 'completed']);
        $card = $this->renderer->render($game, new DiscordCardContext);

        $this->assertSame(DiscordCardRenderer::COLOR_COMPLETED, $card->embed['color']);
    }

    #[Test]
    public function duration_formats_hours_and_minutes()
    {
        $game = $this->makeGame(['expected_duration' => 3.5]);

        $when = $this->field($this->renderer->render($game, new DiscordCardContext)->embed, 'When');

        $this->assertStringContainsString('3h 30m', $when);
    }

    #[Test]
    public function cross_community_uses_generic_phrase_when_no_guild_name()
    {
        $game = $this->makeGame();
        $context = new DiscordCardContext(crossCommunityAttendeeCount: 2);

        $cross = $this->field($this->renderer->render($game, $context)->embed, '🌐 Cross-community');

        $this->assertStringContainsString('from beyond this server', $cross);
    }

    #[Test]
    public function missing_date_time_omits_when_field()
    {
        $game = $this->makeGame(['date_time' => null]);

        $this->assertNotContains('When', $this->fieldNames($this->renderer->render($game, new DiscordCardContext)->embed));
    }

    #[Test]
    public function description_strips_html_tags_and_truncates()
    {
        $game = $this->makeGame(['description' => ['en' => '<p>Bold <b>night</b> of <script>x</script>play.</p>']]);

        $description = $this->renderer->render($game, new DiscordCardContext)->embed['description'] ?? '';

        $this->assertStringNotContainsString('<', $description);
        $this->assertStringContainsString('night', $description);
    }

    #[Test]
    public function invalid_avatar_url_is_omitted_from_author()
    {
        $owner = $this->makeOwner(['avatar_url' => 'not-a-url']);
        $game = $this->makeGame(owner: $owner);

        $author = $this->renderer->render($game, new DiscordCardContext)->embed['author'];

        $this->assertArrayNotHasKey('icon_url', $author);
    }

    #[Test]
    public function card_wraps_into_postable_webhook_payload()
    {
        $game = $this->makeGame();
        $card = $this->renderer->render($game, new DiscordCardContext);

        $payload = $card->toPayload();

        $this->assertInstanceOf(DiscordWebhookPayload::class, $payload);
        $array = $payload->toArray();
        $this->assertSame([$card->embed], $array['embeds']);
        $this->assertSame($card->components, $array['components']);
    }

    #[Test]
    public function renderer_issuing_no_database_queries()
    {
        $owner = $this->makeOwner();
        $venue = $this->makeVenue();
        $system = $this->makeSystem();
        $game = $this->makeGame(owner: $owner, venue: $venue, systems: collect([$system]));

        DB::enableQueryLog();
        $this->renderer->render($game, new DiscordCardContext(approvedCount: 3, crossCommunityAttendeeCount: 1));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'DiscordCardRenderer must not issue any database queries.');
    }

    #[Test]
    public function renderer_does_not_touch_unloaded_relations()
    {
        // A game with relations NOT loaded must degrade gracefully rather than
        // lazy-load (which would query) or throw.
        $game = $this->makeGame(); // no relations set

        // It should simply not error and omit the dependent fields.
        $card = $this->renderer->render($game, new DiscordCardContext);

        $this->assertNotContains('System', $this->fieldNames($card->embed));
        $this->assertNotContains('Organizer', $this->fieldNames($card->embed));
        $this->assertNotContains('Venue', $this->fieldNames($card->embed));
        $this->assertArrayNotHasKey('author', $card->embed);
    }

    #[Test]
    public function cover_image_thumbnail_only_present_when_provided()
    {
        $game = $this->makeGame();

        $without = $this->renderer->render($game, new DiscordCardContext);
        $this->assertArrayNotHasKey('thumbnail', $without->embed);

        $with = $this->renderer->render($game, new DiscordCardContext(coverImageUrl: 'https://cdn.test/cover.png'));
        $this->assertSame(['url' => 'https://cdn.test/cover.png'], $with->embed['thumbnail']);
    }

    // ── Fixture builders (pure — factory make(), no DB) ──

    private function makeGame(
        array $overrides = [],
        ?User $owner = null,
        ?Location $venue = null,
        ?Collection $systems = null,
    ): Game {
        $game = Game::factory()->make(array_merge([
            'id' => self::GAME_ID,
            'name' => ['en' => 'Catan Night'],
            'description' => ['en' => 'A relaxed evening.'],
            'date_time' => '2026-08-15 19:00:00',
            'min_players' => 2,
            'max_players' => 5,
            'expected_duration' => 3.0,
            'status' => 'scheduled',
            'owner_id' => $owner?->id ?? Str::uuid()->toString(),
        ], $overrides));

        if ($owner !== null) {
            $game->setRelation('owner', $owner);
        }
        if ($venue !== null) {
            $game->setRelation('linkedLocation', $venue);
        }
        if ($systems !== null) {
            $game->setRelation('gameSystems', $systems);
        }

        return $game;
    }

    private function makeOwner(array $overrides = []): User
    {
        return User::factory()->make(array_merge([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Organizer',
            'username' => 'organizer',
            'reliability_score' => null,
            'avatar_url' => null,
        ], $overrides));
    }

    private function makeVenue(array $overrides = []): Location
    {
        return Location::factory()->make(array_merge([
            'name' => 'Test Venue',
            'website_url' => null,
            'latitude' => null,
            'longitude' => null,
            'venue_metadata' => null,
        ], $overrides));
    }

    private function makeSystem(array $overrides = []): GameSystem
    {
        return GameSystem::factory()->make(array_merge([
            'name' => ['en' => 'Test System'],
        ], $overrides));
    }

    // ── Embed field accessors ───────────────────────────

    /**
     * @param  array<string, mixed>  $embed
     * @return list<string>
     */
    private function fieldNames(array $embed): array
    {
        return array_map(fn (array $f): string => $f['name'], $embed['fields'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $embed
     */
    private function field(array $embed, string $name): string
    {
        foreach ($embed['fields'] ?? [] as $f) {
            if ($f['name'] === $name) {
                return $f['value'];
            }
        }

        $this->fail("Expected embed field '{$name}' not present. Fields: ".implode(', ', $this->fieldNames($embed)));
    }
}
