<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use App\Services\SeoCacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->service = new SeoCacheService;
});

// ── getSitemap / setSitemap roundtrip ────────────────

describe('Sitemap cache set/get', function () {
    it('returns null on cache miss', function () {
        expect($this->service->getSitemap('games'))->toBeNull();
    });

    it('stores different content per type', function () {
        $this->service->setSitemap('games', '<games-xml/>');
        $this->service->setSitemap('events', '<events-xml/>');

        expect($this->service->getSitemap('games'))->toBe('<games-xml/>');
        expect($this->service->getSitemap('events'))->toBe('<events-xml/>');
    });
});

// ── getIndex / setIndex roundtrip ────────────────────

describe('Index cache set/get', function () {
    it('returns null on cache miss', function () {
        expect($this->service->getIndex())->toBeNull();
    });
});

// ── forgetSitemap ────────────────────────────────────

describe('forgetSitemap', function () {
    it('clears a specific sitemap type cache', function () {
        $this->service->setSitemap('games', '<games/>');
        $this->service->setSitemap('events', '<events/>');

        $this->service->forgetSitemap('games');

        expect($this->service->getSitemap('games'))->toBeNull();
        expect($this->service->getSitemap('events'))->toBe('<events/>');
    });

    it('is a no-op when cache key does not exist', function () {
        // Should not throw
        $this->service->forgetSitemap('nonexistent');
        expect(true)->toBeTrue();
    });

    it('clears each type independently', function () {
        foreach (['static', 'game-systems', 'events', 'games', 'campaigns', 'teams', 'profiles'] as $type) {
            $this->service->setSitemap($type, "<{$type}/>");
        }

        $this->service->forgetSitemap('campaigns');

        expect($this->service->getSitemap('campaigns'))->toBeNull();
        // All others still present
        foreach (['static', 'game-systems', 'events', 'games', 'teams', 'profiles'] as $type) {
            expect($this->service->getSitemap($type))->not->toBeNull();
        }
    });
});

// ── forgetIndex ──────────────────────────────────────

describe('forgetIndex', function () {
    it('clears the sitemap index cache', function () {
        $this->service->setIndex('<sitemapindex/>');
        $this->service->setSitemap('games', '<games/>');

        $this->service->forgetIndex();

        expect($this->service->getIndex())->toBeNull();
        // Sub-sitemaps unaffected
        expect($this->service->getSitemap('games'))->toBe('<games/>');
    });
});

// ── forgetByModel ────────────────────────────────────

describe('forgetByModel', function () {
    it('clears sitemap cache and index for GameSystem model', function () {
        $system = GameSystem::factory()->create();
        $this->service->setSitemap('game-systems', '<gs/>');
        $this->service->setIndex('<idx/>');

        $this->service->forgetByModel($system);

        expect($this->service->getSitemap('game-systems'))->toBeNull();
        expect($this->service->getIndex())->toBeNull();
    });

    it('clears sitemap cache and index for Event model', function () {
        $event = Event::factory()->create(['status' => 'published']);
        $this->service->setSitemap('events', '<ev/>');
        $this->service->setIndex('<idx/>');

        $this->service->forgetByModel($event);

        expect($this->service->getSitemap('events'))->toBeNull();
        expect($this->service->getIndex())->toBeNull();
    });

    it('clears sitemap cache and index for Game model', function () {
        $game = Game::factory()->create();
        $this->service->setSitemap('games', '<g/>');
        $this->service->setIndex('<idx/>');

        $this->service->forgetByModel($game);

        expect($this->service->getSitemap('games'))->toBeNull();
        expect($this->service->getIndex())->toBeNull();
    });

    it('clears sitemap cache and index for Campaign model', function () {
        $campaign = Campaign::factory()->create();
        $this->service->setSitemap('campaigns', '<c/>');
        $this->service->setIndex('<idx/>');

        $this->service->forgetByModel($campaign);

        expect($this->service->getSitemap('campaigns'))->toBeNull();
        expect($this->service->getIndex())->toBeNull();
    });

    it('clears sitemap cache and index for Team model', function () {
        $team = Team::factory()->create();
        $this->service->setSitemap('teams', '<t/>');
        $this->service->setIndex('<idx/>');

        $this->service->forgetByModel($team);

        expect($this->service->getSitemap('teams'))->toBeNull();
        expect($this->service->getIndex())->toBeNull();
    });

    it('clears sitemap cache and index for User model', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $this->service->setSitemap('profiles', '<u/>');
        $this->service->setIndex('<idx/>');

        $this->service->forgetByModel($user);

        expect($this->service->getSitemap('profiles'))->toBeNull();
        expect($this->service->getIndex())->toBeNull();
    });

    it('is a no-op for unmapped model classes', function () {
        $this->service->setSitemap('games', '<games/>');
        $this->service->setIndex('<idx/>');

        // Use an anonymous class that isn't mapped
        $this->service->forgetByModel(new \stdClass);

        expect($this->service->getSitemap('games'))->toBe('<games/>');
        expect($this->service->getIndex())->toBe('<idx/>');
    });

    it('only clears the relevant sitemap type, not all types', function () {
        $game = Game::factory()->create();
        $this->service->setSitemap('games', '<games/>');
        $this->service->setSitemap('events', '<events/>');
        $this->service->setIndex('<idx/>');

        $this->service->forgetByModel($game);

        expect($this->service->getSitemap('games'))->toBeNull();
        expect($this->service->getSitemap('events'))->toBe('<events/>');
        expect($this->service->getIndex())->toBeNull();
    });
});

// ── Accessor methods ─────────────────────────────────

describe('Accessor methods', function () {
    it('returns all valid sitemap types', function () {
        $types = $this->service->getSitemapTypes();

        expect($types)->toBe([
            'static',
            'game-systems',
            'events',
            'games',
            'campaigns',
            'teams',
            'profiles',
        ]);
    });

    it('validates correct sitemap types', function () {
        foreach (['static', 'game-systems', 'events', 'games', 'campaigns', 'teams', 'profiles'] as $type) {
            expect($this->service->isValidType($type))->toBeTrue();
        }
    });

    it('rejects invalid sitemap types', function () {
        expect($this->service->isValidType('nonexistent'))->toBeFalse();
        expect($this->service->isValidType(''))->toBeFalse();
        expect($this->service->isValidType('Games'))->toBeFalse(); // case-sensitive
    });
});
