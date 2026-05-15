<?php

use App\Livewire\GM\GmWorkspace;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
    app()->setLocale('en');
});

// ── GM sees link analytics section ────────────────────────

describe('GM workspace analytics visibility', function () {
    it('GM sees share link analytics section', function () {
        $gm = $this->createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee(__('gws.heading_share_link_analytics'));
    });

    it('non-GM does not see analytics section', function () {
        $regularUser = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        $this->actingAs($regularUser)
            ->get(route('gm.workspace', 'en'))
            ->assertRedirect();
    });
});

// ── Top links by hit count ────────────────────────────────

describe('Top links by hit count', function () {
    it('displays top links ordered by hit count', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $topLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $gm->id,
            'label' => 'Top Discord Link',
            'hit_count' => 42,
        ]);

        $lowLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $gm->id,
            'label' => 'Low Twitter Link',
            'hit_count' => 5,
        ]);

        // Clear any cached analytics
        Cache::forget("gm_workspace:link_analytics:{$gm->id}");

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('topLinks', function ($topLinks) use ($topLink) {
                return $topLinks->first()->id === $topLink->id
                    && $topLinks->first()->hit_count === 42;
            });
    });

    it('only shows links belonging to the GM', function () {
        $gm = $this->createSubscribedGm();
        $otherGm = $this->createSubscribedGm(['email' => 'other-gm@test.com']);

        $game1 = Game::factory()->create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
        ]);
        $game2 = Game::factory()->create([
            'owner_id' => $otherGm->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $gmLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game1->id,
            'user_id' => $gm->id,
            'label' => 'My Link',
            'hit_count' => 10,
        ]);

        ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game2->id,
            'user_id' => $otherGm->id,
            'label' => 'Other GM Link',
            'hit_count' => 100,
        ]);

        Cache::forget("gm_workspace:link_analytics:{$gm->id}");

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('topLinks', function ($topLinks) {
                return $topLinks->count() === 1 && $topLinks->first()->label === 'My Link';
            });
    });
});

// ── Referrer domains aggregation ──────────────────────────

describe('Referrer domain aggregation', function () {
    it('aggregates referrer domains correctly', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $gm->id,
        ]);

        // Create hits with different referrers (GROUP BY is on full URL, not domain)
        ShortLinkHit::create([
            'short_link_id' => $link->id,
            'ip_address' => '1.2.3.4',
            'referer' => 'https://discord.com/channels/123',
            'hit_at' => now()->subDay(),
        ]);

        ShortLinkHit::create([
            'short_link_id' => $link->id,
            'ip_address' => '5.6.7.8',
            'referer' => 'https://discord.com/channels/456',
            'hit_at' => now()->subDay(),
        ]);

        ShortLinkHit::create([
            'short_link_id' => $link->id,
            'ip_address' => '9.10.11.12',
            'referer' => 'https://twitter.com/user/status/789',
            'hit_at' => now()->subDay(),
        ]);

        Cache::forget("gm_workspace:link_analytics:{$gm->id}");

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('topReferrers', function ($referrers) {
                // After fix: aggregation is by host in PHP, so two discord.com URLs
                // become a single domain entry with count 2.
                return $referrers->count() === 2
                    && $referrers->first()['domain'] === 'discord.com'
                    && $referrers->first()['count'] === 2
                    && $referrers->last()['domain'] === 'twitter.com'
                    && $referrers->last()['count'] === 1;
            });
    });

    it('excludes hits with empty or null referer', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $gm->id,
        ]);

        // Hit with null referer
        ShortLinkHit::create([
            'short_link_id' => $link->id,
            'ip_address' => '1.2.3.4',
            'referer' => null,
            'hit_at' => now()->subDay(),
        ]);

        // Hit with empty referer
        ShortLinkHit::create([
            'short_link_id' => $link->id,
            'ip_address' => '5.6.7.8',
            'referer' => '',
            'hit_at' => now()->subDay(),
        ]);

        Cache::forget("gm_workspace:link_analytics:{$gm->id}");

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('topReferrers', fn ($referrers) => $referrers->isEmpty());
    });
});

// ── Summary statistics ─────────────────────────────────────

describe('Link analytics summary', function () {
    it('calculates total links and 30-day hit count', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link1 = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $gm->id,
        ]);

        $link2 = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $gm->id,
        ]);

        // 30-day hits
        ShortLinkHit::create([
            'short_link_id' => $link1->id,
            'ip_address' => '1.2.3.4',
            'hit_at' => now()->subDays(5),
        ]);

        ShortLinkHit::create([
            'short_link_id' => $link2->id,
            'ip_address' => '5.6.7.8',
            'hit_at' => now()->subDays(10),
        ]);

        // Old hit (beyond 30 days) — should NOT be counted
        ShortLinkHit::create([
            'short_link_id' => $link1->id,
            'ip_address' => '9.10.11.12',
            'hit_at' => now()->subDays(45),
        ]);

        Cache::forget("gm_workspace:link_analytics:{$gm->id}");

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('linkAnalytics', function ($analytics) {
                return $analytics['totalLinks'] === 2
                    && $analytics['totalHits30d'] === 2; // Only 30-day hits
            });
    });

    it('shows zero totals for GM with no links', function () {
        $gm = $this->createSubscribedGm();

        Cache::forget("gm_workspace:link_analytics:{$gm->id}");

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('linkAnalytics', function ($analytics) {
                return $analytics['totalLinks'] === 0
                    && $analytics['totalHits30d'] === 0;
            });
    });

    it('groups links by entity type', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
        ]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $gm->id,
        ]);

        ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $gm->id,
        ]);

        ShortLink::factory()->create([
            'linkable_type' => Campaign::class,
            'linkable_id' => $campaign->id,
            'user_id' => $gm->id,
        ]);

        Cache::forget("gm_workspace:link_analytics:{$gm->id}");

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('linkAnalytics', function ($analytics) {
                $byType = $analytics['linksByType'];
                return $byType['Game'] === 2 && $byType['Campaign'] === 1;
            });
    });
});
