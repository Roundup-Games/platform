<?php

use App\Console\Commands\SyncCloudflareCacheRules;
use ReflectionMethod;

// 2026-11-04 incident regression guard: the Cloudflare "Public pages" cache
// rule must exclude every path whose page embeds per-session state. The
// previous catch-all (locale prefix + cookie bypass only) let the edge cache
// /en/login — serving all cookieless visitors one dead CSRF token with no
// Set-Cookie.

function cf_expression(): string
{
    $command = app(SyncCloudflareCacheRules::class);
    $method = new ReflectionMethod($command, 'buildPublicExpression');
    $method->setAccessible(true);

    return (string) $method->invoke($command, ['exact' => [], 'prefixes' => []]);
}

describe('SyncCloudflareCacheRules — public expression exclusions', function () {
    test('excludes auth-form paths from the cached path set', function () {
        $expression = cf_expression();

        expect($expression)->toContain('not (')
            ->toContain('ends_with(http.request.uri.path, "/login")')
            ->toContain('ends_with(http.request.uri.path, "/register")')
            ->toContain('ends_with(http.request.uri.path, "/logout")')
            ->toContain('http.request.uri.path contains "/forgot-password"')
            ->toContain('http.request.uri.path contains "/reset-password"')
            ->toContain('http.request.uri.path contains "/verify-email"');
    });

    test('keeps the locale-prefix base and anonymous cookie bypass intact', function () {
        $expression = cf_expression();

        expect($expression)->toContain('starts_with(http.request.uri.path, "/en/")')
            ->toContain('starts_with(http.request.uri.path, "/de/")')
            ->toContain('not http.cookie contains "roundup-games-session"')
            ->toContain('not http.cookie contains "XSRF-TOKEN"');
    });
});
