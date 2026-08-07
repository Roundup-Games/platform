<?php

namespace App\Services\Discord;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Pure transformer that turns a roundup {@see Game} (with viewer/guild context)
 * into a Discord enriched card: a single embed plus a button component row.
 *
 * This is the wedge renderer. Every card it produces enriches Apollo's abstract
 * "time slot with a roster" model with roundup's differentiators (D116):
 *  - organizer trust line (reliability tier + score + games hosted)
 *  - venue with a map link and operational parameters (fee / overlap / rules)
 *  - roster state (approved / capacity, with waitlist + bench overflow)
 *  - cross-community attendee indicator (when nonzero)
 *
 * Pure: NO Discord I/O, NO database queries, NO filesystem access. It reads
 * only the Game's attributes and its *pre-loaded* relations (owner,
 * linkedLocation, gameSystems) plus the values carried in
 * {@see DiscordCardContext}. Callers (the DiscordPublisher chokepoint, T05) are
 * responsible for eager-loading relations and for computing the roster +
 * cross-community counts that depend on the participant pipeline and guild
 * membership. The renderer never mutates global state.
 *
 * Failure-degradation is graceful and field-level rather than throwing:
 *  - a Game with no linked Location omits the venue field entirely;
 *  - a Game whose owner has no reliability score omits the trust detail;
 *  - a context with zero cross-community attendees omits the indicator;
 *  - missing capacity renders an "open roster" line instead of a fraction.
 *
 * The output arrays are Discord's Create/Edit Message JSON shapes (embed
 * object + components array), ready to hand to {@see DiscordWebhookPayload}.
 */
class DiscordCardRenderer
{
    /**
     * Roundup brand color (emerald green) for the embed side-stripe.
     *
     * Discord embed colors are a decimal int (0xRRGGBB). Emerald differentiates
     * roundup cards from Apollo's default embeds in a shared channel.
     */
    public const COLOR_BRAND = 0x2ECC71;

    /** A cancelled/canceled game posts a red card so members see the change. */
    public const COLOR_CANCELED = 0xE74C3C;

    /** A completed game posts a muted grey card (historical, not actionable). */
    public const COLOR_COMPLETED = 0x95A5A6;

    /**
     * Render a Game into a postable enriched card.
     *
     * @param  Game  $game  Must have owner, linkedLocation, and gameSystems
     *                      eager-loaded by the caller (relations are read from
     *                      the cache; an unloaded relation degrades to null /
     *                      empty rather than triggering a query).
     */
    public function render(Game $game, DiscordCardContext $context): DiscordCard
    {
        return new DiscordCard(
            embed: $this->buildEmbed($game, $context),
            components: $this->buildComponents($game, $context),
        );
    }

    // ── Embed ───────────────────────────────────────────

    /**
     * @return array<string, mixed> A single Discord embed object.
     */
    private function buildEmbed(Game $game, DiscordCardContext $context): array
    {
        $deepLink = $this->deepLink($game, $context);

        $embed = [
            'title' => $this->title($game),
            'url' => $deepLink,
            'color' => $this->color($game),
            'timestamp' => $this->timestamp($game),
            'footer' => ['text' => $this->trans('discord.content_card_footer', [], $context->locale)],
            'fields' => $this->fields($game, $context),
        ];

        $description = $this->description($game);
        if ($description !== '') {
            $embed['description'] = $description;
        }

        $owner = $this->loadedOwner($game);
        if ($owner) {
            $embed['author'] = $this->author($owner, $context);
        }

        // Cover image is optional (the publisher may resolve it upstream and
        // pass it through context). Kept out of the Game read path so the
        // renderer never touches the filesystem (resolveCoverUrl does a
        // file_exists check). Omitted entirely when not provided.
        if ($context->coverImageUrl !== null) {
            $embed['thumbnail'] = ['url' => $context->coverImageUrl];
        }

        return $embed;
    }

    /**
     * The wedge fields, in display order. Venue and cross-community are
     * conditional — appended only when they have something to show.
     *
     * @return array<int, array{name: string, value: string, inline: bool}>
     */
    private function fields(Game $game, DiscordCardContext $context): array
    {
        $fields = [];

        $when = $this->whenField($game, $context);
        if ($when !== null) {
            $fields[] = $when;
        }

        $roster = $this->rosterField($game, $context);
        $fields[] = $roster;

        $systems = $this->systemsField($game, $context->locale);
        if ($systems !== null) {
            $fields[] = $systems;
        }

        $trust = $this->trustField($game, $context->locale);
        if ($trust !== null) {
            $fields[] = $trust;
        }

        $venue = $this->venueField($game, $context->locale);
        if ($venue !== null) {
            $fields[] = $venue;
        }

        $cross = $this->crossCommunityField($context);
        if ($cross !== null) {
            $fields[] = $cross;
        }

        // Discord rejects an empty fields array; only include the key when at
        // least one field was produced (a valid embed needs no fields at all,
        // but an empty [] is treated as "clear fields" by the PATCH path).
        return $fields;
    }

    // ── Field builders ──────────────────────────────────

    /**
     * @return array{name: string, value: string, inline: bool}|null
     */
    private function whenField(Game $game, DiscordCardContext $context): ?array
    {
        $date = $game->date_time;

        if (! $date) {
            return null;
        }

        $carbon = $date->copy();
        if ($context->locale !== null && $context->locale !== '') {
            $carbon->locale($context->locale);
        }
        $value = $carbon->translatedFormat('D j M Y · H:i');

        $duration = $this->duration($game);
        if ($duration !== '') {
            $value .= "\n⏱️ {$duration}";
        }

        return ['name' => $this->trans('discord.content_card_field_when', [], $context->locale), 'value' => $value, 'inline' => true];
    }

    /**
     * @return array{name: string, value: string, inline: bool}
     */
    private function rosterField(Game $game, DiscordCardContext $context): array
    {
        $approved = $context->approvedCount;
        $max = $this->intOrNull($game->max_players);

        // Count line — unchanged semantics: approved/capacity, full marker,
        // overflow models, optional min for open rosters.
        if ($max !== null && $max > 0) {
            $countLine = "{$approved}/{$max}";
            if ($approved >= $max) {
                $countLine .= ' — **'.$this->trans('discord.content_card_full', [], $context->locale).'**';
            }
        } else {
            // Unlimited capacity (max_players null or 0 — see HasCapacity).
            $countLine = $this->trans('discord.content_card_joined_open_roster', ['count' => $approved], $context->locale);
        }

        $overflows = [];
        if ($context->waitlistCount > 0) {
            $overflows[] = $this->trans('discord.content_card_count_waitlist', ['count' => $context->waitlistCount], $context->locale);
        }
        if ($context->benchedCount > 0) {
            $overflows[] = $this->trans('discord.content_card_count_bench', ['count' => $context->benchedCount], $context->locale);
        }
        if ($overflows !== []) {
            $countLine .= ' ('.implode(' · ', $overflows).')';
        }

        $min = $this->intOrNull($game->min_players);
        if ($min !== null && $min > 0 && ($max === null || $max === 0)) {
            $countLine .= ' · '.$this->trans('discord.content_card_min', ['count' => $min], $context->locale);
        }

        // Name lines (only when Discord-linked members are supplied). Listed
        // grouped by roster so a reader can see WHO is in at a glance, with the
        // unlinked remainder folded into "+x from roundup" per roster.
        $names = $this->rosterNameLines($context);

        return [
            'name' => $this->trans('discord.content_card_field_players', [], $context->locale),
            'value' => $names === '' ? $countLine : $countLine."\n".$names,
            // Block layout when we list names (they need the width); compact
            // inline when only a count is shown.
            'inline' => $names === '',
        ];
    }

    /**
     * The per-roster name lines, joined with newlines, or '' when no Discord
     * members were supplied.
     *
     * Members are grouped by status (approved → waitlisted → benched).
     * Each group renders its members as non-pinging Discord profile links and
     * appends a "+x from roundup" tail for the participants in that roster who
     * have no displayable Discord name. The number of names shown per group is
     * iteratively capped so the whole field stays within Discord's 1024-char
     * field-value limit — a too-long field makes Discord reject the embed,
     * which would break card posting. Overflow beyond the cap folds into
     * "+y more".
     *
     * @return string '' when no members, else the grouped lines.
     */
    private function rosterNameLines(DiscordCardContext $context): string
    {
        if ($context->rosterMembers === []) {
            return '';
        }

        // Preserve publisher-supplied order within each status group.
        $byStatus = [
            ParticipantStatus::Approved->value => [],
            ParticipantStatus::Waitlisted->value => [],
            ParticipantStatus::Benched->value => [],
        ];
        foreach ($context->rosterMembers as $member) {
            $key = $member->status->value;
            if (isset($byStatus[$key])) {
                $byStatus[$key][] = $member;
            }
        }

        // Lower the per-group cap until the combined field fits. Caps step
        // 12 → 8 → 5 → 3 → 1 → 0; at 0 only the roundup tails remain (short),
        // which always fits.
        foreach ([12, 8, 5, 3, 1, 0] as $cap) {
            $lines = [
                $this->rosterGroupLine($this->trans('discord.content_card_roster_in', [], $context->locale), $byStatus[ParticipantStatus::Approved->value], $context->approvedCount, $cap, $context->locale),
            ];
            if ($context->waitlistCount > 0) {
                $lines[] = $this->rosterGroupLine(
                    $this->trans('discord.content_card_roster_waitlist', ['count' => $context->waitlistCount], $context->locale),
                    $byStatus[ParticipantStatus::Waitlisted->value],
                    $context->waitlistCount,
                    $cap,
                    $context->locale,
                );
            }
            if ($context->benchedCount > 0) {
                $lines[] = $this->rosterGroupLine(
                    $this->trans('discord.content_card_roster_bench', ['count' => $context->benchedCount], $context->locale),
                    $byStatus[ParticipantStatus::Benched->value],
                    $context->benchedCount,
                    $cap,
                    $context->locale,
                );
            }
            $joined = trim(implode("\n", array_filter($lines, fn ($l) => $l !== '')));
            if ($joined === '' || strlen($joined) <= 1000 || $cap === 0) {
                return $joined;
            }
        }

        return '';
    }

    /**
     * One roster group line: `**Header:** @name, @name · +y more · +x from roundup`.
     *
     * @param  list<DiscordRosterMember>  $members  Discord-linked members of this roster (publisher-ordered).
     * @param  int  $totalCount  Total participants in this roster (linked + unlinked).
     * @param  int  $cap  Max linked names to render (overflow folds to "+y more").
     */
    private function rosterGroupLine(string $header, array $members, int $totalCount, int $cap, ?string $locale = null): string
    {
        if ($totalCount <= 0 && $members === []) {
            return '';
        }

        $parts = [];
        $shown = array_slice($members, 0, $cap);
        foreach ($shown as $member) {
            $label = $this->sanitizeNameLabel($member->label);
            $parts[] = "[@{$label}](https://discord.com/users/{$member->snowflake})";
        }

        $moreLinked = count($members) - count($shown);
        if ($moreLinked > 0) {
            $parts[] = $this->trans('discord.content_card_more', ['count' => $moreLinked], $locale);
        }

        $roundupOnly = max(0, $totalCount - count($members));
        if ($roundupOnly > 0) {
            $parts[] = $this->trans('discord.content_card_from_roundup', ['count' => $roundupOnly], $locale);
        }

        if ($parts === []) {
            return '';
        }

        return "**{$header}:** ".implode(' · ', $parts);
    }

    /**
     * Make a Discord nickname safe to embed inside a markdown link label.
     *
     * Strips the characters that would break `[@label](url)` parsing
     * (`[`, `]`, `(`, `)`, backslash, backtick) and caps length so a long
     * nickname cannot blow the field-value budget. Discord nicknames are
     * already capped at 32 chars server-side, so this is defense-in-depth.
     */
    private function sanitizeNameLabel(string $label): string
    {
        $clean = str_replace(['[', ']', '(', ')', '\\', '`'], '', $label);

        return Str::limit(trim($clean), 32, '');
    }

    /**
     * @return array{name: string, value: string, inline: bool}|null
     */
    private function systemsField(Game $game, ?string $locale = null): ?array
    {
        $systems = $this->loadedSystems($game);
        if ($systems->isEmpty()) {
            return null;
        }

        $labels = $systems->map(fn (GameSystem $system) => $system->name)->filter()->values();
        if ($labels->isEmpty()) {
            return null;
        }

        return ['name' => $this->trans('discord.content_card_field_system', [], $locale), 'value' => implode(' · ', $labels->all()), 'inline' => true];
    }

    /**
     * The organizer trust line — roundup's headline differentiator. Omitted
     * entirely when the owner has no persisted reliability score (a brand-new
     * organizer), rather than showing a misleading "0%".
     *
     * @return array{name: string, value: string, inline: bool}|null
     */
    private function trustField(Game $game, ?string $locale = null): ?array
    {
        $owner = $this->loadedOwner($game);
        if (! $owner) {
            return null;
        }

        $score = is_array($owner->reliability_score) ? $owner->reliability_score : null;
        if ($score === null) {
            return null;
        }

        $tierRaw = $score['tier'] ?? null;
        $tier = is_string($tierRaw) ? $tierRaw : 'newcomer';
        $gamesRaw = $score['game_count'] ?? null;
        $games = is_int($gamesRaw) ? $gamesRaw : null;
        $scoreRaw = $score['score'] ?? null;
        $pct = is_numeric($scoreRaw) ? (int) round((float) $scoreRaw) : null;

        $badge = match ($tier) {
            'reliable' => $this->trans('discord.content_card_tier_reliable', [], $locale),
            'active' => $this->trans('discord.content_card_tier_active', [], $locale),
            default => $this->trans('discord.content_card_tier_newcomer', [], $locale),
        };

        $parts = [$badge];
        if ($pct !== null) {
            $parts[] = $this->trans('discord.content_card_reliable_pct', ['pct' => $pct], $locale);
        }
        if ($games !== null && $games > 0) {
            $parts[] = Lang::choice('discord.content_card_games_hosted', $games, ['count' => $games], $this->resolveLocale($locale));
        }

        return ['name' => $this->trans('discord.content_card_field_organizer', [], $locale), 'value' => implode(' · ', $parts), 'inline' => true];
    }

    /**
     * Venue with map link and operational parameters. Omitted entirely when the
     * Game has no linked Location (the verify contract's missing-venue case).
     *
     * @return array{name: string, value: string, inline: bool}|null
     */
    private function venueField(Game $game, ?string $locale = null): ?array
    {
        $location = $this->loadedLocation($game);
        if (! $location) {
            return null;
        }

        $lines = [];

        // Venue name, hyperlinked to its website when one is on record.
        $name = trim((string) $location->name);
        $website = $this->urlOrNull($location->website_url);
        if ($name !== '') {
            $lines[] = $website !== null ? "[**{$name}**]({$website})" : "**{$name}**";
        } elseif ($website !== null) {
            $lines[] = '[**'.$this->trans('discord.content_card_field_venue', [], $locale).'**]('.$website.')';
        }

        // Map link — lat/lng when available (precise), else a name/address query.
        $mapUrl = $this->mapUrl($location);
        if ($mapUrl !== null) {
            $lines[] = '🗺️ ['.$this->trans('discord.content_card_open_in_maps', [], $locale).']('.$mapUrl.')';
        }

        // Operational parameters surfaced from venue_metadata (M055/S05).
        $params = $this->venueParams($location);
        if ($params !== '') {
            $lines[] = $params;
        }

        if ($lines === []) {
            return null;
        }

        return ['name' => $this->trans('discord.content_card_field_venue', [], $locale), 'value' => implode("\n", $lines), 'inline' => false];
    }

    /**
     * The cross-community attendee indicator — omitted when the count is zero
     * (the verify contract's zero-cross-community case). Framed positively per
     * the CONTEXT risk note ("the community is growing", not "who are these
     * randoms").
     *
     * @return array{name: string, value: string, inline: bool}|null
     */
    private function crossCommunityField(DiscordCardContext $context): ?array
    {
        if ($context->crossCommunityAttendeeCount <= 0) {
            return null;
        }

        $count = $context->crossCommunityAttendeeCount;

        if ($context->guildName !== null && $context->guildName !== '') {
            $value = $this->trans('discord.content_card_cross_value_outside', ['count' => $count, 'guild' => $context->guildName], $context->locale);
        } else {
            $value = $this->trans('discord.content_card_cross_value_generic', ['count' => $count], $context->locale);
        }

        return [
            'name' => $this->trans('discord.content_card_field_cross_community', [], $context->locale),
            'value' => $value,
            'inline' => false,
        ];
    }

    // ── Components (button row) ─────────────────────────

    /**
     * One action row: a primary "Join" button (handled by the HTTP Interactions
     * endpoint, carrying the game_id in its custom_id) plus a link button to
     * the roundup game page (the fallback for members who haven't linked
     * Discord, and the "view full details" affordance).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildComponents(Game $game, DiscordCardContext $context): array
    {
        $gameId = (string) $game->id;
        $deepLink = $this->deepLink($game, $context);

        $buttons = [
            [
                'type' => 2, // BUTTON
                'style' => 1, // PRIMARY (blurple)
                'label' => $this->trans('discord.content_card_button_my_seat', [], $context->locale),
                // custom_id carries the game_id so the stateless Interactions
                // endpoint (D117) can resolve the target game. Namespaced so
                // roundup interactions never collide with another bot's.
                'custom_id' => "roundup:rsvp:{$gameId}",
            ],
            [
                'type' => 2, // BUTTON
                'style' => 5, // LINK
                'label' => $this->trans('discord.content_card_button_view', [], $context->locale),
                'url' => $deepLink,
            ],
        ];

        return [
            [
                'type' => 1, // ACTION_ROW
                'components' => $buttons,
            ],
        ];
    }

    // ── Embed primitives ────────────────────────────────

    private function title(Game $game): string
    {
        return Str::limit(trim((string) $game->name), 250);
    }

    private function description(Game $game): string
    {
        $raw = trim((string) $game->description);
        if ($raw === '') {
            return '';
        }

        $clean = trim(strip_tags($raw));

        return $clean === '' ? '' : Str::limit($clean, 300);
    }

    /**
     * @return array{name: string, url?: string, icon_url?: string}
     */
    private function author(User $owner, DiscordCardContext $context): array
    {
        $author = ['name' => Str::limit(trim((string) $owner->name), 100)];

        $profile = $this->profileUrl($owner, $context);
        if ($profile !== null) {
            $author['url'] = $profile;
        }

        $avatar = $this->urlOrNull($owner->avatar_url);
        if ($avatar !== null) {
            $author['icon_url'] = $avatar;
        }

        return $author;
    }

    private function profileUrl(User $owner, DiscordCardContext $context): ?string
    {
        $username = $owner->username;
        if (! is_string($username) || $username === '') {
            return null;
        }

        return rtrim($this->appUrl($context), '/').'/@'.$username;
    }

    private function deepLink(Game $game, DiscordCardContext $context): string
    {
        return rtrim($this->appUrl($context), '/').'/games/'.(string) $game->id;
    }

    private function timestamp(Game $game): ?string
    {
        return $game->date_time?->toIso8601String();
    }

    private function duration(Game $game): string
    {
        $hours = $this->floatOrNull($game->expected_duration);
        if ($hours === null || $hours <= 0) {
            return '';
        }

        $whole = (int) $hours;
        $minutes = (int) round(($hours - $whole) * 60);

        if ($minutes === 0) {
            return "{$whole}h";
        }
        if ($whole === 0) {
            return "{$minutes}m";
        }

        return "{$whole}h {$minutes}m";
    }

    private function color(Game $game): int
    {
        return match ($game->status) {
            GameStatus::Canceled => self::COLOR_CANCELED,
            GameStatus::Completed => self::COLOR_COMPLETED,
            default => self::COLOR_BRAND,
        };
    }

    /**
     * Build a Google Maps search URL from the Location. Prefers precise
     * lat/lng; falls back to a name + address + city query; returns null when
     * there is nothing addressable.
     */
    private function mapUrl(Location $location): ?string
    {
        // Coordinates: 0.0 is a valid latitude/longitude, so null-check the
        // raw value rather than coercing through floatOrNull (which treats 0.0
        // as "unset" — correct for duration, wrong for geo).
        $lat = $location->latitude !== null ? (float) $location->latitude : null;
        $lng = $location->longitude !== null ? (float) $location->longitude : null;

        if ($lat !== null && $lng !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode("{$lat},{$lng}");
        }

        $query = trim(implode(', ', array_filter([
            (string) $location->name,
            (string) $location->address,
            (string) $location->city,
        ], fn ($v) => trim($v) !== '')));

        if ($query === '') {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }

    /**
     * Render the venue operational parameters (fee_display, overlap_guidance,
     * house_rules) as a compact single line. Omitted entirely when none are set.
     */
    private function venueParams(Location $location): string
    {
        $metadata = is_array($location->venue_metadata) ? $location->venue_metadata : [];

        $parts = [];
        $feeRaw = $metadata['fee_display'] ?? '';
        $fee = is_string($feeRaw) ? trim($feeRaw) : '';
        if ($fee !== '') {
            $parts[] = "🎟️ {$fee}";
        }
        $overlapRaw = $metadata['overlap_guidance'] ?? '';
        $overlap = is_string($overlapRaw) ? trim($overlapRaw) : '';
        if ($overlap !== '') {
            $parts[] = '⚠️ '.Str::limit($overlap, 120);
        }
        $rulesRaw = $metadata['house_rules'] ?? '';
        $rules = is_string($rulesRaw) ? trim($rulesRaw) : '';
        if ($rules !== '') {
            $parts[] = '📜 '.Str::limit($rules, 120);
        }

        return implode(' · ', $parts);
    }

    // ── Relation reads (pure — cache-only, never query) ──

    /**
     * Read the owner from the relation cache without triggering a lazy query.
     */
    private function loadedOwner(Game $game): ?User
    {
        if (! $game->relationLoaded('owner')) {
            return null;
        }

        $owner = $game->owner;

        return $owner instanceof User ? $owner : null;
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

    private function loadedLocation(Game $game): ?Location
    {
        if (! $game->relationLoaded('linkedLocation')) {
            return null;
        }

        $location = $game->linkedLocation;

        return $location instanceof Location ? $location : null;
    }

    // ── Coercion helpers ────────────────────────────────

    private function appUrl(DiscordCardContext $context): string
    {
        $url = $context->appUrl ?? (is_string(config('app.url')) ? config('app.url') : null);

        return $url !== null && $url !== '' ? $url : 'http://localhost';
    }

    private function urlOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int >= 0 ? $int : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float !== 0.0 ? $float : null;
    }

    // ── Locale ──────────────────────────────────────────

    /**
     * Resolve a non-empty locale string, falling back to the configured
     * fallback locale (then 'en') so Lang never receives null.
     */
    private function resolveLocale(?string $locale): string
    {
        $resolved = ($locale !== null && $locale !== '') ? $locale : config('app.fallback_locale', 'en');

        return is_string($resolved) && $resolved !== '' ? $resolved : 'en';
    }

    /**
     * Translate a card string in the guild's locale. Keeps the renderer's text
     * audience-correct (the card is read by guild members) while staying a pure
     * function of (key, replace, locale) — Lang::get is a cached, stateless read.
     *
     * @param  array<string, string|int>  $replace
     */
    private function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return Lang::get($key, $replace, $this->resolveLocale($locale));
    }
}
