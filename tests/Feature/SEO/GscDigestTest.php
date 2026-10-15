<?php

use App\Models\CwvMeasurement;
use App\Models\GscSnapshot;
use App\Models\User;
use App\SEO\SearchConsole\GscClient;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

//
// seo:gsc-digest + the Search Performance page (SEO track 2026-10-15).
//
// The GscClient runs for real with a locally-generated RSA key and faked
// HTTP — the JWT signing, token caching, and response parsing are all
// exercised; only Google's endpoints are stubbed.
//

function gscCredentials(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return base64_encode(json_encode([
        'client_email' => 'sa@test.iam.gserviceaccount.com',
        'private_key' => $pem,
    ]));
}

function gscRow(array $overrides = []): array
{
    return array_merge([
        'keys' => ['board game groups near me'],
        'clicks' => 12,
        'impressions' => 3400,
        'ctr' => 0.0035,
        'position' => 14.6,
    ], $overrides);
}

it('stores an idempotent digest snapshot with totals and previous window', function () {
    config(['services.gsc.credentials' => gscCredentials()]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth2.googleapis.com')) {
            return Http::response(['access_token' => 'test-token'], 200);
        }

        $body = json_decode((string) $request->body(), true);
        $dimensions = is_array($body) ? ($body['dimensions'] ?? []) : [];

        if ($dimensions === []) {
            return Http::response(['rows' => [gscRow(['keys' => []])]], 200);
        }

        if (($dimensions[0] ?? null) === 'query') {
            return Http::response(['rows' => [gscRow()]], 200);
        }

        return Http::response(['rows' => [gscRow(['keys' => ['https://roundup.games/en']])]], 200);
    });

    artisan('seo:gsc-digest')->assertSuccessful();

    $snapshot = GscSnapshot::query()->sole();

    expect($snapshot->clicks)->toBe(12)
        ->and($snapshot->impressions)->toBe(3400)
        ->and($snapshot->previous_clicks)->toBe(12)
        ->and($snapshot->top_queries[0]['key'])->toBe('board game groups near me')
        ->and($snapshot->top_pages[0]['key'])->toBe('https://roundup.games/en');

    // Idempotent per window: same days → update, not a second row.
    artisan('seo:gsc-digest')->assertSuccessful();

    expect(GscSnapshot::query()->count())->toBe(1);
});

it('fails loudly when the gsc query fails', function () {
    config(['services.gsc.credentials' => gscCredentials()]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token'], 200),
        'searchconsole.googleapis.com/*' => Http::response('permission denied', 403),
    ]);

    artisan('seo:gsc-digest')->assertFailed();

    expect(GscSnapshot::query()->count())->toBe(0);
});

it('is a no-op without credentials', function () {
    config(['services.gsc.credentials' => null]);

    artisan('seo:gsc-digest')->assertSuccessful();

    expect(GscSnapshot::query()->count())->toBe(0);
});

it('renders the search performance page for admins with the latest snapshot and cwv', function () {
    seedRoles();
    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $admin = User::factory()->create();
    $admin->assignRole('Platform Admin');
    $admin->unsetRelations();

    actingAs($admin);
    Filament\Facades\Filament::setCurrentPanel('admin');

    GscSnapshot::create([
        'range_start' => now()->subDays(28)->toDateString(),
        'range_end' => now()->subDay()->toDateString(),
        'clicks' => 12,
        'impressions' => 3400,
        'ctr' => 0.0035,
        'position' => 14.6,
        'previous_clicks' => 8,
        'previous_impressions' => 3100,
        'top_queries' => [['key' => 'board game groups near me', 'clicks' => 5, 'impressions' => 400, 'ctr' => 0.0125, 'position' => 8.2]],
        'top_pages' => [],
    ]);
    CwvMeasurement::create([
        'template' => 'home',
        'url' => 'https://roundup.games/en',
        'strategy' => 'mobile',
        'performance_score' => 99,
        'fetched_at' => now(),
    ]);

    $this->withoutExceptionHandling();
    get('/admin/search-performance')
        ->assertOk()
        ->assertSee('board game groups near me', false)
        ->assertSee('Core Web Vitals', false);
});

it('redirects guests from the search performance page to login', function () {
    get('/admin/search-performance')->assertRedirect();
});
