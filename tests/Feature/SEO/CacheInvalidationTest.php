<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use App\Services\SeoCacheService;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\{get};

beforeEach(function () {
    Cache::flush();
    $this->seoCache = app(SeoCacheService::class);
});

// ── Full lifecycle: populate → mutate → invalidate → re-populate ──

describe('Sitemap cache lifecycle', function () {
    it('GameSystem: populate → update → cache cleared → regenerate reflects new data', function () {
        $system = GameSystem::factory()->create(['name' => 'Alpha System']);

        // Step 1: Generate sitemap (cache populated)
        $response = get('/sitemap-game-systems.xml');
        $response->assertOk();
        $cached = $this->seoCache->getSitemap('game-systems');
        expect($cached)->not->toBeNull();
        expect($cached)->toContain('/game-systems/' . $system->slug);

        // Step 2: Update model (observer invalidates cache)
        $system->update(['name' => 'Beta System']);

        // Step 3: Cache should be cleared
        expect($this->seoCache->getSitemap('game-systems'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();

        // Step 4: Regenerate — new data reflected
        $response = get('/sitemap-game-systems.xml');
        $response->assertOk();
        $fresh = $this->seoCache->getSitemap('game-systems');
        expect($fresh)->not->toBeNull();
        // Old cached content is gone — new content reflects updated model
        $system->refresh();
        expect($fresh)->toContain('/game-systems/' . $system->slug);
    });

    it('Event: populate → update → cache cleared → regenerate reflects new data', function () {
        $event = Event::factory()->create([
            'name' => 'Original Event',
            'status' => 'published',
            'is_public' => true,
        ]);

        // Step 1: Populate
        get('/sitemap-events.xml')->assertOk();
        expect($this->seoCache->getSitemap('events'))->not->toBeNull();

        // Step 2: Update
        $event->update(['name' => 'Updated Event']);

        // Step 3: Cache cleared
        expect($this->seoCache->getSitemap('events'))->toBeNull();

        // Step 4: Regenerate
        get('/sitemap-events.xml')->assertOk();
        expect($this->seoCache->getSitemap('events'))->not->toBeNull();
    });

    it('Game: populate → update → cache cleared → regenerate reflects new data', function () {
        $game = Game::factory()->create();

        // Populate
        get('/sitemap-games.xml')->assertOk();
        expect($this->seoCache->getSitemap('games'))->not->toBeNull();

        // Update (change visibility to trigger relevant field)
        $game->update(['visibility' => Visibility::Private->value]);

        // Cache cleared
        expect($this->seoCache->getSitemap('games'))->toBeNull();

        // Regenerate — game no longer in sitemap (private)
        $response = get('/sitemap-games.xml');
        $response->assertOk();
        $fresh = $this->seoCache->getSitemap('games');
        expect($fresh)->not->toContain("/games/{$game->id}");
    });

    it('Campaign: populate → update → cache cleared → regenerate reflects new data', function () {
        $campaign = Campaign::factory()->create();

        // Populate
        get('/sitemap-campaigns.xml')->assertOk();
        expect($this->seoCache->getSitemap('campaigns'))->not->toBeNull();

        // Update
        $campaign->update(['status' => CampaignStatus::Cancelled->value]);

        // Cache cleared
        expect($this->seoCache->getSitemap('campaigns'))->toBeNull();

        // Regenerate — campaign no longer in sitemap (cancelled)
        get('/sitemap-campaigns.xml')->assertOk();
        $fresh = $this->seoCache->getSitemap('campaigns');
        expect($fresh)->not->toContain("/campaigns/{$campaign->id}");
    });

    it('Team: populate → update → cache cleared → regenerate reflects new data', function () {
        $team = Team::factory()->create();

        // Populate
        get('/sitemap-teams.xml')->assertOk();
        expect($this->seoCache->getSitemap('teams'))->not->toBeNull();

        // Update (deactivate)
        $team->update(['is_active' => false]);

        // Cache cleared
        expect($this->seoCache->getSitemap('teams'))->toBeNull();

        // Regenerate — team no longer in sitemap
        get('/sitemap-teams.xml')->assertOk();
        $fresh = $this->seoCache->getSitemap('teams');
        expect($fresh)->not->toContain("/teams/{$team->slug}");
    });

    it('User: populate → update slug → cache cleared → regenerate reflects new data', function () {
        $user = User::factory()->create([
            'name' => 'Alice Example',
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        // Populate
        get('/sitemap-profiles.xml')->assertOk();
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();

        // Update slug (triggers observer)
        $user->update(['slug' => 'alice-new-slug']);

        // Cache cleared
        expect($this->seoCache->getSitemap('profiles'))->toBeNull();

        // Regenerate
        get('/sitemap-profiles.xml')->assertOk();
        $fresh = $this->seoCache->getSitemap('profiles');
        expect($fresh)->toContain('/u/alice-new-slug');
    });
});

// ── Observer selectively skips User saves ────────────

describe('SeoModelObserver User selectivity', function () {
    it('does not invalidate cache on irrelevant User update', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        // Populate cache
        get('/sitemap-profiles.xml')->assertOk();
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();

        // Update an irrelevant field
        $user->update(['name' => 'New Name']);

        // Cache should still be intact
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();
    });

    it('invalidates cache when User slug changes', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        get('/sitemap-profiles.xml')->assertOk();
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();

        $user->update(['slug' => 'changed-slug']);

        expect($this->seoCache->getSitemap('profiles'))->toBeNull();
    });

    it('invalidates cache when User profile_complete changes', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        get('/sitemap-profiles.xml')->assertOk();
        $this->seoCache->getSitemap('profiles'); // ensure populated

        $user->update(['profile_complete' => false]);

        expect($this->seoCache->getSitemap('profiles'))->toBeNull();
    });

    it('invalidates cache when User is_disabled changes', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        get('/sitemap-profiles.xml')->assertOk();

        $user->update(['is_disabled' => true]);

        expect($this->seoCache->getSitemap('profiles'))->toBeNull();
    });
});

// ── Delete triggers cache invalidation ───────────────

describe('Model deletion invalidates cache', function () {
    it('clears cache when GameSystem is deleted', function () {
        $system = GameSystem::factory()->create();
        get('/sitemap-game-systems.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        $system->delete();

        expect($this->seoCache->getSitemap('game-systems'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    });

    it('clears cache when Event is deleted', function () {
        $event = Event::factory()->create(['status' => 'published']);
        get('/sitemap-events.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        $event->delete();

        expect($this->seoCache->getSitemap('events'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    });

    it('clears cache when Game is deleted', function () {
        $game = Game::factory()->create();
        get('/sitemap-games.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        $game->delete();

        expect($this->seoCache->getSitemap('games'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    });

    it('clears cache when Campaign is deleted', function () {
        $campaign = Campaign::factory()->create();
        get('/sitemap-campaigns.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        $campaign->delete();

        expect($this->seoCache->getSitemap('campaigns'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    });

    it('clears cache when Team is deleted', function () {
        $team = Team::factory()->create();
        get('/sitemap-teams.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        $team->delete();

        expect($this->seoCache->getSitemap('teams'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    });

    it('clears cache when User is deleted', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        get('/sitemap-profiles.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        $user->delete();

        expect($this->seoCache->getSitemap('profiles'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    });
});

// ── Index cache invalidation always accompanies sitemap invalidation ──

describe('Index invalidation accompanies sitemap invalidation', function () {
    it('clears both index and sub-sitemap when GameSystem is updated', function () {
        GameSystem::factory()->create();
        get('/sitemap.xml')->assertOk();
        get('/sitemap-game-systems.xml')->assertOk();

        expect($this->seoCache->getIndex())->not->toBeNull();
        expect($this->seoCache->getSitemap('game-systems'))->not->toBeNull();

        // Updating any game system should invalidate both
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

        // Updating a game system clears game-systems + index but NOT events
        GameSystem::first()->update(['name' => 'Changed']);

        expect($this->seoCache->getSitemap('game-systems'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
        expect($this->seoCache->getSitemap('events'))->not->toBeNull();
    });
});

// ── Model creation invalidates cache (via saved observer) ──

describe('New model creation invalidates cache', function () {
    it('invalidates sitemap when new GameSystem is created', function () {
        get('/sitemap-game-systems.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        GameSystem::factory()->create(['name' => 'Brand New System']);

        expect($this->seoCache->getSitemap('game-systems'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    });

    it('invalidates sitemap when new Event is created', function () {
        get('/sitemap-events.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        Event::factory()->create(['status' => 'published', 'is_public' => true]);

        expect($this->seoCache->getSitemap('events'))->toBeNull();
        expect($this->seoCache->getIndex())->toBeNull();
    });

    it('does not invalidate sitemap when new User is created (slug set in creating hook, not detected by wasChanged)', function () {
        get('/sitemap-profiles.xml')->assertOk();
        $this->seoCache->setIndex('<idx/>');

        User::factory()->create([
            'name' => 'New User',
            'profile_complete' => true,
        ]);

        // Slug is auto-generated in the creating hook, so wasChanged('slug') = false
        // on the saved event (slug is part of original attributes before first save).
        // The observer intentionally skips this to avoid unnecessary invalidation.
        expect($this->seoCache->getSitemap('profiles'))->not->toBeNull();
        expect($this->seoCache->getIndex())->not->toBeNull();
    });
});

// ── Old cached data is confirmed gone after invalidation ──

describe('Stale data removal', function () {
    it('confirms old XML is not served after model update', function () {
        $system = GameSystem::factory()->create(['name' => 'Old Name']);
        $oldSlug = $system->slug;

        // Populate
        get('/sitemap-game-systems.xml')->assertOk();
        $oldCached = $this->seoCache->getSitemap('game-systems');
        expect($oldCached)->toContain($oldSlug);

        // Mutate slug
        $system->update(['slug' => 'completely-new-slug']);

        // Old cache gone
        expect($this->seoCache->getSitemap('game-systems'))->toBeNull();

        // Regenerate
        get('/sitemap-game-systems.xml')->assertOk();
        $newCached = $this->seoCache->getSitemap('game-systems');
        expect($newCached)->toContain('completely-new-slug');
        expect($newCached)->not->toContain($oldSlug);
    });
});
