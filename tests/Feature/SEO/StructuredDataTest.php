<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

// ── BreadcrumbBuilder Tests ────────────────────────────

describe('BreadcrumbBuilder', function () {
    it('includes breadcrumb JSON-LD on all public game system pages', function () {
        $system = GameSystem::factory()->create(['slug' => 'test-breadcrumb-' . Str::random(6)]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('application/ld+json');
        expect($content)->toContain('"@type":"BreadcrumbList"');
    });

    it('builds correct breadcrumb trail for game-systems.show', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'D&D 5e'],
            'slug' => 'dd-5e-breadcrumb-' . Str::random(6),
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        // English locale (set in Pest.php beforeEach)
        expect($content)->toContain('"name":"Home"');
        expect($content)->toContain('"name":"Game Systems"');
    });
});

// ── GameSystem Product Schema Tests ────────────────────

describe('GameSystem Product Schema', function () {
    it('renders Product JSON-LD with name and description', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Catan'],
            'description' => ['en' => '<p>A classic resource management board game</p>'],
            'slug' => 'catan-prod-' . Str::random(6),
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Product"');
        expect($content)->toContain('"name":"Catan"');
        expect($content)->toContain('A classic resource management board game');
    });

    it('renders AggregateRating when sp_rating exists', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'rated-game-' . Str::random(6),
            'sp_rating' => 4.5,
            'sp_review_count' => 23,
            'bgg_average_rating' => null,
            'bgg_users_rated' => null,
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"AggregateRating"');
        expect($content)->toContain('"ratingValue":4.5');
        expect($content)->toContain('"bestRating":5');
    });

    it('renders AggregateRating with BGG scale when only BGG rating exists', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'bgg-rated-' . Str::random(6),
            'sp_rating' => null,
            'sp_review_count' => null,
            'bgg_average_rating' => 7.82,
            'bgg_users_rated' => 15000,
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"AggregateRating"');
        expect($content)->toContain('"ratingValue":7.82');
        expect($content)->toContain('"bestRating":10');
    });

    it('skips AggregateRating when no ratings exist', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'no-ratings-' . Str::random(6),
            'sp_rating' => null,
            'sp_review_count' => null,
            'bgg_average_rating' => null,
            'bgg_users_rated' => null,
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->not->toContain('"@type":"AggregateRating"');
    });

    it('renders FAQPage when faq_content exists', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'faq-game-' . Str::random(6),
            'bgg_average_rating' => null,
            'bgg_users_rated' => null,
            'faq_content' => [
                ['question' => 'How many players?', 'answer' => '2-6 players'],
                ['question' => 'Play time?', 'answer' => '60-90 minutes'],
            ],
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"FAQPage"');
        expect($content)->toContain('"@type":"Question"');
        expect($content)->toContain('How many players?');
        expect($content)->toContain('2-6 players');
    });

    it('skips FAQPage when faq_content is empty', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'no-faq-' . Str::random(6),
            'bgg_average_rating' => null,
            'bgg_users_rated' => null,
            'faq_content' => null,
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->not->toContain('"@type":"FAQPage"');
    });

    it('includes brand/creator in Product schema when available', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'branded-game-' . Str::random(6),
            'creator' => 'Wizards of the Coast',
            'bgg_average_rating' => null,
            'bgg_users_rated' => null,
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"brand":"Wizards of the Coast"');
    });
});

// ── Game Event Schema Tests ───────────────────────────

describe('Game Event Schema', function () {
    it('renders Event JSON-LD with name and startDate on public game', function () {
        $game = Game::factory()->create([
            'name' => ['en' => 'Epic Board Game Night'],
            'description' => ['en' => 'Join us for an epic session'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'expected_duration' => 3.0,
            'price' => 0,
            'max_players' => 6,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Event"');
        expect($content)->toContain('"name":"Epic Board Game Night"');
        expect($content)->toContain('"startDate"');
        expect($content)->toContain('"endDate"');
        expect($content)->toContain('"eventStatus"');
        expect($content)->toContain('"maximumAttendees":6');
        expect($content)->toContain('"isAccessibleForFree":true');
    });

    it('renders Cancelled eventStatus for cancelled games', function () {
        $game = Game::factory()->create([
            'visibility' => 'public',
            'status' => 'canceled',
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('EventCancelled');
    });

    it('renders Offer with price for paid games', function () {
        $game = Game::factory()->create([
            'visibility' => 'public',
            'price' => 15.00,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Offer"');
        expect($content)->toContain('"price":15');
        expect($content)->toContain('"priceCurrency":"EUR"');
        expect($content)->toContain('"isAccessibleForFree":false');
    });

    it('skips Offer for free games', function () {
        $game = Game::factory()->create([
            'visibility' => 'public',
            'price' => 0,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->not->toContain('"@type":"Offer"');
    });

    it('skips Event schema for non-public games', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
        ]);

        // Protected games require auth; view as the owner so policy passes
        $response = actingAs($owner)->get(route('games.show', $game->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->not->toContain('"@type":"Event"');
    });

    it('renders location from linked location', function () {
        $location = \App\Models\Location::factory()->create([
            'name' => 'Game Store',
            'address' => '123 Board St',
            'city' => 'Berlin',
            'country' => 'DE',
        ]);
        $game = Game::factory()->create([
            'visibility' => 'public',
            'location_id' => $location->id,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Place"');
        expect($content)->toContain('"@type":"PostalAddress"');
        expect($content)->toContain('"addressLocality":"Berlin"');
        expect($content)->toContain('"addressCountry":"DE"');
    });

    it('renders organizer from game owner', function () {
        $owner = User::factory()->create(['name' => 'GM Alice']);
        $game = Game::factory()->create([
            'visibility' => 'public',
            'owner_id' => $owner->id,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Person"');
        expect($content)->toContain('"name":"GM Alice"');
    });
});

// ── Campaign Event Schema Tests ───────────────────────

describe('Campaign Event Schema', function () {
    it('renders Event JSON-LD for active public campaign', function () {
        $campaign = Campaign::factory()->create([
            'name' => ['en' => 'Weekly D&D Campaign'],
            'description' => ['en' => 'An ongoing adventure'],
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Event"');
        expect($content)->toContain('"name":"Weekly D&D Campaign"');
        expect($content)->toContain('"eventStatus"');
    });

    it('skips Event schema for non-public campaigns', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
            'status' => 'active',
        ]);

        // Protected campaigns require auth; view as the owner so policy passes
        $response = actingAs($owner)->get(route('campaigns.show', $campaign->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->not->toContain('"@type":"Event"');
    });

    it('renders startDate from first session', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => 'public',
            'status' => 'active',
        ]);
        // Create first session
        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'owner_id' => $campaign->owner_id,
            'game_system_id' => $campaign->game_system_id,
            'date_time' => now()->addDays(7),
            'visibility' => 'public',
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"startDate"');
    });

    it('renders location from linked location', function () {
        $location = \App\Models\Location::factory()->create([
            'name' => 'Community Center',
            'city' => 'Munich',
            'country' => 'DE',
        ]);
        $campaign = Campaign::factory()->create([
            'visibility' => 'public',
            'status' => 'active',
            'location_id' => $location->id,
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Place"');
        expect($content)->toContain('"addressLocality":"Munich"');
    });
});

// ── Team Organization Schema Tests ────────────────────

describe('Team Organization Schema', function () {
    it('renders Organization JSON-LD with name and url for active team', function () {
        $team = Team::factory()->create([
            'name' => 'Berlin Board Gamers',
            'slug' => 'berlin-board-gamers-' . Str::random(6),
            'is_active' => true,
        ]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Organization"');
        expect($content)->toContain('"name":"Berlin Board Gamers"');
        expect($content)->toContain('"url"');
    });

    it('renders address with city and country', function () {
        $team = Team::factory()->create([
            'slug' => 'addr-team-' . Str::random(6),
            'is_active' => true,
            'city' => 'Hamburg',
            'country' => 'DE',
        ]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"PostalAddress"');
        expect($content)->toContain('"addressLocality":"Hamburg"');
        expect($content)->toContain('"addressCountry":"DE"');
    });

    it('renders foundingDate when founded_year is set', function () {
        $team = Team::factory()->create([
            'slug' => 'founded-team-' . Str::random(6),
            'is_active' => true,
            'founded_year' => 2020,
        ]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"foundingDate":"2020"');
    });

    it('renders sameAs from social_links', function () {
        $team = Team::factory()->create([
            'slug' => 'social-team-' . Str::random(6),
            'is_active' => true,
            'social_links' => [
                'twitter' => 'https://twitter.com/berlingamers',
                'discord' => 'https://discord.gg/abc123',
            ],
        ]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"sameAs"');
        // JSON encoding escapes forward slashes as \/
        expect($content)->toContain('twitter.com');
        expect($content)->toContain('discord.gg');
    });

    it('skips Organization schema for inactive teams', function () {
        $user = User::factory()->create();
        $team = Team::factory()->create([
            'slug' => 'inactive-team-' . Str::random(6),
            'is_active' => false,
        ]);

        // Inactive teams require auth (policy blocks guests)
        $response = actingAs($user)->get(route('teams.detail', $team->slug));
        // May be 403 if user is not a member, which is expected — just verify no Organization schema
        if ($response->status() === 200) {
            $content = $response->content();
            expect($content)->not->toContain('"@type":"Organization"');
        } else {
            // If we can't even view the page, that's correct — no schema to check
            expect($response->status())->toBe(403);
        }
    });
});

// ── User Person Schema Tests ──────────────────────────

describe('User Person Schema', function () {
    it('renders Person JSON-LD with name and url on public profile', function () {
        $user = User::factory()->create([
            'name' => 'Alice Gamer',
            'slug' => 'alice-gamer-' . Str::random(6),
        ]);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"@type":"Person"');
        expect($content)->toContain('"name":"Alice Gamer"');
        expect($content)->toContain('"url"');
        expect($content)->toContain($user->slug);
    });

    it('renders description from bio', function () {
        $user = User::factory()->create([
            'slug' => 'bio-user-' . Str::random(6),
            'bio' => 'I love board games and TTRPGs',
        ]);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('I love board games and TTRPGs');
    });

    it('renders image from avatar media', function () {
        $user = User::factory()->create([
            'slug' => 'avatar-user-' . Str::random(6),
            'avatar_url' => 'https://example.com/avatar.jpg',
        ]);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"image"');
    });

    it('renders jobTitle Game Master when user has GM role', function () {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $user = User::factory()->create([
            'slug' => 'gm-user-' . Str::random(6),
        ]);
        $user->assignRole('Game Master');

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"jobTitle":"Game Master"');
    });

    it('skips jobTitle when user is not a GM', function () {
        $user = User::factory()->create([
            'slug' => 'non-gm-user-' . Str::random(6),
        ]);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->not->toContain('"jobTitle"');
    });

    it('renders knowsAbout from favorite game systems', function () {
        $user = User::factory()->create([
            'slug' => 'systems-user-' . Str::random(6),
        ]);

        $system1 = GameSystem::factory()->create(['name' => ['en' => 'D&D 5e']]);
        $system2 = GameSystem::factory()->create(['name' => ['en' => 'Pathfinder 2e']]);

        $user->favoriteGameSystems()->attach([
            $system1->id => ['preference_type' => 'favorite'],
            $system2->id => ['preference_type' => 'favorite'],
        ]);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('"knowsAbout"');
        expect($content)->toContain('D&D 5e');
        expect($content)->toContain('Pathfinder 2e');
    });

    it('skips Person schema when profile is noindex', function () {
        $user = User::factory()->create([
            'slug' => 'private-user-' . Str::random(6),
            'privacy_settings' => [
                'location' => 'nobody',
                'game_systems' => 'nobody',
                'vibes' => 'nobody',
                'campaigns' => 'nobody',
                'teams' => 'nobody',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
            ],
        ]);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('noindex');
        expect($content)->not->toContain('"@type":"Person"');
    });

    it('includes BreadcrumbList alongside Person schema', function () {
        $user = User::factory()->create([
            'slug' => 'breadcrumb-user-' . Str::random(6),
        ]);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $content = $response->content();
        // Person schema from this task
        expect($content)->toContain('"@type":"Person"');
        // BreadcrumbList from T01 (auto-injected)
        expect($content)->toContain('"@type":"BreadcrumbList"');
    });
});

// ── JSON-LD Structural Validation Tests (T06) ────────

describe('JSON-LD Structural Validation', function () {
    it('GameSystem page emits valid JSON-LD with Product, AggregateRating, FAQPage, and BreadcrumbList', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Struct Test Game'],
            'description' => ['en' => '<p>A test game for structural validation</p>'],
            'slug' => 'struct-test-' . Str::random(6),
            'sp_rating' => 4.3,
            'sp_review_count' => 42,
            'bgg_average_rating' => null,
            'bgg_users_rated' => null,
            'faq_content' => [
                ['question' => 'How to play?', 'answer' => 'Read the rules'],
            ],
            'creator' => 'Test Studios',
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $schemas = extractJsonLdSchemas($response->content());
        expect($schemas)->not->toBeEmpty('No JSON-LD scripts found on GameSystem page');

        // BreadcrumbList
        $breadcrumb = findSchemaByType($schemas, 'BreadcrumbList');
        expect($breadcrumb)->not->toBeNull('Missing BreadcrumbList');
        expect($breadcrumb)->toHaveKey('@context');
        expect($breadcrumb['itemListElement'])->not->toBeEmpty();

        // Product
        $product = findSchemaByType($schemas, 'Product');
        expect($product)->not->toBeNull('Missing Product schema');
        expect($product)->toHaveKey('name');
        expect($product['name'])->toBe('Struct Test Game');
        expect($product)->toHaveKey('description');
        expect($product)->toHaveKey('brand');

        // AggregateRating nested in Product
        expect($product)->toHaveKey('aggregateRating');
        $rating = $product['aggregateRating'];
        expect($rating['@type'])->toBe('AggregateRating');
        expect($rating)->toHaveKey('ratingValue');
        expect($rating)->toHaveKey('bestRating');
        expect($rating)->toHaveKey('reviewCount');
        expect($rating['bestRating'])->toBe(5); // Platform scale

        // FAQPage
        $faq = findSchemaByType($schemas, 'FAQPage');
        expect($faq)->not->toBeNull('Missing FAQPage schema');
        expect($faq)->toHaveKey('mainEntity');
        expect($faq['mainEntity'])->not->toBeEmpty();
        $firstQuestion = $faq['mainEntity'][0];
        expect($firstQuestion['@type'])->toBe('Question');
        expect($firstQuestion)->toHaveKey('acceptedAnswer');
        expect($firstQuestion['acceptedAnswer']['@type'])->toBe('Answer');
    });

    it('Game page emits valid Event JSON-LD with all required properties', function () {
        $location = \App\Models\Location::factory()->create([
            'name' => 'Game Cafe',
            'address' => '456 Dice Ave',
            'city' => 'Vienna',
            'country' => 'AT',
        ]);
        $owner = User::factory()->create(['name' => 'Event Host']);
        $game = Game::factory()->create([
            'name' => ['en' => 'Structured Event Test'],
            'description' => ['en' => 'A test event'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'expected_duration' => 2.5,
            'price' => 5.00,
            'max_players' => 8,
            'location_id' => $location->id,
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(14),
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $schemas = extractJsonLdSchemas($response->content());
        expect($schemas)->not->toBeEmpty('No JSON-LD scripts found on Game page');

        // Event
        $event = findSchemaByType($schemas, 'Event');
        expect($event)->not->toBeNull('Missing Event schema');
        expect($event)->toHaveKey('name');
        expect($event)->toHaveKey('startDate');
        expect($event)->toHaveKey('eventStatus');
        expect($event['eventStatus'])->toContain('EventScheduled');

        // EndDate
        expect($event)->toHaveKey('endDate');

        // Location
        expect($event)->toHaveKey('location');
        $place = $event['location'];
        expect($place['@type'])->toBe('Place');
        expect($place)->toHaveKey('address');
        expect($place['address']['@type'])->toBe('PostalAddress');
        expect($place['address']['addressLocality'])->toBe('Vienna');
        expect($place['address']['addressCountry'])->toBe('AT');

        // Organizer
        expect($event)->toHaveKey('organizer');
        expect($event['organizer']['@type'])->toBe('Person');

        // Attendees
        expect($event)->toHaveKey('maximumAttendees');
        expect($event['maximumAttendees'])->toBe(8);

        // Offers (paid game)
        expect($event)->toHaveKey('offers');
        $offer = $event['offers'];
        expect($offer['@type'])->toBe('Offer');
        expect($offer)->toHaveKey('price');
        expect($offer)->toHaveKey('priceCurrency');
        expect($offer['priceCurrency'])->toBe('EUR');

        // Free/paid flag
        expect($event['isAccessibleForFree'])->toBeFalse();

        // BreadcrumbList
        $breadcrumb = findSchemaByType($schemas, 'BreadcrumbList');
        expect($breadcrumb)->not->toBeNull('Missing BreadcrumbList');
    });

    it('Campaign page emits valid Event JSON-LD', function () {
        $campaign = Campaign::factory()->create([
            'name' => ['en' => 'Campaign Event Test'],
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();

        $schemas = extractJsonLdSchemas($response->content());
        expect($schemas)->not->toBeEmpty('No JSON-LD scripts found on Campaign page');

        $event = findSchemaByType($schemas, 'Event');
        expect($event)->not->toBeNull('Missing Event schema on Campaign page');
        expect($event)->toHaveKey('name');
        expect($event)->toHaveKey('eventStatus');

        // BreadcrumbList
        $breadcrumb = findSchemaByType($schemas, 'BreadcrumbList');
        expect($breadcrumb)->not->toBeNull('Missing BreadcrumbList');
    });

    it('Team page emits valid Organization JSON-LD', function () {
        $team = Team::factory()->create([
            'name' => 'Struct Test Team',
            'slug' => 'struct-team-' . Str::random(6),
            'is_active' => true,
            'city' => 'Leipzig',
            'country' => 'DE',
            'founded_year' => 2021,
            'social_links' => ['website' => 'https://example.com'],
        ]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();

        $schemas = extractJsonLdSchemas($response->content());
        expect($schemas)->not->toBeEmpty('No JSON-LD scripts found on Team page');

        $org = findSchemaByType($schemas, 'Organization');
        expect($org)->not->toBeNull('Missing Organization schema');
        expect($org)->toHaveKey('name');
        expect($org['name'])->toBe('Struct Test Team');
        expect($org)->toHaveKey('url');

        // Address
        expect($org)->toHaveKey('address');
        $addr = $org['address'];
        expect($addr['@type'])->toBe('PostalAddress');
        expect($addr['addressLocality'])->toBe('Leipzig');
        expect($addr['addressCountry'])->toBe('DE');

        // foundingDate
        expect($org)->toHaveKey('foundingDate');
        expect($org['foundingDate'])->toBe('2021');

        // sameAs
        expect($org)->toHaveKey('sameAs');

        // BreadcrumbList
        $breadcrumb = findSchemaByType($schemas, 'BreadcrumbList');
        expect($breadcrumb)->not->toBeNull('Missing BreadcrumbList');
    });

    it('User page emits valid Person JSON-LD', function () {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $user = User::factory()->create([
            'name' => 'Struct Person User',
            'slug' => 'struct-person-' . Str::random(6),
            'bio' => 'Struct test bio',
        ]);
        $user->assignRole('Game Master');

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();

        $schemas = extractJsonLdSchemas($response->content());
        expect($schemas)->not->toBeEmpty('No JSON-LD scripts found on User page');

        $person = findSchemaByType($schemas, 'Person');
        expect($person)->not->toBeNull('Missing Person schema');
        expect($person)->toHaveKey('name');
        expect($person['name'])->toBe('Struct Person User');
        expect($person)->toHaveKey('url');
        expect($person)->toHaveKey('description');
        expect($person['description'])->toBe('Struct test bio');
        expect($person)->toHaveKey('jobTitle');
        expect($person['jobTitle'])->toBe('Game Master');

        // BreadcrumbList
        $breadcrumb = findSchemaByType($schemas, 'BreadcrumbList');
        expect($breadcrumb)->not->toBeNull('Missing BreadcrumbList');
    });

    it('all JSON-LD blocks have @context on every page type', function () {
        // GameSystem
        $system = GameSystem::factory()->create([
            'slug' => 'ctx-gs-' . Str::random(6),
            'bgg_average_rating' => null,
            'bgg_users_rated' => null,
        ]);
        $resp = get(route('game-systems.show', $system->slug));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue("Schema missing @context: " . json_encode($schema));
        }

        // Game
        $game = Game::factory()->create(['visibility' => 'public', 'date_time' => now()->addDay()]);
        $resp = get(route('games.detail', $game->id));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue("Schema missing @context on Game page");
        }

        // Campaign
        $campaign = Campaign::factory()->create(['visibility' => 'public', 'status' => 'active']);
        $resp = get(route('campaigns.detail', $campaign->id));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue("Schema missing @context on Campaign page");
        }

        // Team
        $team = Team::factory()->create([
            'slug' => 'ctx-team-' . Str::random(6),
            'is_active' => true,
        ]);
        $resp = get(route('teams.detail', $team->slug));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue("Schema missing @context on Team page");
        }

        // User
        $user = User::factory()->create(['slug' => 'ctx-user-' . Str::random(6)]);
        $resp = get(route('profile.public', $user->slug));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue("Schema missing @context on User page");
        }
    });
});
