<?php

use App\Enums\Visibility;
use App\Jobs\IndexNowSweepJob;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\SEO\IndexNow\IndexNowService;

use function Pest\Laravel\get;

//
// IndexNow pipeline (SEO 2026-10-15): observer parks a model's public
// URLs, a coalescing sweep submits them, and the key file proves host
// ownership. Everything no-ops until INDEXNOW_KEY is configured — which
// is also why the rest of the suite is unaffected by the observer.
//

function enableIndexNow(): void
{
    config(['services.indexnow.key' => str_repeat('a1', 16)]);
}

// ── Key file ────────────────────────────────────────────

it('serves the ownership key file when indexnow is configured', function () {
    enableIndexNow();

    $response = get('/'.str_repeat('a1', 16).'.txt');

    $response->assertOk();
    expect($response->getContent())->toBe(str_repeat('a1', 16));
});

it('hides the key file when indexnow is not configured', function () {
    config(['services.indexnow.key' => null]);

    get('/'.str_repeat('a1', 16).'.txt')->assertNotFound();
});

it('rejects probe filenames that do not match the configured key', function () {
    enableIndexNow();

    get('/'.str_repeat('b2', 16).'.txt')->assertNotFound();
});

// ── Observer → pending set ──────────────────────────────

it('parks both locale urls of a public game and schedules the sweep', function () {
    enableIndexNow();
    Queue::fake();

    $game = Game::factory()->create([
        'name' => ['en' => 'Open Table'],
        'visibility' => Visibility::Public,
    ]);

    Queue::assertPushed(IndexNowSweepJob::class);

    expect(app(IndexNowService::class)->pendingUrls())
        ->toContain(url("en/games/{$game->id}"))
        ->toContain(url("de/games/{$game->id}"));
});

it('does not announce non-indexable entities', function (callable $create, callable $forbidden) {
    enableIndexNow();
    Queue::fake();

    $model = $create();

    // Side models created by factories may legitimately announce their own
    // URLs — the contract under test is that THIS model's URLs never appear.
    $pending = app(IndexNowService::class)->pendingUrls();
    foreach ($forbidden($model) as $needle) {
        expect($pending)->not->toContain($needle);
    }
})->with([
    'private game' => [
        fn () => Game::factory()->create(['visibility' => Visibility::Private]),
        fn ($game) => [url("en/games/{$game->id}"), url("de/games/{$game->id}")],
    ],
    'duplicate game system' => [
        function () {
            $primary = GameSystem::factory()->create(['slug' => 'puerto-rico']);

            return GameSystem::factory()->create([
                'slug' => 'puerto-rico-165332',
                'canonical_game_system_id' => $primary->id,
            ]);
        },
        fn ($duplicate) => [url('en/game-systems/puerto-rico-165332'), url('de/game-systems/puerto-rico-165332')],
    ],
    'draft event' => [
        fn () => Event::factory()->create(['status' => 'draft']),
        fn ($event) => [url("en/events/{$event->slug}"), url("de/events/{$event->slug}")],
    ],
    'incomplete user' => [
        fn () => User::factory()->create(['profile_complete' => false]),
        fn ($user) => [url("en/u/{$user->slug}"), url("de/u/{$user->slug}")],
    ],
]);

it('does nothing when indexnow is not configured', function () {
    config(['services.indexnow.key' => null]);
    Queue::fake();

    Game::factory()->create(['visibility' => Visibility::Public]);

    Queue::assertNotPushed(IndexNowSweepJob::class);
});

// ── Sweep → submission ──────────────────────────────────

it('submits parked urls with the protocol payload and drains the set', function () {
    enableIndexNow();
    Queue::fake();
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);

    $game = Game::factory()->create(['visibility' => Visibility::Public]);

    $submitted = app(IndexNowService::class)->sweep();

    // The game factory also creates an indexable owner (profile_complete)
    // whose profile URLs ride along — coalescing is the point of the set.
    expect($submitted)->toBe(4)
        ->and(app(IndexNowService::class)->pendingUrls())->toBe([]);

    Http::assertSent(function ($request) use ($game) {
        $payload = $request->data();

        return $request->url() === 'https://api.indexnow.org/indexnow'
            && $payload['key'] === str_repeat('a1', 16)
            && str_ends_with($payload['keyLocation'], str_repeat('a1', 16).'.txt')
            && in_array(url("en/games/{$game->id}"), $payload['urlList'], true)
            && in_array(url("de/games/{$game->id}"), $payload['urlList'], true);
    });
});

it('survives engine rejections without failing', function () {
    enableIndexNow();
    Queue::fake();
    Http::fake(['api.indexnow.org/*' => Http::response('key mismatch', 403)]);

    Game::factory()->create(['visibility' => Visibility::Public]);

    $submitted = app(IndexNowService::class)->sweep();

    expect($submitted)->toBe(0);
});

// ── Route must not shadow locale routing ────────────────

it('still redirects bare paths after adding the key route', function () {
    get('/')->assertRedirect();
});
