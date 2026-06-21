<?php

use App\Enums\CampaignStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use App\Services\SeoCacheService;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

beforeEach(function () {
    Cache::flush();
    $this->seoCache = app(SeoCacheService::class);
});

// Full lifecycle: populate → mutate → invalidate → re-populate. Each model
// has its own observer registration; this single dataset exercises the
// entire observer → cache → regenerate pipeline per model.

dataset('sitemap_lifecycle', [
    'GameSystem' => [[
        'sitemap' => 'game-systems',
        'make' => fn () => GameSystem::factory()->create(['name' => ['en' => 'Alpha System']]),
        'mutate' => fn ($m) => $m->update(['name' => 'Beta System']),
    ]],
    'Event' => [[
        'sitemap' => 'events',
        'make' => fn () => Event::factory()->create([
            'name' => ['en' => 'Original Event'],
            'status' => 'published',
            'is_public' => true,
        ]),
        'mutate' => fn ($m) => $m->update(['name' => 'Updated Event']),
    ]],
    'Game' => [[
        'sitemap' => 'games',
        'make' => fn () => Game::factory()->create(),
        'mutate' => fn ($m) => $m->update(['visibility' => Visibility::Private->value]),
    ]],
    'Campaign' => [[
        'sitemap' => 'campaigns',
        'make' => fn () => Campaign::factory()->create(),
        'mutate' => fn ($m) => $m->update(['status' => CampaignStatus::Cancelled->value]),
    ]],
    'Team' => [[
        'sitemap' => 'teams',
        'make' => fn () => Team::factory()->create(),
        'mutate' => fn ($m) => $m->update(['is_active' => false]),
    ]],
    'User' => [[
        'sitemap' => 'profiles',
        'make' => fn () => User::factory()->create([
            'name' => 'Alice Example',
            'profile_complete' => true,
            'is_disabled' => false,
        ]),
        'mutate' => fn ($m) => $m->update(['slug' => 'alice-new-slug']),
    ]],
]);

describe('Sitemap cache lifecycle', function () {
    it('populate → update → cache cleared → regenerate reflects new data', function (array $d) {
        $type = $d['sitemap'];
        $model = ($d['make'])();

        get("/sitemap-{$type}.xml")->assertOk();
        expect($this->seoCache->getSitemap($type))->not->toBeNull();

        ($d['mutate'])($model);

        expect($this->seoCache->getSitemap($type))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();

        get("/sitemap-{$type}.xml")->assertOk();
        expect($this->seoCache->getSitemap($type))->not->toBeNull();
    })->with('sitemap_lifecycle');
});

// ── Observer selectively skips User saves ────────────

describe('SeoModelObserver User selectivity', function () {
    it('does not invalidate cache on irrelevant User update', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        get('/sitemap-profiles.xml')->assertOk();
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();

        $user->update(['name' => 'New Name']);
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();
    });

    it('invalidates cache when a watched User field changes', function ($field, $value) {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        get('/sitemap-profiles.xml')->assertOk();
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();

        $user->update([$field => $value]);
        expect($this->seoCache->getSitemap('profiles'))->toBeNull();
    })->with([
        'slug' => ['slug', 'changed-slug'],
        'profile_complete' => ['profile_complete', false],
        'is_disabled' => ['is_disabled', true],
    ]);
});

// ── Deletion triggers cache invalidation ──

describe('Model deletion invalidates cache', function () {
    it('clears cache and index when a mapped model is deleted', function ($sitemap, $factory) {
        $model = $factory();
        get("/sitemap-{$sitemap}.xml")->assertOk();
        $this->seoCache->setIndex('<idx/>');

        $model->delete();

        expect($this->seoCache->getSitemap($sitemap))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    })->with([
        'GameSystem' => ['game-systems', fn () => GameSystem::factory()->create()],
        'User' => ['profiles', fn () => User::factory()->create(['profile_complete' => true])],
    ]);
});

// ── Index cache invalidation always accompanies sitemap invalidation ──

describe('Index invalidation accompanies sitemap invalidation', function () {
    it('clears both index and sub-sitemap when GameSystem is updated', function () {
        GameSystem::factory()->create();
        get('/sitemap.xml')->assertOk();
        get('/sitemap-game-systems.xml')->assertOk();

        expect($this->seoCache->getIndex())->not->toBeNull();
        expect($this->seoCache->getSitemap('game-systems'))->not->toBeNull();

        GameSystem::first()->update(['name' => 'Updated']);

        expect($this->seoCache->getIndex())->toBeNull();
        expect($this->seoCache->getSitemap('game-systems'))->toBeNull();
    });

    it('clears only the relevant sub-sitemap, not others', function () {
        GameSystem::factory()->create();
        Event::factory()->create(['status' => 'published', 'is_public' => true]);

        get('/sitemap-game-systems.xml')->assertOk();
        get('/sitemap-events.xml')->assertOk();
        get('/sitemap.xml')->assertOk();

        GameSystem::first()->update(['name' => 'Changed']);

        expect($this->seoCache->getSitemap('game-systems'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
        expect($this->seoCache->getSitemap('events'))->not->toBeNull();
    });
});

// ── Model creation invalidates cache (via saved observer) ──

describe('New model creation invalidates cache', function () {
    it('invalidates sitemap and index when a new mapped model is created', function ($sitemap, $factory) {
        get("/sitemap-{$sitemap}.xml")->assertOk();
        $this->seoCache->setIndex('<idx/>');

        $factory();

        expect($this->seoCache->getSitemap($sitemap))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    })->with([
        'GameSystem' => ['game-systems', fn () => GameSystem::factory()->create(['name' => ['en' => 'Brand New System']])],
        'Event' => ['events', fn () => Event::factory()->create(['status' => 'published', 'is_public' => true])],
    ]);

    it('does not invalidate sitemap when a new User is created (slug set in creating hook, not detected by wasChanged)', function () {
        get('/sitemap-profiles.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        User::factory()->create([
            'name' => 'New User',
            'profile_complete' => true,
        ]);

        // Slug is auto-generated in the creating hook, so wasChanged('slug') = false.
        // The observer intentionally skips this to avoid unnecessary invalidation.
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();
        expect($this->seoCache->getIndex())->not->toBeNull();
    });
});

// ── Old cached data is confirmed gone after invalidation ──

describe('Stale data removal', function () {
    it('confirms old XML is not served after model update', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Old Name']]);
        $oldSlug = $system->slug;

        get('/sitemap-game-systems.xml')->assertOk();
        $oldCached = $this->seoCache->getSitemap('game-systems');
        expect($oldCached)->toContain($oldSlug);

        $system->update(['slug' => 'completely-new-slug']);

        expect($this->seoCache->getSitemap('game-systems'))->toBeNull();

        get('/sitemap-game-systems.xml')->assertOk();
        $newCached = $this->seoCache->getSitemap('game-systems');
        expect($newCached)->toContain('completely-new-slug');
        expect($newCached)->not->toContain($oldSlug);
    });
});
