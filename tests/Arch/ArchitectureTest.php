<?php

use Symfony\Component\Finder\Finder;

/**
 * Architecture guardrails (adopted from the Traceway Laravel 13 writeup, r/laravel
 * 1xgm9el). Same philosophy as scripts/check_eloquent_practices.sh: high-precision,
 * zero false positives, pinned to zero baseline violations. CI-gating via the Pest
 * suite. When a rule blocks legitimate work, extend the documented ignore list in
 * the same change and leave a comment saying why — never weaken the rule.
 *
 * Performance shape (M073/S03-T03): every rule scans ONLY its target directory.
 * The arch plugin builds — and fully parses — one layer per `toUse()` dependency
 * namespace (a whole-directory scan each), so namespace-wide `not->toUse` rules
 * re-scanned most of app/ plus two vendor trees per rule (~590 files for the enum
 * rule, 747 for the HTTP rule; suite was 11.9s+). Each rule below is expressed as
 * either `toUseNothing()` plus an explicit allowlist (one target-dir scan) or a
 * textual prefilter feeding a small `not->toUse()` suspect list. Failure surfaces
 * are preserved or strictly tightened — see each rule's mapping comment.
 */
arch('value objects are dependency-free')
    ->expect('App\Values')
    ->toUseNothing()
    // The __() translation helper is the only sanctioned global function.
    ->ignoring('__');

/*
 * Mapping (was): `->expect('App\Enums')->not->toUse([Illuminate\Database\Eloquent,
 * Illuminate\Support\Facades, App\Models, App\Services, App\Http, App\Livewire,
 * App\Filament, App\Console])` — eight dependency layers, ~590 file parses.
 * Mapping (now): `toUseNothing()` scans only app/Enums (46 files). Every previous
 * ban still fails: Eloquent and Facades references (any facade class — the old
 * layer membership check and the allowlist strip agree), and all six App
 * namespaces. The rule is strictly stronger: enums may now depend on nothing but
 * their enum siblings and the __() helper. The three grandfathered stragglers
 * (dashboard cache coupling, Discord RSVP/CARD side effects) stay exempt exactly
 * as before — exempt from the whole rule — and must keep their `ignoring` entries,
 * which also strip sibling references to them.
 */
arch('enums stay dependency-light')
    ->expect('App\Enums')
    ->toUseNothing()
    // Sanctioned helpers: the __() translation helper everywhere, and the
    // collect() collection helper (VibeFlag's flag grouping).
    ->ignoring(['__', 'collect'])
    // Pre-existing stragglers: dashboard cache coupling and Discord RSVP side
    // effects. Ignore-listed, not refactored, until they are deliberately moved.
    ->ignoring([
        'App\Enums\DashboardSection',
        'App\Enums\DiscordCardStatus',
        'App\Enums\DiscordRsvpOutcome',
    ]);

/*
 * Mapping (was): `not->toUse([App\Http, App\Livewire, App\Filament, App\Console])`
 * — four dependency layers, ~275 file parses. Mapping (now): `toUseNothing()`
 * scans only app/Dto (33 files) against this allowlist of every dependency shape
 * Dtos legitimately take today (domain models, services, enums, contracts, value
 * primitives like Carbon/Collection). The four presentation/transport bans still
 * fail; anything else new must be added to the allowlist deliberately.
 */
arch('dto shapes stay free of presentation and transport layers')
    ->expect('App\Dto')
    ->toUseNothing()
    ->ignoring([
        'App\Contracts',
        'App\Enums',
        'App\Models',
        'App\Services',
        'Carbon\Carbon',
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Notifications\DatabaseNotification',
        'Illuminate\Support',
        // Sanctioned global helpers (validation, time, uuid, app helpers).
        '__', 'now', 'collect', 'filled', 'community_now', 'uuid_or_null', 'uuid_array',
    ]);

/*
 * Mapping (was): `not->toUse([App\Http, App\Livewire, App\Filament, App\Console])`
 * on App\Contracts. Mapping (now): `toUseNothing()` scans the single contract
 * file against its legitimate domain dependencies. Same four bans preserved.
 */
arch('contracts stay free of presentation and transport layers')
    ->expect('App\Contracts')
    ->toUseNothing()
    ->ignoring([
        'App\Dto',
        'App\Enums',
        'App\Models',
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Support\Carbon',
    ]);

/*
 * Mapping (was): `->expect('App')->not->toUse('Illuminate\Support\Facades\Http')`
 * — the App target layer is a 747-file parse. Mapping (now): a textual prefilter
 * picks the suspects and each goes through the identical arch assertion, one
 * class at a time inside one Pest test.
 *
 * Soundness of the prefilter (why the violation surface is identical): a file can
 * only reference the Http facade if the reference is textual — an import
 * (`use Illuminate\Support\Facades\Http;` or a group import), an aliased import,
 * or a fully-qualified usage. Every one of those forms puts BOTH the substrings
 * "Facades" and "Http" in the file, so {contains "Facades"} ∧ {contains "Http"}
 * is a superset of every file the old App-wide arch scan could flag; the arch
 * `not->toUse('Illuminate\Support\Facades\Http')` per suspect remains the
 * semantic check and non-suspects are provably clean.
 *
 * Why one class per expect() call instead of passing the whole array: arch's
 * `not->toUse()` over an ARRAY of targets silently passes when any target is a
 * non-user — inside the plugin, a non-using target throws the positive
 * "Expecting X to use Y" failure, which suppresses the not-negation for the
 * whole expectation. Single-target expectations have the correct semantics
 * (fail iff that class uses the facade), so the loop is per-class.
 */
it('outbound HTTP is confined to client classes', function () {
    $suspects = outboundHttpSuspects();

    expect($suspects)->not->toBeEmpty(
        'Http-facade prefilter found no suspects — the app scan surface changed.'
    );

    foreach ($suspects as $class) {
        expect($class)->not->toUse('Illuminate\Support\Facades\Http');
    }
});

/**
 * Classes under app/ that textually reference the Http facade, minus the
 * sanctioned egress surface — a provable superset of arch's own detection set
 * (see the mapping comment above the rule that consumes this list).
 *
 * @return array<int, class-string>
 */
function outboundHttpSuspects(): array
{
    // Sanctioned egress: dedicated client classes under App\Services\<Provider>
    // and App\SEO. Stragglers below are deliberate exceptions (OAuth callback
    // flow, Cloudflare cache-rules sync) — see .ai/rules/app.md. New outbound
    // calls must go through a client class, never join this list silently.
    $sanctioned = [
        'App\Services',
        'App\SEO',
        'App\Http\Controllers\Auth\OAuthController',
        'App\Console\Commands\SyncCloudflareCacheRules',
    ];

    $suspects = [];

    $files = Finder::create()
        ->files()
        ->in(dirname(__DIR__, 2).'/app')
        ->name('*.php')
        ->sortByName();

    foreach ($files as $file) {
        $source = (string) file_get_contents((string) $file->getRealPath());

        if (! str_contains($source, 'Facades') || ! str_contains($source, 'Http')) {
            continue;
        }

        $class = primaryDeclaredClassName($source);

        if ($class === null) {
            continue;
        }

        foreach ($sanctioned as $prefix) {
            if (str_starts_with($class, $prefix)) {
                continue 2;
            }
        }

        $suspects[] = $class;
    }

    return array_values(array_unique($suspects));
}

/*
 * R093 (M073/S05): test code must not perform real-time waits —
 * elapsed-time behavior is virtualized via Sleep::fake (TestCase wires it
 * suite-wide) / Carbon travel / RateLimiter window shrinking. Native
 * sleep()/usleep()/time_nanosleep()/time_sleep_until() calls grow suite time
 * with wall-clock semantics no assertion needs and bypass Sleep::fake
 * invisibly: of the 2026-11-02 sleeper hypothesis's nine claims, eight were
 * ambient-load noise, but the one real case (PostHog SDK network wait) hid
 * real elapsed time from every profile consumer (see 73-01-CONTEXT.md).
 *
 * Why a tokenizer scan and NOT a layer expectation
 * (`->expect('Tests')->not->toUse('sleep')`): the arch layer only describes
 * class-bearing files — ObjectDescriptionBase::make returns null when a
 * file declares no class/trait/interface/enum — so a layer rule is blind to
 * every Pest-style test file (function-only bodies, exactly where a stray
 * sleep() would land) AND to bootstrap.php alike. A token-level pass over
 * every tests/*.php file is the provable superset: it covers Pest test
 * bodies plus the class-bearing helpers (TestCase, Helpers, Traits,
 * Fixtures), while the single legitimate caller — tests/bootstrap.php's
 * Testcontainers readiness polls, which gate a real Docker PostgreSQL
 * container and cannot be virtualized — is excluded by exact path (the R093
 * carve-out; do not "fix" the bootstrap waits).
 */
it('test code waits only on virtualized time', function () {
    $violations = nativeSleepViolations();

    expect($violations)->toBeEmpty(
        'Native sleep calls in test code — virtualize elapsed time via Sleep::fake()/travel() instead. '
        .'(tests/bootstrap.php Testcontainers readiness polls are the only carve-out.)'
        .($violations === [] ? '' : "\n".implode("\n", $violations))
    );
});

/**
 * Direct calls to native sleep functions in test code under tests/, as
 * `relative/path.php:line` entries — a tokenizer pass over every tests/*.php
 * file except tests/bootstrap.php (R093 carve-out). A textual prefilter keeps
 * the tokenizer off the files that never mention sleep at all.
 *
 * @return array<int, string>
 */
function nativeSleepViolations(): array
{
    $banned = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];

    $violations = [];

    $files = Finder::create()
        ->files()
        ->in(dirname(__DIR__))
        ->name('*.php')
        ->sortByName();

    foreach ($files as $file) {
        // R093 carve-out: the three usleep(500_000) Testcontainers readiness
        // polls in bootstrap.php wait for a real external container to accept
        // connections — harness boot code, not test-body elapsed-time behavior.
        // Compared as the Finder-relative pathname, not getRealPath(), so a
        // symlinked checkout (worktrees) cannot break the match.
        if ($file->getRelativePathname() === 'bootstrap.php') {
            continue;
        }

        $source = (string) file_get_contents((string) $file->getRealPath());

        // Every banned function name contains "sleep" — one case-insensitive
        // prefilter covers all four (and docblock mentions just trigger a scan).
        if (! str_contains(strtolower($source), 'sleep')) {
            continue;
        }

        $violations = [...$violations, ...array_map(
            fn (int $line): string => $file->getRelativePathname().':'.$line,
            nativeSleepCallLines($source, $banned)
        )];
    }

    return $violations;
}

/**
 * Line numbers of direct calls to the given (lowercase) native sleep
 * functions in the source: a T_STRING name followed by "(", excluding
 * method ($x->sleep()), nullsafe ($x?->sleep()), static (X::sleep()) and
 * declaration (function sleep()) forms — those never reach the global
 * functions. Tokenizer-only: no parse, no reflection.
 *
 * @param  array<int, string>  $banned
 * @return array<int, int>
 */
function nativeSleepCallLines(string $source, array $banned): array
{
    /** @var array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    $tokens = token_get_all($source);
    $count = count($tokens);
    $lines = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // Unqualified call names arrive as T_STRING; fully-qualified ones
        // ("\\sleep()") as single-segment T_NAME_FULLY_QUALIFIED. Qualified
        // multi-segment names ("Foo\\sleep()") can never resolve to the
        // global function and stay out of scope, as do comments and strings
        // — the tokenizer only yields these tokens for actual code.
        $callee = null;

        if (is_array($token) && $token[0] === T_STRING) {
            $callee = strtolower($token[1]);
        } elseif (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED && ! str_contains(substr($token[1], 1), '\\')) {
            $callee = strtolower(substr($token[1], 1));
        }

        if ($callee === null || ! in_array($callee, $banned, true)) {
            continue;
        }

        // The name must be called: the next significant token is "(".
        $isCall = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $next = $tokens[$j];

            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $isCall = $next === '(';

            break;
        }

        if (! $isCall) {
            continue;
        }

        // ...but not as a member of another construct: method ($x->sleep()),
        // nullsafe ($x?->sleep()), static (X::sleep(), static::sleep()) calls
        // and declarations/new-expressions — none reach the global function.
        // The operators are array tokens (T_OBJECT_OPERATOR et al.), never
        // bare strings.
        for ($j = $i - 1; $j >= 0; $j--) {
            $previous = $tokens[$j];

            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $notAGlobalCall = is_array($previous)
                && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_FN, T_NEW], true);

            if ($notAGlobalCall) {
                continue 2;
            }

            break;
        }

        $lines[] = $token[2];
    }

    return $lines;
}

/**
 * The first named class-like declaration (class, enum, interface, trait) in the
 * given source, fully qualified — a tokenizer pass with no parse or reflection,
 * mirroring how the arch plugin derives layer names from each file.
 */
function primaryDeclaredClassName(string $source): ?string
{
    $namespace = '';
    /** @var array<int, array{0: int, 1: string, 2: int}|string $tokens */
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = ltrim($next[1], '\\');

                    break;
                }

                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                break; // `namespace {` (braced global namespace) or malformed.
            }

            continue;
        }

        if (! in_array($token[0], [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT], true)) {
            continue;
        }

        // Skip `Foo::class` constants — the previous significant token is `::`.
        for ($j = $i - 1; $j >= 0; $j--) {
            $previous = $tokens[$j];

            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_string($previous) && $previous === '::') {
                continue 2;
            }

            break;
        }

        // The declared name is the next T_STRING; anonymous classes hit
        // something else first (`(`, `{`, T_EXTENDS, ...) and are skipped.
        for ($j = $i + 1; $j < $count; $j++) {
            $next = $tokens[$j];

            if (is_array($next) && $next[0] === T_STRING) {
                return $namespace === '' ? $next[1] : $namespace.'\\'.$next[1];
            }

            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            break;
        }
    }

    return null;
}
