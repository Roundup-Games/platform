<?php

namespace App\Mcp\Tools;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\McpAbility;
use App\Mcp\Concerns\BuildsGamePayloads;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use App\Services\DiscoveryQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The participant discovery search tool (M079/S02): what games can I join?
 *
 * Reuses DiscoveryQueryService::results() in games mode — the exact
 * web-feed pipeline — so visibility scoping (VisibleToScope), mode
 * constraints, the gathering cap, distance enrichment, and ordering
 * can never drift from the web discovery surface (INV-16: no raw
 * builder consumers). Private games never appear; protected games
 * appear only where the web feed would show them to this user.
 *
 * Proximity works like the web fallback: the origin is the acting user's
 * saved profile location (agents have no browser geolocation), and an
 * inert radius or missing location simply skips the proximity constraint
 * — reported via `proximity` in the payload so an agent understands why
 * radius did not narrow results.
 */
#[Name(self::NAME)]
#[Description('Searches upcoming games open for discovery: the same result set this user sees on the website, scoped to their visibility. Filters: q (free text), city (city-hub slug), radius (km around the user\'s saved profile location: 0, 10, 25, or 50), language (defaults to the user\'s preferred language, then the platform default), experience level, game_system (game system UUID), date window (upcoming, this_week, this_month). Returns compact rows: id, name, date, systems, seats, city, and a link to the web detail page.')]
class GamesSearchTool extends Tool
{
    use BuildsGamePayloads;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'games.search';

    /** Tool-class scope (D277) — read-only discovery. */
    public const ABILITY = McpAbility::Read;

    /** Page-size bounds for the compact result window. */
    public const MAX_LIMIT = 25;

    public const DEFAULT_LIMIT = 10;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'radius' => ['nullable', 'integer', Rule::in([0, ...DiscoveryQueryService::RADIUS_OPTIONS])],
            'language' => ['nullable', new Enum(ContentLanguage::class)],
            'experience' => ['nullable', new Enum(ExperienceLevel::class)],
            'game_system' => ['nullable', 'uuid'],
            'date' => ['nullable', Rule::in(['upcoming', 'this_week', 'this_month'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $radiusValue = $args['radius'] ?? 0;
        $radius = is_int($radiusValue) ? (float) $radiusValue : 0.0;
        $limitValue = $args['limit'] ?? self::DEFAULT_LIMIT;
        $limit = is_int($limitValue) ? $limitValue : self::DEFAULT_LIMIT;
        $pageValue = $args['page'] ?? 1;
        $page = is_int($pageValue) ? max(1, $pageValue) : 1;

        $search = is_string($args['q'] ?? null) ? $args['q'] : '';
        $experience = is_string($args['experience'] ?? null) ? $args['experience'] : '';
        $language = is_string($args['language'] ?? null)
            ? $args['language']
            : ($user->preferredLocale() ?? app()->getLocale());
        $citySlug = is_string($args['city'] ?? null) ? $args['city'] : null;
        $date = is_string($args['date'] ?? null) ? $args['date'] : 'upcoming';

        [$lat, $lng, $hasLocation] = $this->profileOrigin($user);

        $filters = new DiscoveryFilters(
            search: $search,
            gameSystemId: uuid_or_null($args['game_system'] ?? null),
            experienceLevel: $experience,
            language: $language,
            citySlug: $citySlug,
            date: $date,
        );

        // The feed pipeline paginates by page number; the tool's page
        // argument is the single source for it within this dispatch.
        Paginator::currentPageResolver(fn (): int => $page);

        $feed = app(DiscoveryQueryService::class)
            ->results(DiscoveryQueryService::MODE_GAMES, $filters, $user, $radius, $lat, $lng, $hasLocation, $limit);

        /** @var LengthAwarePaginator<int, Game> $paginator */
        $paginator = $feed['results'];

        // One bulk disclosure pass for the whole page (~4 queries) instead
        // of a per-row relationship resolution in gameRow().
        $venueLevels = $this->batchVenueLevels($paginator->items(), $user);

        return Response::json([
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $limit,
            'has_more' => $paginator->hasMorePages(),
            'proximity' => [
                'radius_km' => (int) $radius,
                'origin' => $hasLocation ? 'profile-location' : 'none',
            ],
            'games' => collect($paginator->items())
                ->map(fn (Game $game): array => $this->gameRow($game, $user, $venueLevels[to_string_id($game->getKey())] ?? null))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->max(200)->description('Free-text search on game name and description.'),
            'city' => $schema->string()->max(100)->description('City-hub slug filter (e.g. a city from the platform\'s city directory).'),
            'radius' => $schema->integer()->enum([0, ...DiscoveryQueryService::RADIUS_OPTIONS])->description('Radius in kilometres around the user\'s saved profile location. 0 disables the proximity filter. Inert when the user has no saved location.'),
            'language' => $schema->string()->enum(ContentLanguage::class)->description('Content language filter. Defaults to the user\'s preferred language, falling back to the platform default for users without one (unlike the website, which follows the visited locale).'),
            'experience' => $schema->string()->enum(ExperienceLevel::class)->description('Experience level filter.'),
            'game_system' => $schema->string()->description('Game system UUID to filter by.'),
            'date' => $schema->string()->enum(['upcoming', 'this_week', 'this_month'])->description('Date window. Defaults to upcoming.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->description('Page size. Defaults to 10.'),
            'page' => $schema->integer()->min(1)->description('Result page number (1-based), like the website feed. Defaults to 1.'),
        ];
    }

    /**
     * The proximity origin for an agent call: the acting user's saved
     * profile location — exactly the web fallback branch of
     * ManagesDiscoveryFilters::resolveSearchOrigin() when browser
     * geolocation is unavailable (which it always is for an agent).
     *
     * @return array{0: ?float, 1: ?float, 2: bool} [lat, lng, hasLocation]
     */
    private function profileOrigin(User $user): array
    {
        $locationId = $user->location_id;

        if ($locationId === null || $locationId === '') {
            return [null, null, false];
        }

        $location = Location::query()->find($locationId);

        if ($location === null) {
            return [null, null, false];
        }

        return [(float) $location->latitude, (float) $location->longitude, true];
    }
}
