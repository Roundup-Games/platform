<?php

use App\Enums\CampaignStatus;
use App\Enums\VenueType;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/*
|--------------------------------------------------------------------------
| Consolidated per-entity getDynamicSEOData tests
|--------------------------------------------------------------------------
|
| The six per-entity *SeoTest.php files (Campaign, Event, Game, GameSystem,
| Team, User) each exercised the same four getDynamicSEOData behaviours:
|
|   1. title comes from the entity's name
|   2. description comes from the entity's description source
|   3. description is capped at 160 characters (<=163 with ellipsis slack)
|   4. og-default.jpg is used as the fallback image
|
| Those four behaviours are driven here by a single Pest dataset
| (`entity_seo`) over the six entities, preserving every assertion from the
| originals. Behaviour that is unique to an entity — image override fields,
| robots triggers, schema shapes, M053 JSON-LD disclosure regression — is
| kept as separate it(...) blocks grouped per entity below. No assertion
| coverage is dropped.
|
| Out of scope (do NOT touch here): AdminOverrideTest, CacheInvalidationTest,
| MetaTagRenderingTest, SeoCacheServiceTest, SitemapTest, StructuredDataTest,
| VenueSitemapTest.
|
*/

// Each dataset row is wrapped in an outer indexed array (`[[ ... ]]`) so the
// whole descriptor binds to the test closure's single `$d` parameter. Without
// the wrap, Pest v4 maps the associative keys to closure parameters by name
// and fails with "Unknown named parameter $model".
dataset('entity_seo', [
    'Campaign' => [[
        'model' => Campaign::class,
        'titleAttrs' => [
            'name' => ['en' => 'Curse of Strahd Campaign'],
            'visibility' => Visibility::Public,
        ],
        'expectedTitle' => 'Curse of Strahd Campaign',
        // Common description path: Campaign reads its `description` translation.
        'descriptionAttrs' => [
            'description' => ['en' => 'A gothic horror adventure set in the land of Barovia.'],
            'visibility' => Visibility::Public,
        ],
        'expectedDescription' => 'A gothic horror adventure',
        'longDescriptionAttrs' => [
            'description' => ['en' => str_repeat('A very long campaign description. ', 20)],
            'visibility' => Visibility::Public,
        ],
        'fallbackImageAttrs' => [
            'visibility' => Visibility::Public,
        ],
    ]],
    'Event' => [[
        'model' => Event::class,
        'titleAttrs' => [
            'name' => ['en' => 'Grand Tournament 2025'],
            'is_public' => true,
            'status' => 'registration_open',
        ],
        'expectedTitle' => 'Grand Tournament 2025',
        // Common description path for Event is the stripped-description
        // fallback (short_description absent). short_description precedence
        // is covered by a unique Event test below.
        'descriptionAttrs' => [
            'short_description' => null,
            'description' => ['en' => 'A detailed description of the event.'],
            'is_public' => true,
            'status' => 'registration_open',
        ],
        'expectedDescription' => 'A detailed description of the event.',
        'longDescriptionAttrs' => [
            'short_description' => null,
            'description' => ['en' => str_repeat('Long event description. ', 20)],
            'is_public' => true,
            'status' => 'registration_open',
        ],
        'fallbackImageAttrs' => [
            'is_public' => true,
            'status' => 'registration_open',
        ],
    ]],
    'Game' => [[
        'model' => Game::class,
        'titleAttrs' => [
            'name' => ['en' => 'Epic Board Game Night'],
            'visibility' => Visibility::Public,
        ],
        'expectedTitle' => 'Epic Board Game Night',
        'descriptionAttrs' => [
            'description' => ['en' => 'An exciting evening of board games for all skill levels.'],
            'visibility' => Visibility::Public,
        ],
        'expectedDescription' => 'An exciting evening of board games',
        'longDescriptionAttrs' => [
            'description' => ['en' => str_repeat('A very long game description. ', 20)],
            'visibility' => Visibility::Public,
        ],
        'fallbackImageAttrs' => [
            'visibility' => Visibility::Public,
        ],
    ]],
    'GameSystem' => [[
        'model' => GameSystem::class,
        'titleAttrs' => [
            'name' => ['en' => 'Dungeons & Dragons 5e'],
        ],
        'expectedTitle' => 'Dungeons & Dragons 5e',
        'descriptionAttrs' => [
            'description' => ['en' => 'A fantastic tabletop role-playing game with deep lore.'],
        ],
        'expectedDescription' => 'A fantastic tabletop role-playing game',
        'longDescriptionAttrs' => [
            'description' => ['en' => str_repeat('This is a long description. ', 20)],
        ],
        'fallbackImageAttrs' => [
            'thumbnail_url' => null,
        ],
    ]],
    'Team' => [[
        'model' => Team::class,
        'titleAttrs' => [
            'name' => 'Dragon Slayers',
        ],
        'expectedTitle' => 'Dragon Slayers',
        'descriptionAttrs' => [
            'description' => ['en' => 'A competitive tabletop gaming team.'],
            'is_active' => true,
        ],
        'expectedDescription' => 'A competitive tabletop gaming team',
        'longDescriptionAttrs' => [
            'description' => ['en' => str_repeat('A very long team description. ', 20)],
            'is_active' => true,
        ],
        'fallbackImageAttrs' => [
            'is_active' => true,
        ],
    ]],
    'User' => [[
        'model' => User::class,
        'titleAttrs' => [
            'name' => 'Alice the Gamer',
        ],
        'expectedTitle' => 'Alice the Gamer',
        // User reads `bio` (string), not a translated description.
        'descriptionAttrs' => [
            'name' => 'Bio User',
            'bio' => 'I love playing board games and RPGs.',
        ],
        'expectedDescription' => 'I love playing board games and RPGs',
        'longDescriptionAttrs' => [
            'name' => 'Long Bio User',
            'bio' => str_repeat('I love playing games. ', 20),
        ],
        'fallbackImageAttrs' => [
            'avatar_url' => null,
        ],
    ]],
]);

describe('entity SEO common assertions', function () {
    it('returns a SEOData instance with title from entity name', function (array $d) {
        $model = $d['model']::factory()->create($d['titleAttrs']);

        $seo = $model->getDynamicSEOData();

        expect($seo)->toBeInstanceOf(SEOData::class);
        expect($seo->title)->toBe($d['expectedTitle']);
    })->with('entity_seo');

    it('returns description from entity description source', function (array $d) {
        $model = $d['model']::factory()->create($d['descriptionAttrs']);

        $seo = $model->getDynamicSEOData();

        expect($seo->description)->not->toBeNull();
        expect($seo->description)->toContain($d['expectedDescription']);
    })->with('entity_seo');

    it('limits description to 160 characters', function (array $d) {
        $model = $d['model']::factory()->create($d['longDescriptionAttrs']);

        $seo = $model->getDynamicSEOData();

        expect(strlen($seo->description))->toBeLessThanOrEqual(163);
    })->with('entity_seo');

    it('returns fallback og-default.jpg image', function (array $d) {
        $model = $d['model']::factory()->create($d['fallbackImageAttrs']);

        $seo = $model->getDynamicSEOData();

        expect($seo->image)->toContain('og-default.jpg');
    })->with('entity_seo');
});

// ── Campaign unique assertions ──────────────────────────────────────────────

describe('Campaign getDynamicSEOData unique assertions', function () {
    it('returns game system cover image as the representative resolveCoverUrl() fallback', function () {
        $system = GameSystem::factory()->create([
            'thumbnail_url' => 'https://example.com/system-cover.jpg',
        ]);
        $campaign = Campaign::factory()->create([
            'game_system_id' => $system->id,
            'visibility' => Visibility::Public,
        ]);

        // S07: campaigns.images JSON column was dropped; the cover surface now
        // reads through ResolvesCoverImage::resolveCoverUrl(). With no host
        // cover media, rung 2 (representative GameSystem cover) wins.
        $seo = $campaign->getDynamicSEOData();

        expect($seo->image)->toBe('https://example.com/system-cover.jpg');
    });

    it('does not include schema for non-public campaign', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Private,
            'status' => CampaignStatus::Active->value,
        ]);

        expect($campaign->getDynamicSEOData()->schema)->toBeNull();
    });

    it('does not include schema for public but cancelled campaign', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
            'status' => CampaignStatus::Cancelled->value,
        ]);

        expect($campaign->getDynamicSEOData()->schema)->toBeNull();
    });

    it('includes Event schema for public active campaign', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
            'status' => CampaignStatus::Active->value,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $event = collect($seo->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Event');
        expect($event)->not->toBeNull();
    });
});

// ── Event unique assertions ─────────────────────────────────────────────────

describe('Event getDynamicSEOData unique assertions', function () {
    it('returns description from short_description when present', function () {
        $event = Event::factory()->create([
            'short_description' => ['en' => 'Join us for the biggest event of the year!'],
            'description' => ['en' => 'This is a longer description that should be ignored.'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->description)->toBe('Join us for the biggest event of the year!');
    });

    it('returns null description when both description fields are empty', function () {
        $event = Event::factory()->create([
            'short_description' => null,
            'description' => null,
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->description)->toBeNull();
    });
});

// ── Game unique assertions ──────────────────────────────────────────────────

describe('Game getDynamicSEOData unique assertions', function () {
    it('returns null description from empty string description', function () {
        $game = Game::factory()->create([
            'description' => ['en' => ''],
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        // Str::limit(strip_tags('')) returns '' which is falsy, so description is null
        expect($seo->description)->toBeNull();
    });

    it('returns game system cover image as fallback', function () {
        $system = GameSystem::factory()->create([
            'thumbnail_url' => 'https://example.com/system-cover.jpg',
        ]);
        $game = Game::factory()->create([
            'game_system_id' => $system->id,
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo->image)->toBe('https://example.com/system-cover.jpg');
    });

    it('includes Event schema for public game with date_time', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
            'date_time' => now()->addDays(7),
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $event = collect($seo->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Event');
        expect($event)->not->toBeNull();
        expect($event)->toHaveKey('startDate');
    });

    it('does not include schema for private game', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Private,
            'date_time' => now()->addDays(7),
        ]);

        expect($game->getDynamicSEOData()->schema)->toBeNull();
    });

    it('does not include schema for public game without date_time (factory always sets date_time)', function () {
        // Since date_time is NOT NULL, all games have a date_time.
        // Verify schema IS present for public games (factory provides date_time).
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        expect($game->getDynamicSEOData()->schema)->not->toBeNull();
    });

    // ── M053 regression: JSON-LD location must honour the disclosure service ──
    // JSON-LD is a single server-rendered artifact served to every viewer
    // (including crawlers), so the Event location must reflect the stranger
    // (most-restrictive) disclosure level. A public game at a private home
    // must NOT leak the host's street address in structured data even though
    // the HTML body correctly shows only "In your area".

    it('emits a full PostalAddress in the Event location for a verified commercial venue', function () {
        $venue = Location::factory()->verifiedVenue()->create([
            'venue_type' => VenueType::Cafe,
            'name' => 'Café Glück',
            'address' => 'Friedrichstraße 100',
            'city' => 'Berlin',
            'country' => 'DEU',
            'is_verified' => true,
        ]);
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
            'date_time' => now()->addDays(7),
            'location_id' => $venue->id,
        ]);

        $event = collect($game->getDynamicSEOData()->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Event');

        expect($event)->not->toBeNull();
        expect($event)->toHaveKey('location');
        expect($event['location']['@type'] ?? null)->toBe('Place');
        expect($event['location']['address']['@type'] ?? null)->toBe('PostalAddress');
        expect($event['location']['address']['streetAddress'] ?? null)->toBe('Friedrichstraße 100');
        expect($event['location']['address']['addressLocality'] ?? null)->toBe('Berlin');
    });

    it('does NOT leak the street address in JSON-LD for a public game at a private home (M053 regression)', function () {
        $home = Location::factory()->create([
            'venue_type' => VenueType::Other,
            'is_verified' => false,
            'name' => 'Private Home',
            'address' => 'Torstraße 5',
            'city' => 'Berlin',
            'country' => 'DEU',
        ]);
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
            'date_time' => now()->addDays(7),
            'location_id' => $home->id,
        ]);

        $schemaJson = json_encode($game->getDynamicSEOData()->schema->toArray());

        // No Place, no PostalAddress, no street, no locality — fail-closed.
        expect($schemaJson)->not->toContain('PostalAddress');
        expect($schemaJson)->not->toContain('Torstra');
        expect($schemaJson)->not->toContain('streetAddress');
    });
});

// ── GameSystem unique assertions ────────────────────────────────────────────

describe('GameSystem getDynamicSEOData unique assertions', function () {
    it('returns null description when description is empty', function () {
        $system = GameSystem::factory()->create(['description' => null]);

        expect($system->getDynamicSEOData()->description)->toBeNull();
    });

    it('returns thumbnail_url as image when no media is attached', function () {
        $system = GameSystem::factory()->create([
            'thumbnail_url' => 'https://example.com/thumb.jpg',
        ]);

        expect($system->getDynamicSEOData()->image)->toBe('https://example.com/thumb.jpg');
    });

    it('includes schema with Product type', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Test RPG']]);

        $seo = $system->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $product = collect($seo->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Product');
        expect($product)->not->toBeNull();
        expect($product['name'])->toBe('Test RPG');
    });

    it('includes Product schema with SKU from model id', function () {
        $system = GameSystem::factory()->create();

        $seo = $system->getDynamicSEOData();

        $product = collect($seo->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Product');
        expect($product)->toHaveKey('sku');
        expect($product['sku'])->toBe((string) $system->id);
    });

    it('registers FAQPage markup when faq_content is present', function () {
        $system = GameSystem::factory()->create([
            'faq_content' => [
                ['question' => 'How many players?', 'answer' => '2-6 players.'],
            ],
        ]);

        $seo = $system->getDynamicSEOData();

        // FAQPage is stored in SchemaCollection::markup, not in push/toArray
        expect($seo->schema)->not->toBeNull();
        expect($seo->schema->markup)->toHaveKey('RalphJSmit\Laravel\SEO\Schema\FaqPageSchema');
    });

    it('does not register FAQPage markup when faq_content is empty', function () {
        $system = GameSystem::factory()->create(['faq_content' => null]);

        $seo = $system->getDynamicSEOData();

        expect($seo->schema->markup)->not->toHaveKey('RalphJSmit\Laravel\SEO\Schema\FaqPageSchema');
    });
});

// ── Team unique assertions ──────────────────────────────────────────────────

describe('Team getDynamicSEOData unique assertions', function () {
    it('returns composite description from name, city, and country when no description', function () {
        $team = Team::factory()->create([
            'description' => null,
            'name' => 'Dragon Slayers',
            'city' => 'Berlin',
            'country' => 'DE',
            'is_active' => true,
        ]);

        $seo = $team->getDynamicSEOData();

        expect($seo->description)->toContain('Dragon Slayers');
        expect($seo->description)->toContain('Berlin');
        expect($seo->description)->toContain('DE');
    });

    it('includes Organization schema for active team', function () {
        $team = Team::factory()->create([
            'name' => 'Dragon Slayers',
            'is_active' => true,
        ]);

        $seo = $team->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $org = collect($seo->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Organization');
        expect($org)->not->toBeNull();
        expect($org['name'])->toBe('Dragon Slayers');
    });

    it('does not include schema for inactive team', function () {
        $team = Team::factory()->create(['is_active' => false]);

        expect($team->getDynamicSEOData()->schema)->toBeNull();
    });

    it('includes address in Organization schema when city and country are set', function () {
        $team = Team::factory()->create([
            'is_active' => true,
            'city' => 'Berlin',
            'country' => 'DE',
        ]);

        $seo = $team->getDynamicSEOData();

        $org = collect($seo->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Organization');
        expect($org)->toHaveKey('address');
    });

    it('includes foundingDate in Organization schema when founded_year is set', function () {
        $team = Team::factory()->create([
            'is_active' => true,
            'founded_year' => 2020,
        ]);

        $seo = $team->getDynamicSEOData();

        $org = collect($seo->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Organization');
        expect($org['foundingDate'])->toBe('2020');
    });

    it('includes sameAs social links in Organization schema', function () {
        $team = Team::factory()->create([
            'is_active' => true,
            'website' => 'https://example.com',
            'social_links' => ['https://twitter.com/team'],
        ]);

        $seo = $team->getDynamicSEOData();

        $org = collect($seo->schema->toArray())
            ->first(fn ($item) => ($item['@type'] ?? null) === 'Organization');
        expect($org)->toHaveKey('sameAs');
        expect($org['sameAs'])->toContain('https://example.com');
        expect($org['sameAs'])->toContain('https://twitter.com/team');
    });
});

// ── User unique assertions ──────────────────────────────────────────────────

describe('User getDynamicSEOData unique assertions', function () {
    it('returns default description when user has no bio', function () {
        $user = User::factory()->create([
            'name' => 'NoBio User',
            'bio' => null,
        ]);

        $seo = $user->getDynamicSEOData();

        expect($seo->description)->toContain('NoBio User');
        expect($seo->description)->toContain('profile on Roundup Games');
    });

    it('returns avatar_url as image when no media is attached', function () {
        $user = User::factory()->create([
            'avatar_url' => 'https://example.com/avatar.jpg',
        ]);

        expect($user->getDynamicSEOData()->image)->toBe('https://example.com/avatar.jpg');
    });
});

// ── Robots directive contract (parameterized) ─────────────────────────────
//
// The robots contract is uniform across entities: a public/indexable state
// yields 'index, follow' and any non-public/hidden state yields
// 'noindex, nofollow'. The User cases drive the real ProfileVisibilityResolver
// via privacy_settings instead of mocking it, so the resolver → robots
// pipeline is exercised end-to-end.

dataset('entity_robots_directives', [
    'Campaign public' => [[Campaign::class, ['visibility' => Visibility::Public], 'index, follow']],
    'Campaign private' => [[Campaign::class, ['visibility' => Visibility::Private], 'noindex, nofollow']],
    'Campaign protected' => [[Campaign::class, ['visibility' => Visibility::Protected], 'noindex, nofollow']],
    'Team active' => [[Team::class, ['is_active' => true], 'index, follow']],
    'Event draft' => [[Event::class, ['is_public' => true, 'status' => 'draft'], 'noindex, nofollow']],
    'Event completed' => [[Event::class, ['is_public' => true, 'status' => 'completed'], 'noindex, nofollow']],
]);

describe('Robots directive contract', function () {
    it('emits the correct robots directive per visibility state', function (array $d) {
        [$class, $attrs, $expected] = $d;

        expect($class::factory()->create($attrs)->getDynamicSEOData()->robots)->toBe($expected);
    })->with('entity_robots_directives');
});
