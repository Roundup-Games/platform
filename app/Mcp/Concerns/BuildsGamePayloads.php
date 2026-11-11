<?php

namespace App\Mcp\Concerns;

use App\Enums\DisclosureLevel;
use App\Enums\ParticipantStatus;
use App\Enums\SafetyTool;
use App\Models\Game;
use App\Models\User;
use App\Services\LocationDisclosureService;

/**
 * Compact JSON payload mapping for participant read tools (M079/S02).
 *
 * One row shape for list surfaces (games.search, my.sessions) and one
 * detail shape for games.detail — names, ids, dates, and counts only.
 * No full entities, no descriptions, no free-text safety notes: an agent
 * gets enough to decide and a URL to point the user at, never a dump.
 *
 * Venue fields resolve through LocationDisclosureService (D079) exactly
 * like the web LocationDisplay component — the tool never reads address
 * attributes directly, so graduated disclosure can never drift between
 * surfaces.
 */
trait BuildsGamePayloads
{
    /**
     * Map a game to its compact list row. $venueLevel is the batch-resolved
     * disclosure level for the row's venue (see batchVenueLevels()); when
     * absent the row resolves it per-entity through the disclosure service
     * (single-row callers).
     *
     * @return array<string, mixed>
     */
    protected function gameRow(Game $game, ?User $viewer, ?DisclosureLevel $venueLevel = null): array
    {
        return [
            'id' => $game->id,
            'name' => $game->name,
            'date_time' => $game->date_time?->toIso8601String(),
            'type' => $game->game_type?->value,
            'language' => $game->language,
            'price' => $game->price,
            'systems' => $game->gameSystems->take(3)->pluck('name')->values()->all(),
            'distance_km' => isset($game->distance_km) ? round($game->distance_km, 1) : null,
            'seats' => $this->seatsPayload($game),
            'venue_city' => $this->venueCity($game, $viewer, $venueLevel),
            'url' => route('games.detail', $game),
        ];
    }

    /**
     * Batch-resolve venue disclosure levels for a list row set (M079/S02
     * review): one bulk pass through LocationDisclosureService (blocks +
     * approved games + friend/teammate owners, ~4 queries total) instead
     * of a per-row relationship resolution (~5 queries per row). Keyed by
     * game id for the row mapper; rows missing from the map fall back to
     * per-entity resolution inside venueCity().
     *
     * @param  iterable<int, Game>  $games
     * @return array<string, DisclosureLevel>
     */
    protected function batchVenueLevels(iterable $games, ?User $viewer): array
    {
        return app(LocationDisclosureService::class)->addressLevelsForGames($games, $viewer);
    }

    /**
     * Map a game to its detail payload: date, venue, seats, safety tools,
     * systems — the decision set for join/apply, nothing more.
     *
     * @return array<string, mixed>
     */
    protected function gameDetailPayload(Game $game, User $viewer): array
    {
        return [
            'id' => $game->id,
            'name' => $game->name,
            'date_time' => $game->date_time?->toIso8601String(),
            'status' => $game->status?->value,
            'type' => $game->game_type?->value,
            'visibility' => $game->visibility?->value,
            'language' => $game->language,
            'experience_level' => $game->experience_level,
            'price' => $game->price,
            'expected_duration_hours' => $game->expected_duration,
            'online' => $game->linkedLocation === null && ($game->location['type'] ?? null) === 'online',
            'systems' => $game->gameSystems->pluck('name')->values()->all(),
            'safety_tools' => $this->safetyTools($game),
            'seats' => $this->seatsPayload($game),
            'venue' => $this->venuePayload($game, $viewer),
            'owner' => ['id' => $game->owner?->id, 'name' => $game->owner?->name],
            'campaign' => $game->campaign !== null
                ? ['id' => $game->campaign->id, 'name' => $game->campaign->name]
                : null,
            'signup_cutoff_at' => $game->signup_cutoff_at?->toIso8601String(),
            'is_participating' => $game->owner_id === $viewer->id
                || $game->participants->contains(
                    fn ($participant): bool => $participant->user_id === $viewer->id
                        && $participant->status !== ParticipantStatus::Removed
                        && $participant->status !== ParticipantStatus::Rejected
                ),
            'url' => route('games.detail', $game),
        ];
    }

    /**
     * Seat availability: approved participants against capacity. Waitlist
     * and bench counts are surfaced too — they are public card facts on
     * the web feed (overflow counts) and drive join expectations.
     *
     * @return array<string, int|null>
     */
    protected function seatsPayload(Game $game): array
    {
        $approved = $this->withCountValue($game, 'participants_count');
        $max = $game->max_players;

        return [
            'taken' => $approved,
            'max' => $max,
            'available' => is_int($max) ? max(0, $max - $approved) : 0,
            'waitlisted' => $this->withCountValue($game, 'waitlisted_count'),
            'benched' => $this->withCountValue($game, 'benched_count'),
        ];
    }

    /**
     * A withCount subquery alias as an int. The aliases are runtime
     * attributes (not model PHPDoc properties), so they resolve through
     * getAttribute() and fail closed to 0 when a caller's query did not
     * select them.
     */
    protected function withCountValue(Game $game, string $key): int
    {
        $value = $game->getAttribute($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The game's selected safety tools (enum values only). The free-text
     * safety notes (lines/veils, comfort notes) never reach the agent
     * surface — they are participant-shared content, not listing facts.
     *
     * @return array<int, string>
     */
    protected function safetyTools(Game $game): array
    {
        $tools = $game->safety_rules['tools'] ?? [];

        if (! is_array($tools)) {
            return [];
        }

        return collect($tools)
            ->map(fn (mixed $tool) => is_string($tool) ? $tool : null)
            ->filter(fn (?string $tool) => $tool !== null && SafetyTool::tryFrom($tool) !== null)
            ->values()
            ->all();
    }

    /**
     * Venue facts for the detail payload, graduated through D079: the
     * address rung decides what appears, the name appears only for
     * locations with a public venue page (commercial), and nothing is
     * ever read around the disclosure service.
     *
     * @return array<string, mixed>|null
     */
    protected function venuePayload(Game $game, User $viewer): ?array
    {
        $location = $game->linkedLocation;

        if ($location === null) {
            return null;
        }

        $disclosure = app(LocationDisclosureService::class);
        $level = $disclosure->addressLevel($location, $viewer, $game);

        $payload = ['disclosure_level' => $level->value];

        if ($level->isAtLeast(DisclosureLevel::City)) {
            $payload['city'] = $location->city;
        }

        if ($level === DisclosureLevel::Exact) {
            $payload['address'] = $location->fullAddress();
        }

        if ($disclosure->isPublicVenuePage($location)) {
            $payload['name'] = $location->name;
        }

        return $payload;
    }

    /**
     * The city rung of venue disclosure for list rows: present only when
     * the viewer's disclosure level reaches the City rung (city name for
     * friends/verified venues), otherwise absent — an Area-level viewer
     * gets no city hint beyond what the web card would show. $level is the
     * batch-resolved disclosure level when the caller mapped one; null
     * resolves it per-entity.
     */
    protected function venueCity(Game $game, ?User $viewer, ?DisclosureLevel $level = null): ?string
    {
        $location = $game->linkedLocation;

        if ($location === null || $viewer === null) {
            return null;
        }

        $level ??= app(LocationDisclosureService::class)->addressLevel($location, $viewer, $game);

        return $level->isAtLeast(DisclosureLevel::City) ? $location->city : null;
    }
}
