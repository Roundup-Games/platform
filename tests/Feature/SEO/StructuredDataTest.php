<?php

use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\get;

// Structural JSON-LD validation via extractJsonLdSchemas(). Earlier byte-
// substring assertions on $response->content() (asserting '"@type":"Product"',
// '"bestRating":5', etc.) were brittle and are fully re-covered here through
// a real JSON parse.

describe('JSON-LD Structural Validation', function () {
    it('GameSystem page emits valid JSON-LD with Product, AggregateRating, FAQPage, and BreadcrumbList', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Struct Test Game'],
            'description' => ['en' => '<p>A test game for structural validation</p>'],
            'slug' => 'struct-test-'.Str::random(6),
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
        // Verified commercial venue so Game::buildEventPlace() emits the Place/
        // PostalAddress in the Event schema (stranger disclosure rung = Exact).
        $location = Location::factory()->create([
            'name' => 'Game Cafe',
            'address' => '456 Dice Ave',
            'city' => 'Vienna',
            'country' => 'AT',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
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
        expect($event)->toHaveKey('maximumAttendeeCapacity');
        expect($event['maximumAttendeeCapacity'])->toBe(8);

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
            'slug' => 'struct-team-'.Str::random(6),
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
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $user = User::factory()->create([
            'name' => 'Struct Person User',
            'slug' => 'struct-person-'.Str::random(6),
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
            'slug' => 'ctx-gs-'.Str::random(6),
            'bgg_average_rating' => null,
            'bgg_users_rated' => null,
        ]);
        $resp = get(route('game-systems.show', $system->slug));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue('Schema missing @context: '.json_encode($schema));
        }

        // Game
        $game = Game::factory()->create(['visibility' => 'public', 'date_time' => now()->addDay()]);
        $resp = get(route('games.detail', $game->id));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue('Schema missing @context on Game page');
        }

        // Campaign
        $campaign = Campaign::factory()->create(['visibility' => 'public', 'status' => 'active']);
        $resp = get(route('campaigns.detail', $campaign->id));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue('Schema missing @context on Campaign page');
        }

        // Team
        $team = Team::factory()->create([
            'slug' => 'ctx-team-'.Str::random(6),
            'is_active' => true,
        ]);
        $resp = get(route('teams.detail', $team->slug));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue('Schema missing @context on Team page');
        }

        // User
        $user = User::factory()->create(['slug' => 'ctx-user-'.Str::random(6)]);
        $resp = get(route('profile.public', $user->slug));
        $schemas = extractJsonLdSchemas($resp->content());
        foreach ($schemas as $schema) {
            expect(array_key_exists('@context', $schema))->toBeTrue('Schema missing @context on User page');
        }
    });
});
