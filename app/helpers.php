<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Resolve the preferred locale for the current request.
 *
 * Priority: session > Accept-Language header > app fallback.
 * Used by the root redirect and the locale-less URL catch-all.
 */
if (! function_exists('resolvePreferredLocale')) {
    function resolvePreferredLocale(): string
    {
        $availableLocales = config('app.available_locales');
        $availableLocales = is_array($availableLocales) ? array_filter($availableLocales, 'is_string') : [];
        /** @var string[] $availableLocales */
        $locale = session('locale')
            ?? Request::getPreferredLanguage($availableLocales)
            ?? config('app.fallback_locale');

        if (! is_string($locale) || ! in_array($locale, $availableLocales, true)) {
            $locale = is_string($fallback = config('app.fallback_locale')) ? $fallback : 'en';
        }

        return $locale;
    }
}

/**
 * The community wall-clock timezone identifier (R089): the single pinned
 * zone behind community_now() and every "community display zone" render
 * (Discord cards/digests, iCal all-day fallback, dashboard buckets).
 *
 * Storage stays UTC; this zone only names HUMAN wall-calendar concepts —
 * "today", "this week", streak buckets, community display times. Swap it
 * here alone if the community default ever moves; per-user zones live in
 * users.timezone and are never resolved through this helper.
 */
if (! function_exists('community_timezone')) {
    function community_timezone(): string
    {
        return 'Europe/Berlin';
    }
}

/**
 * Resolve the display timezone for the current viewer (R089).
 *
 * The viewer's captured IANA timezone when logged in, else the community
 * default. Captured values are validated at every write edge (login,
 * OAuth, beacon, profile) via canonical_timezone(), so the string is
 * always a constructible identifier.
 */
if (! function_exists('viewer_timezone')) {
    function viewer_timezone(): string
    {
        $timezone = auth()->user()?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : community_timezone();
    }
}

/**
 * "Now" in the community wall clock (community_timezone()).
 *
 * Calendar bucketing that names a human wall-calendar concept — "today",
 * "this week", "this month", quarter/month streak buckets — must resolve
 * its boundaries against ONE platform-wide zone instead of the app's UTC
 * storage timezone or an individual viewer's device zone (R089: cohorts
 * and counters stay deterministic no matter who is looking).
 *
 * When a bucket boundary feeds a database comparison, convert it back with
 * ->utc() first — Eloquent binds a Carbon using its OWN timezone's wall
 * string, and the columns store UTC instants.
 */
if (! function_exists('community_now')) {
    function community_now(): Carbon
    {
        return Carbon::now(community_timezone());
    }
}

/**
 * Validate and canonicalize a browser-reported timezone identifier (R089).
 *
 * Accepts tzdata aliases (CLDR primaries like Asia/Calcutta and
 * America/Buenos_Aires) that DateTimeZone::listIdentifiers() — and with it
 * Laravel's `timezone` validation rule, which is a list lookup — wrongly
 * rejects; the DateTimeZone constructor is the authoritative parser.
 * Etc/Unknown, offsets like "UTC+02:00", and garbage throw in the
 * constructor and map to null.
 *
 * @return string|null The identifier exactly as DateTimeZone reports it, or null when invalid.
 */
if (! function_exists('canonical_timezone')) {
    function canonical_timezone(mixed $timezone): ?string
    {
        if (! is_string($timezone) || $timezone === '' || strlen($timezone) > 64) {
            return null;
        }

        try {
            return (new DateTimeZone($timezone))->getName();
        } catch (Exception) {
            return null;
        }
    }
}

if (! function_exists('timezone_identifier_options')) {
    /**
     * Identifier list for timezone selects, with a stored device-captured
     * tzdata alias surfaced when it is missing from the canonical list
     * (R089).
     *
     * canonical_timezone() accepts aliases (Asia/Calcutta) that
     * DateTimeZone::listIdentifiers() omits, so a user's stored zone can
     * be a value no canonical-list select offers. Feeding such a select
     * without surfacing the stored value renders it blank — and on save
     * the select dehydrates empty, silently wiping the stored zone. This
     * single source (profile partial + Filament UserResource) keeps every
     * timezone select alias-safe: the stored identifier is prepended when
     * the canonical list lacks it.
     *
     * @param  string|null  $ensureIncluded  A stored identifier to guarantee present (canonical values pass through unchanged, null/'' ignored).
     * @return array<int, string>
     */
    function timezone_identifier_options(?string $ensureIncluded = null): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        if ($ensureIncluded !== null && $ensureIncluded !== '' && ! in_array($ensureIncluded, $identifiers, true)) {
            array_unshift($identifiers, $ensureIncluded);
        }

        return $identifiers;
    }
}

/**
 * Convert a user-entered wall-clock datetime into a true UTC instant (R089).
 *
 * The input edge: datetime-local inputs carry the ENTERING user's wall
 * clock, so the string is parsed in their captured timezone (community
 * fallback Europe/Berlin) and converted to UTC before persistence.
 *
 * The parse zone resolves from the ambient auth state by default — exactly
 * right for in-request Livewire forms, where the entering user IS the
 * authenticated user. Any context where the two can differ (queue job,
 * token-auth API, acting-on-behalf) MUST pass the entering user's zone
 * explicitly: parsing in the wrong zone silently shifts the stored instant
 * by the offset delta.
 *
 * @param  string|null  $value  Raw input value ('Y-m-d\TH:i' from datetime-local pickers). Empty/null maps to null.
 * @param  string|null  $timezone  Explicit parse zone (the entering user's IANA zone); null = the current viewer's.
 */
if (! function_exists('wall_time_to_utc')) {
    function wall_time_to_utc(?string $value, ?string $timezone = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, $timezone ?? viewer_timezone())->setTimezone('UTC');
    }
}

/**
 * Does the locale render clock times on a 24-hour dial? (R089)
 *
 * Shared clock convention between format_date() and notification time
 * rendering — de gets "19:00 CET", en gets "7:00 PM CET".
 */
if (! function_exists('is_24_hour_locale')) {
    function is_24_hour_locale(string $locale): bool
    {
        return in_array($locale, ['de', 'fr', 'es', 'it', 'nl', 'pt', 'pl', 'cs', 'ru', 'ja', 'zh', 'ko'], true);
    }
}

/**
 * Format a Carbon date according to the current app locale.
 *
 * The instant is converted to the VIEWER's timezone at render time
 * (captured IANA zone, community fallback) unless an explicit $timezone
 * is passed — notifications/emails pass the RECIPIENT's zone. All types
 * below show the converted wall time.
 *
 * Convention (R089): generic views render the viewer's wall time WITHOUT a
 * zone marker — the same page legitimately differs per viewer. Recipient-
 * addressed copy (notifications) appends the abbreviation ("19:00 CET")
 * instead; do not add markers here without a product decision.
 *
 * Types:
 *  - 'date'            EN: "Apr 14, 2026"  / DE: "14. April 2026"
 *  - 'short_date'      EN: "Apr 14"        / DE: "14. Apr"
 *  - 'datetime'        EN: "Apr 14, 2026 at 2:30 PM" / DE: "14. April 2026, 14:30"
 *  - 'weekday_datetime' EN: "Tue, Apr 14, 14:30" / DE: "Di., 14. Apr, 14:30"
 *  - 'short_month_day' EN: "Apr 14, 2026"  / DE: same as 'date'
 */
if (! function_exists('format_date')) {
    function format_date(?Carbon $date, string $type = 'date', ?string $timezone = null): string
    {
        if ($date === null) {
            return '';
        }

        $locale = app()->getLocale();

        $is24Hour = is_24_hour_locale($locale);

        // Copy first: Carbon is mutable and the caller's instance (often a
        // freshly hydrated model attribute) must not shift timezone.
        $date = $date->copy()->setTimezone($timezone ?? viewer_timezone());

        return match ($type) {
            'date' => $date->isoFormat($is24Hour ? 'D. MMMM YYYY' : 'MMM D, YYYY'),
            'short_date' => $date->isoFormat($is24Hour ? 'D. MMM' : 'MMM D'),
            'datetime' => $date->isoFormat($is24Hour ? 'D. MMMM YYYY, HH:mm' : 'MMM D, YYYY [at] h:mm A'),
            'weekday_datetime' => $date->isoFormat($is24Hour ? 'ddd, D. MMM, HH:mm' : 'ddd, MMM D, HH:mm'),
            'short_month_day' => $date->isoFormat($is24Hour ? 'D. MMMM YYYY' : 'MMM D, YYYY'),
            default => $date->isoFormat($is24Hour ? 'D. MMMM YYYY' : 'MMM D, YYYY'),
        };
    }
}

/**
 * Format a monetary value according to the current app locale.
 *
 * Zero values show "Free" / translated equivalent via __().
 *
 * @param  int|float  $amount  The amount to format (cents when $inCents=true, whole currency when false).
 * @param  bool  $inCents  True for event fees (stored as cents), false for game/campaign prices (stored as float dollars).
 */
if (! function_exists('format_currency')) {
    function format_currency(int|float $amount, bool $inCents = true): string
    {
        $value = $inCents ? $amount / 100 : $amount;

        if ((float) $value === 0.0) {
            return __('common.price_free');
        }

        $locale = app()->getLocale();

        // European locales use comma decimal, period thousands, € suffix
        $europeanLocales = ['de', 'fr', 'es', 'it', 'nl', 'pt', 'pl', 'cs', 'ru'];

        if (in_array($locale, $europeanLocales, true)) {
            return number_format($value, 2, ',', '.').' €';
        }

        // English and other locales: $ prefix, period decimal
        return '$'.number_format($value, 2);
    }
}

/**
 * Get the currently authenticated user, asserting non-null.
 *
 * Use this instead of Auth::user() in any context guaranteed
 * to be behind auth middleware. The return type is non-nullable
 * so PHPStan and IDEs can infer User instead of User|null.
 */
function authenticatedUser(): User
{
    $user = Auth::user();

    assert($user instanceof User, 'Expected authenticated user');

    return $user;
}

/**
 * Coerce a value to a non-empty string identifier, or '' if it is not a string
 * (int values are accepted as a legacy bridge but the result is always a string).
 * Booleans, floats, null, arrays, and objects collapse to the empty string.
 *
 * Use to normalise mixed-sourced identifiers (decoded JSON, cache hits, pluck
 * results, wireables) before string comparison or storage. The typed `string`
 * return makes this PHPStan-clean without relying on type narrowing.
 */
function to_string_id(mixed $value): string
{
    return is_string($value) || is_int($value) ? (string) $value : '';
}

/**
 * Narrow a collection of mixed id values to a string[] array.
 *
 * The collection shape produced by Eloquent `->pluck()` is a `Collection<int, mixed>`
 * under PHPStan, even when every element is a UUID string in practice. This helper
 * applies `to_string_id()` per element, drops empties, reindexes, and returns a
 * typed `array<int, string>` so downstream `whereIn`/array operations are
 * PHPStan-clean without per-call-site string[] phpdoc annotations.
 *
 * Replaces the duplicated `->filter(fn ($id) => is_string($id))->values()->toArray()`
 * chain that appeared in ActionCenterService, DashboardNewcomerService, and
 * SocialGraphService.
 *
 * @param  Collection<int, mixed>  $values
 * @return array<int, string>
 */
function to_string_id_array(Collection $values): array
{
    return $values->map(fn (mixed $id): string => to_string_id($id))
        ->filter(fn (string $id) => $id !== '')
        ->values()
        ->all();
}

/**
 * Format bytes into a human-readable string (e.g. "1.5 MB").
 *
 * Shared by data export command and Filament ticket actions.
 */
function format_bytes(int $bytes): string
{
    if ($bytes < 0) {
        $bytes = 0;
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 2).' '.$units[$i];
}

/**
 * Return a URL only when it is a safe http(s) href target; null otherwise.
 *
 * Input flows validate the `url` rule, but admin (Filament), factory, and
 * legacy rows can bypass that validation. Render sites route any user-facing
 * URL through here rather than trusting a raw column, so a stored
 * `javascript:`/`data:` URI (or any non-http scheme) can never become a live
 * XSS vector in an href attribute.
 *
 * Accepts null and returns null so it composes cleanly with nullable
 * columns. In Blade, use an assign-in-condition so the helper runs once
 * rather than twice (e.g. check `safe_url($model->website_url)` and bind
 * the truthy result to a local for the href in the same expression).
 */
function safe_url(?string $url): ?string
{
    if ($url === null) {
        return null;
    }

    return preg_match('#^https?://#i', $url) === 1 ? $url : null;
}

/**
 * Read an integer config value safely.
 *
 * `config()` returns mixed; PHPStan (level 9) rejects `(int) mixed`. This
 * helper narrows the value: if it's numeric (int, float, or a numeric string)
 * it casts to int; otherwise it returns the default. The default is returned
 * unchanged when the key is absent or the stored value is non-numeric
 * (e.g. null or a stray string), so callers always get a usable integer.
 */
if (! function_exists('config_int')) {
    function config_int(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}

if (! function_exists('uuid_or_null')) {
    /**
     * Coerce a mixed value to a valid uuid string, or null when it is not one.
     *
     * Canonical guard for id values that originate from untyped sources
     * (URL query strings, Livewire public properties) and are about to be
     * bound to a uuid column. A malformed value cannot match a uuid column,
     * but Postgres rejects it at bind time (SQLSTATE 22P02) and turns the
     * request into a 500 — callers treat null as "no filter". Model id
     * lookups are guarded separately by App\Models\Builders\UuidBuilder.
     */
    function uuid_or_null(mixed $value): ?string
    {
        $string = is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);

        return $string !== null && Str::isUuid($string) ? $string : null;
    }
}

if (! function_exists('uuid_array')) {
    /**
     * Coerce a mixed value to a list of valid uuid strings, dropping every
     * value that is not a uuid. Canonical guard for multi-id filters — see
     * uuid_or_null() for why malformed ids must not reach a uuid column.
     *
     * @return array<int, string>
     */
    function uuid_array(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $uuids = [];
        foreach ($values as $value) {
            $uuid = uuid_or_null($value);
            if ($uuid !== null) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }
}
