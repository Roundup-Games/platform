<?php

namespace App\Services\Discord;

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pure transformer that turns a collection of upcoming roundup {@see Game}
 * sessions (with viewer/guild context) into a single multi-embed Discord digest
 * message: a compact two-week calendar listing grouped by **date → venue**,
 * with multi-table nights (several games sharing a venue + date) collapsed
 * under one venue heading.
 *
 * This is the S02 digest renderer — a DIFFERENT render shape from {@see
 * DiscordCardRenderer}. The card renderer emits one rich embed per Game *with
 * RSVP buttons*; the digest emits one embed per **date** with one field per
 * **venue**, and NO buttons — it is a read-only listing. Interactivity lives in
 * the games/card channel; the calendar channel only lists what is coming up.
 *
 * Pure (MEM917): NO Discord I/O, NO database queries, NO filesystem access. It
 * reads only the Games' attributes and their *pre-loaded* relations (owner,
 * linkedLocation, gameSystems) plus the values carried in {@see
 * DiscordDigestContext}. Callers ({@see DiscordDigestPublisher}, T03) are
 * responsible for eager-loading relations and for computing the roster counts.
 * The renderer never mutates global state and never touches an unloaded
 * relation (it degrades to an empty value rather than lazy-loading).
 *
 * Discord embed limits are enforced defensively — the renderer cannot trust the
 * input size, so it caps:
 *  - field.value at {@see MAX_FIELD_VALUE_CHARS}, truncating whole one-liners
 *    and appending a `… (+N more)` marker;
 *  - fields per embed at {@see MAX_FIELDS_PER_EMBED} and total embed text at
 *    {@see MAX_EMBED_TOTAL_CHARS}, splitting a busy date across continued
 *    embeds;
 *  - embeds per message at {@see MAX_EMBEDS}, reserving the last slot for a
 *    "see roundup for the full calendar" note when an extremely busy fortnight
 *    overflows.
 *
 * The output is a {@see DiscordWebhookPayload} (multi-embed), ready to hand to
 * {@see DiscordWebhookClient}. The empty window (no upcoming games) still emits
 * a single tidy empty-state embed so the calendar channel always has exactly
 * one current digest message.
 */
class DiscordDigestRenderer
{
    // Discord embed limits (web-verified 2026).
    public const MAX_EMBEDS = 10;

    public const MAX_FIELDS_PER_EMBED = 25;

    public const MAX_EMBED_TOTAL_CHARS = 6000;

    public const MAX_FIELD_VALUE_CHARS = 1024;

    public const MAX_FIELD_NAME_CHARS = 256;

    public const MAX_TITLE_CHARS = 256;

    /** Roundup brand color (emerald) — matches {@see DiscordCardRenderer::COLOR_BRAND}. */
    public const COLOR_BRAND = 0x2ECC71;

    /** Muted grey for the empty-state embed (no action to take). */
    public const COLOR_EMPTY = 0x95A5A6;

    /** Footer applied to every digest embed for brand consistency. */
    private const FOOTER_TEXT = 'roundup · cross-community tabletop';

    /** Grouping key + label for games with no linked venue. */
    private const NO_VENUE_LABEL = 'Online / no venue';

    /** Headroom reserved inside a field value for the truncation marker. */
    private const FIELD_VALUE_HEADROOM = 40;

    /**
     * Render a collection of upcoming public games into a postable digest
     * payload.
     *
     * Games should have owner, linkedLocation, and gameSystems eager-loaded by
     * the caller; an unloaded relation degrades to an empty value rather than
     * triggering a query. The collection may arrive in any order — the renderer
     * sorts deterministically by date_time.
     *
     * @param  Collection<int, Game>  $games
     */
    public function render(Collection $games, DiscordDigestContext $context): DiscordWebhookPayload
    {
        if ($games->isEmpty()) {
            return $this->emptyStatePayload();
        }

        $dateBuckets = $this->groupByDate($games);

        // Build one embed-group per date (a date may span several embeds when
        // it overflows the per-embed field/char caps).
        $groups = [];
        foreach ($dateBuckets as $dateKey => $dateGames) {
            $title = $this->dateHeading($dateGames, $context);
            $venueFields = $this->buildVenueFields($dateGames, $context);
            $groups[] = [
                'embeds' => $this->packFieldsIntoEmbeds($title, $venueFields),
                'gameCount' => $dateGames->count(),
            ];
        }

        return $this->packMessage($groups);
    }

    // ── Message assembly ─────────────────────────────────

    /**
     * Greedily pack per-date embed groups into a single message, enforcing the
     * 10-embed cap. When content overflows, the final slot is reserved for a
     * truncation note so members always see they are viewing a partial
     * calendar (never a silently truncated list).
     *
     * @param  array<int, array{embeds: array<int, array<string, mixed>>, gameCount: int}>  $groups
     */
    private function packMessage(array $groups): DiscordWebhookPayload
    {
        $totalEmbeds = array_sum(array_map(fn (array $g): int => count($g['embeds']), $groups));

        // No overflow: emit every group in date order.
        if ($totalEmbeds <= self::MAX_EMBEDS) {
            $embeds = [];
            foreach ($groups as $group) {
                foreach ($group['embeds'] as $embed) {
                    $embeds[] = $embed;
                }
            }

            return new DiscordWebhookPayload(embeds: $embeds);
        }

        // Overflow: reserve the last slot for a note, pack whole dates until
        // the budget is spent. A date that would not fit whole is dropped to
        // the note rather than split mid-date across the cap boundary.
        $budget = self::MAX_EMBEDS - 1;
        $embeds = [];
        $shownGames = 0;
        foreach ($groups as $group) {
            if (count($embeds) + count($group['embeds']) > $budget) {
                break;
            }
            foreach ($group['embeds'] as $embed) {
                $embeds[] = $embed;
            }
            $shownGames += $group['gameCount'];
        }

        $embeds[] = $this->overflowNoteEmbed($shownGames);

        return new DiscordWebhookPayload(embeds: $embeds);
    }

    /**
     * Pack a single date's venue fields into one or more embeds, splitting when
     * the per-embed field count ({@see MAX_FIELDS_PER_EMBED}) or total text
     * ({@see MAX_EMBED_TOTAL_CHARS}) would be exceeded. Continued embeds are
     * suffixed `(continued)` so members can tell a split date apart from two
     * adjacent dates.
     *
     * @param  array<int, array{name: string, value: string, inline: bool}>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function packFieldsIntoEmbeds(string $title, array $fields): array
    {
        $embeds = [];
        $current = [];
        $currentChars = $this->embedOverhead($title);
        $part = 0;

        foreach ($fields as $field) {
            $fieldChars = mb_strlen($field['name']) + mb_strlen($field['value']);
            $wouldExceedFields = count($current) >= self::MAX_FIELDS_PER_EMBED;
            $wouldExceedChars = $currentChars + $fieldChars > self::MAX_EMBED_TOTAL_CHARS;

            if ($current !== [] && ($wouldExceedFields || $wouldExceedChars)) {
                $embeds[] = $this->makeDateEmbed($title, $current, $part);
                $part++;
                $current = [];
                $currentChars = $this->embedOverhead($title);
            }

            $current[] = $field;
            $currentChars += $fieldChars;
        }

        if ($current !== []) {
            $embeds[] = $this->makeDateEmbed($title, $current, $part);
        }

        return $embeds;
    }

    /**
     * @param  array<int, array{name: string, value: string, inline: bool}>  $fields
     * @return array<string, mixed>
     */
    private function makeDateEmbed(string $title, array $fields, int $part): array
    {
        $embedTitle = $part > 0 ? $title.' (continued)' : $title;

        return [
            'title' => Str::limit($embedTitle, self::MAX_TITLE_CHARS),
            'color' => self::COLOR_BRAND,
            'fields' => $fields,
            'footer' => ['text' => self::FOOTER_TEXT],
        ];
    }

    /**
     * Base character cost of a date embed (title + footer), used to seed the
     * per-embed running total so the 6000-char cap accounts for fixed text.
     */
    private function embedOverhead(string $title): int
    {
        return mb_strlen($title) + mb_strlen(self::FOOTER_TEXT);
    }

    /** @return array<string, mixed> */
    private function overflowNoteEmbed(int $shownGames): array
    {
        $noun = $shownGames === 1 ? 'game' : 'games';

        return [
            'title' => 'More events ahead',
            'color' => self::COLOR_BRAND,
            'description' => "Showing {$shownGames} upcoming {$noun} — see roundup for the full two-week calendar.",
            'footer' => ['text' => self::FOOTER_TEXT],
        ];
    }

    private function emptyStatePayload(): DiscordWebhookPayload
    {
        return new DiscordWebhookPayload(embeds: [[
            'title' => 'No public events scheduled',
            'description' => '📭 There are no public roundup games in the next two weeks — check back soon.',
            'color' => self::COLOR_EMPTY,
            'footer' => ['text' => self::FOOTER_TEXT],
        ]]);
    }

    // ── Venue field builders ─────────────────────────────

    /**
     * Group a date's games by venue and render each venue as one embed field.
     * Games without a venue collapse into a single "Online / no venue" field.
     * Within a venue, games are ordered by start time.
     *
     * @param  Collection<int, Game>  $dateGames
     * @return array<int, array{name: string, value: string, inline: bool}>
     */
    private function buildVenueFields(Collection $dateGames, DiscordDigestContext $context): array
    {
        $venues = $this->groupByVenue($dateGames);

        $fields = [];
        foreach ($venues as $venueGames) {
            $fields[] = [
                'name' => Str::limit($this->venueLabel($venueGames), self::MAX_FIELD_NAME_CHARS - 1),
                'value' => $this->joinLinesWithCap($this->venueLines($venueGames, $context)),
                'inline' => false,
            ];
        }

        return $fields;
    }

    /**
     * Group a date's games by their venue key, preserving first-appearance
     * (earliest start time) order so multi-table nights collapse under one
     * heading automatically.
     *
     * @param  Collection<int, Game>  $dateGames
     * @return array<int, Collection<int, Game>>
     */
    private function groupByVenue(Collection $dateGames): array
    {
        $sorted = $dateGames->sortBy(fn (Game $g): string => $this->timeSortKey($g))->values();

        $venues = [];
        foreach ($sorted as $game) {
            $key = $this->venueKey($game);
            $venues[$key] ??= collect();
            $venues[$key]->push($game);
        }

        // Re-index numerically; the grouping key is no longer needed. Each
        // bucket is a Collection so downstream helpers keep the
        // Collection<int, Game> contract documented above.
        return array_values($venues);
    }

    /**
     * Derive the venue display name from the first game's pre-loaded
     * linkedLocation, falling back to the no-venue label when the relation is
     * absent or unnamed.
     *
     * @param  Collection<int, Game>  $venueGames
     */
    private function venueLabel(Collection $venueGames): string
    {
        $first = $venueGames[0] ?? null;
        $location = $first !== null ? $this->loadedLocation($first) : null;
        $name = $location !== null ? trim((string) $location->name) : '';

        return $name !== '' ? $name : self::NO_VENUE_LABEL;
    }

    /**
     * Render each game in a venue as a compact one-liner.
     *
     * @param  Collection<int, Game>  $venueGames
     * @return array<int, string>
     */
    private function venueLines(Collection $venueGames, DiscordDigestContext $context): array
    {
        $lines = [];
        foreach ($venueGames as $game) {
            $lines[] = $this->gameLine($game, $context);
        }

        return $lines;
    }

    // ── Game one-liner ───────────────────────────────────

    /**
     * Compact listing line: `HH:MM · [game name](deep link) · systems · roster`.
     * Segments with nothing to show (no systems, unlimited roster) are omitted.
     */
    private function gameLine(Game $game, DiscordDigestContext $context): string
    {
        $time = $this->gameTime($game);
        $name = $this->gameName($game);
        $link = $this->deepLink($game, $context);
        $systems = $this->systemLabels($game);
        $roster = $this->rosterLabel($game, $context);

        $linkedName = ($link !== null && $name !== '')
            ? "[{$name}]({$link})"
            : $name;

        $parts = [$time, $linkedName];
        if ($systems !== '') {
            $parts[] = $systems;
        }
        if ($roster !== '') {
            $parts[] = $roster;
        }

        return implode(' · ', $parts);
    }

    private function gameTime(Game $game): string
    {
        return $game->date_time?->format('H:i') ?? '--:--';
    }

    private function gameName(Game $game): string
    {
        return Str::limit(trim((string) $game->name), 80);
    }

    /**
     * Joined system labels for the one-liner. Systems within a line are
     * comma-separated to read as one segment, distinct from the `·` segment
     * separators.
     */
    private function systemLabels(Game $game): string
    {
        $systems = $this->loadedSystems($game);
        if ($systems->isEmpty()) {
            return '';
        }

        $labels = $systems
            ->map(fn (GameSystem $system): string => trim((string) $system->name))
            ->filter()
            ->values();

        return $labels->isEmpty() ? '' : implode(', ', $labels->all());
    }

    /**
     * Compact roster state: `approved/max` (+ `full`), or `open` for unlimited
     * capacity. The approved count is supplied per-game through the context DTO
     * (the renderer never queries the participant pipeline itself).
     */
    private function rosterLabel(Game $game, DiscordDigestContext $context): string
    {
        $approved = $context->approvedCountFor((string) $game->id) ?? 0;
        $max = $this->intOrNull($game->max_players);

        if ($max !== null && $max > 0) {
            $value = "{$approved}/{$max}";
            if ($approved >= $max) {
                $value .= ' full';
            }

            return $value;
        }

        return 'open';
    }

    private function deepLink(Game $game, DiscordDigestContext $context): ?string
    {
        $id = (string) $game->id;
        if ($id === '') {
            return null;
        }

        return rtrim($this->appUrl($context), '/').'/games/'.$id;
    }

    private function appUrl(DiscordDigestContext $context): string
    {
        $url = $context->appUrl ?? (is_string(config('app.url')) ? config('app.url') : null);

        return $url !== null && $url !== '' ? $url : 'http://localhost';
    }

    // ── Grouping & date headings ─────────────────────────

    /**
     * Group games by calendar date (Y-m-d), ordered ascending by start time so
     * the renderer is deterministic regardless of caller order.
     *
     * @param  Collection<int, Game>  $games
     * @return array<string, Collection<int, Game>>
     */
    private function groupByDate(Collection $games): array
    {
        $sorted = $games->sortBy(fn (Game $g): string => $this->dateTimeSortKey($g))->values();

        $buckets = [];
        foreach ($sorted as $game) {
            $key = $this->dateKey($game);
            $buckets[$key] ??= collect();
            $buckets[$key]->push($game);
        }

        return $buckets;
    }

    private function dateKey(Game $game): string
    {
        return $game->date_time?->format('Y-m-d') ?? '0000-00-00';
    }

    /**
     * Locale-aware date heading for an embed title, e.g. "Friday 25 Jul".
     *
     * @param  Collection<int, Game>  $dateGames
     */
    private function dateHeading(Collection $dateGames, DiscordDigestContext $context): string
    {
        $first = $dateGames->first();
        $date = $first?->date_time;

        if ($date === null) {
            return 'Undated';
        }

        $carbon = $date->copy();
        if ($context->locale !== null && $context->locale !== '') {
            $carbon->locale($context->locale);
        }

        return $carbon->translatedFormat('l j M');
    }

    private function dateTimeSortKey(Game $game): string
    {
        return $game->date_time?->format('Y-m-d\TH:i:s') ?? '9999-99-99T99:99:99';
    }

    private function timeSortKey(Game $game): string
    {
        return $game->date_time?->format('H:i:s') ?? '99:99:99';
    }

    // ── Field-value cap ──────────────────────────────────

    /**
     * Join one-liners with newlines, never exceeding the field-value char cap.
     * Whole one-liners are dropped once the budget is spent and a `… (+N more)`
     * marker records how many games were hidden.
     *
     * @param  array<int, string>  $lines
     */
    private function joinLinesWithCap(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        $out = '';
        $included = 0;

        foreach ($lines as $line) {
            $candidate = $out === '' ? $line : $out."\n".$line;
            if (mb_strlen($candidate) > self::MAX_FIELD_VALUE_CHARS - self::FIELD_VALUE_HEADROOM) {
                break;
            }
            $out = $candidate;
            $included++;
        }

        $remaining = count($lines) - $included;
        if ($remaining > 0) {
            $marker = "… (+{$remaining} more)";
            $out .= ($out === '' ? '' : "\n").$marker;
        }

        return $out;
    }

    // ── Relation reads (pure — cache-only, never query) ──

    private function loadedLocation(Game $game): ?Location
    {
        if (! $game->relationLoaded('linkedLocation')) {
            return null;
        }

        $location = $game->linkedLocation;

        return $location instanceof Location ? $location : null;
    }

    /**
     * @return Collection<int, GameSystem>
     */
    private function loadedSystems(Game $game): Collection
    {
        if (! $game->relationLoaded('gameSystems')) {
            return collect();
        }

        return $game->gameSystems;
    }

    private function venueKey(Game $game): string
    {
        $locationId = $game->location_id;
        if ($locationId !== null && (string) $locationId !== '') {
            return 'loc:'.(string) $locationId;
        }

        return '__no_venue__';
    }

    // ── Coercion helpers ─────────────────────────────────

    private function intOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int >= 0 ? $int : null;
    }
}
