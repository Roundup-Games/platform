<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

// 2026-11-04 incident regression guard: the Cloudflare "Public pages" cache
// rule must exclude every path whose page embeds per-session state. The
// previous catch-all (locale prefix + cookie bypass only) let the edge cache
// /en/login — serving all cookieless visitors one dead CSRF token with no
// Set-Cookie. Drives the command through its public artisan surface with the
// Cloudflare API faked (no reflection: CI promotes non-compound `use`
// warnings on global classes to exceptions — see the BackedEnum precedent).

beforeEach(function () {
    config([
        'services.cloudflare.zone_id' => 'test-zone',
        'services.cloudflare.api_token' => 'test-token',
    ]);

    // Empty current ruleset: the command reports its full desired rule set.
    Http::fake([
        'api.cloudflare.com/*/rulesets/phases/http_request_cache_settings/entrypoint' => Http::response([
            'result' => ['id' => 'rs-test', 'rules' => []],
            'success' => true,
        ]),
    ]);
});

function cf_dry_run_output(): string
{
    $exitCode = Artisan::call('cloudflare:cache-rules', ['--dry-run' => true]);
    expect($exitCode)->toBe(0);

    return (string) preg_replace('/\s+/', ' ', Artisan::output());
}

describe('SyncCloudflareCacheRules — public expression exclusions', function () {
    test('excludes auth-form paths from the cached path set', function () {
        $output = cf_dry_run_output();

        expect($output)->toContain('ends_with(http.request.uri.path, "/login")')
            ->and($output)->toContain('ends_with(http.request.uri.path, "/register")')
            ->and($output)->toContain('ends_with(http.request.uri.path, "/logout")')
            ->and($output)->toContain('http.request.uri.path contains "/forgot-password"')
            ->and($output)->toContain('http.request.uri.path contains "/reset-password"')
            ->and($output)->toContain('http.request.uri.path contains "/verify-email"');
    });

    test('keeps the locale-prefix base and anonymous cookie bypass intact', function () {
        $output = cf_dry_run_output();

        expect($output)->toContain('starts_with(http.request.uri.path, "/en/")')
            ->and($output)->toContain('starts_with(http.request.uri.path, "/de/")')
            ->and($output)->toContain('not http.cookie contains "roundup-games-session"')
            ->and($output)->toContain('not http.cookie contains "XSRF-TOKEN"');
    });
});
