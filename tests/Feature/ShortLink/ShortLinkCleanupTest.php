<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
    $this->owner = User::factory()->create();
});

// ── Entity-driven expiry ──────────────────────────────────

describe('Entity-driven link expiry', function () {
    it('completes a game and sets expires_at on associated links', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => null,
        ]);

        // Trigger entity-driven expiry by completing the game
        $game->update(['status' => 'completed']);

        $link->refresh();
        expect($link->expires_at)->not->toBeNull();
        // Grace period is 7 days by default
        expect($link->expires_at->isFuture())->toBeTrue();
    });

    it('cancels a campaign and sets expires_at on associated links', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'active',
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Campaign::class,
            'linkable_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'expires_at' => null,
        ]);

        // Change campaign status to cancelled (note: Campaign uses 'cancelled' not 'canceled')
        $campaign->update(['status' => 'cancelled']);

        $link->refresh();
        expect($link->expires_at)->not->toBeNull();
    });

    it('does not change already-expired links', function () {
        $originalExpiry = now()->addDays(2)->startOfSecond();

        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => $originalExpiry,
        ]);

        $game->update(['status' => 'completed']);

        $link->refresh();
        // The ShortLinkService expireLinksForEntity only targets whereNull('expires_at'),
        // so the original expiry should remain untouched
        expect($link->expires_at->timestamp)->toBe($originalExpiry->timestamp);
    });
});

// ── PruneExpiredShortLinks command ─────────────────────────

describe('PruneExpiredShortLinks command', function () {
    it('soft-deletes links past their expiry', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $expiredLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => now()->subDay(),
        ]);

        $activeLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $noExpiryLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => null,
        ]);

        Artisan::call('short-links:prune');

        expect(ShortLink::find($expiredLink->id))->toBeNull();
        expect(ShortLink::find($activeLink->id))->not->toBeNull();
        expect(ShortLink::find($noExpiryLink->id))->not->toBeNull();

        // Soft-deleted link still in trashed records
        expect(ShortLink::withTrashed()->find($expiredLink->id))->not->toBeNull();
        expect(ShortLink::withTrashed()->find($expiredLink->id)->trashed())->toBeTrue();
    });

    it('hard-deletes analytics hits older than retention period', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
        ]);

        // Create old hit (beyond default 90-day retention)
        $oldHit = ShortLinkHit::create([
            'short_link_id' => $link->id,
            'ip_address' => '1.2.3.4',
            'hit_at' => now()->subDays(100),
        ]);

        // Create recent hit (within retention period)
        $recentHit = ShortLinkHit::create([
            'short_link_id' => $link->id,
            'ip_address' => '5.6.7.8',
            'hit_at' => now()->subDays(10),
        ]);

        Artisan::call('short-links:prune --days=90');

        expect(ShortLinkHit::find($oldHit->id))->toBeNull();
        expect(ShortLinkHit::find($recentHit->id))->not->toBeNull();
    });

    it('respects grace period for entity-driven expiry', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        // Complete the game and backdate updated_at to 3 days ago (within 7-day grace)
        $game->update(['status' => 'completed']);
        \Illuminate\Support\Facades\DB::table('games')
            ->where('id', $game->id)
            ->update(['updated_at' => now()->subDays(3)]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => null,
        ]);

        Artisan::call('short-links:prune --grace=7');

        // Link should NOT be expired yet (entity completed only 3 days ago, grace is 7)
        $link->refresh();
        expect($link->expires_at)->toBeNull();
    });

    it('expires links past the grace period', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        // Complete the game and backdate updated_at to 10 days ago (past 7-day grace)
        $game->update(['status' => 'completed']);
        \Illuminate\Support\Facades\DB::table('games')
            ->where('id', $game->id)
            ->update(['updated_at' => now()->subDays(10)]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => null,
        ]);

        Artisan::call('short-links:prune --grace=7');

        $link->refresh();
        expect($link->expires_at)->not->toBeNull();
    });

    it('handles entity with no links without errors', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        $game->update(['status' => 'completed']);
        \Illuminate\Support\Facades\DB::table('games')
            ->where('id', $game->id)
            ->update(['updated_at' => now()->subDays(10)]);

        $exitCode = Artisan::call('short-links:prune');
        expect($exitCode)->toBe(0);
    });

    it('dry-run does not modify data', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $expiredLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => now()->subDay(),
        ]);

        Artisan::call('short-links:prune --dry-run');

        // Link should NOT be soft-deleted in dry-run
        expect(ShortLink::find($expiredLink->id))->not->toBeNull();
    });

    it('logs structured counts on success', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'expires_at' => now()->subDay(),
        ]);

        \Illuminate\Support\Facades\Log::partialMock()
            ->shouldReceive('channel')
            ->with('daily')
            ->andReturnSelf()
            ->shouldReceive('info')
            ->once()
            ->with('prune.expired_links', \Mockery::on(function ($context) {
                return isset($context['soft_deleted_count']) && $context['soft_deleted_count'] >= 1;
            }));

        Artisan::call('short-links:prune');
    });

    it('is registered in the console scheduler', function () {
        $consoleContent = file_get_contents(base_path('routes/console.php'));

        expect($consoleContent)->toContain('short-links:prune');
        expect($consoleContent)->toContain('dailyAt');
    });
});
