<?php

use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Models\UserAppVisit;
use App\Models\UserRelationship;
use App\Services\PwaEligibilityService;

beforeEach(function () {
    $this->service = new PwaEligibilityService;

    // Location required for FK constraint on users.location_id
    $this->location = Location::factory()->create();

    // Base user that passes baseline checks by default
    $this->user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => $this->location->id,
        'email_verified_at' => now(),
    ]);

    // Flush session cache between tests
    session()->flush();
});

// ── Baseline Checks ────────────────────────────────────

describe('Baseline checks', function () {
    it('rejects user with incomplete profile', function () {
        $this->user->update(['profile_complete' => false]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeFalse()
            ->and($result->reason)->toBe('baseline_missing')
            ->and($result->source)->toBe('none');
    });

});

// ── Trypass Events ─────────────────────────────────────

describe('Trypass events', function () {
    it('passes for user with upcoming game as owner within 7 days', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(3),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass')
            ->and($result->reason)->toBe('trypass_game_upcoming');
    });

    it('passes for user with upcoming game as approved participant within 7 days', function () {
        $game = Game::factory()->create(['date_time' => now()->addDays(5)]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass')
            ->and($result->reason)->toBe('trypass_game_upcoming');
    });

    it('does not trypass for game beyond 7 days', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(8),
            'created_at' => now()->subDay(),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->source)->not->toBe('trypass');
    });

    it('does not trypass for past game', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->source)->not->toBe('trypass');
    });

    it('passes for first game created within last 5 minutes (owner)', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(30), // far future — not the 7-day check
            'created_at' => now()->subMinutes(2),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass')
            ->and($result->reason)->toBe('trypass_first_game_created');
    });

    it('does not trypass for second game created within 5 minutes', function () {
        // First game (old) — already exists
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subDay(),
        ]);
        // Second game (recent) — should NOT trigger first-game trypass
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->reason)->not->toBe('trypass_first_game_created');
    });

    it('passes for game joined (approved participant on recently created game) within 5 minutes', function () {
        $game = Game::factory()->create([
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass')
            ->and($result->reason)->toBe('trypass_game_joined');
    });

    it('passes for game invitation received within last 5 minutes', function () {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass')
            ->and($result->reason)->toBe('trypass_invitation_received');
    });

    it('does not trypass for second invitation (also covers old-game invitation)', function () {
        $host = User::factory()->create();
        // First invitation (old — covers "old game" case too)
        $game1 = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(10),
        ]);
        GameParticipant::create([
            'game_id' => $game1->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
            'created_at' => now()->subMinutes(10),
        ]);
        // Second invitation (recent but not first-ever)
        $game2 = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);
        GameParticipant::create([
            'game_id' => $game2->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->reason)->not->toBe('trypass_invitation_received');
    });

    it('passes for first campaign created within last 5 minutes', function () {
        Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'created_at' => now()->subMinutes(2),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass')
            ->and($result->reason)->toBe('trypass_first_campaign_created');
    });

    it('does not trypass for second campaign created within 5 minutes', function () {
        Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'created_at' => now()->subDay(),
        ]);
        Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'created_at' => now()->subMinutes(2),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->reason)->not->toBe('trypass_first_campaign_created');
    });

    it('trypass overrides even when score gate would fail', function () {
        // No visit days, no game participation, no social investment — score gate would fail
        // But a recently-created game with the user as pending participant triggers invitation trypass
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass');
    });

    it('trypass is blocked when baseline fails', function () {
        $this->user->update(['profile_complete' => false]);

        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeFalse()
            ->and($result->reason)->toBe('baseline_missing');
    });
});

// ── Score Gate (boundary tests: 0, 1, 2 of 3 signals) ──

describe('Score gate combinations', function () {
    it('rejects with 0 of 3 signals', function () {
        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeFalse()
            ->and($result->reason)->toBe('score_too_low');
    });

    it('rejects with only 1 of 3 signals (visits)', function () {
        UserAppVisit::factory()->create([
            'user_id' => $this->user->id,
            'visit_date' => now()->subDay()->toDateString(),
        ]);
        UserAppVisit::factory()->create([
            'user_id' => $this->user->id,
            'visit_date' => now()->toDateString(),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeFalse()
            ->and($result->reason)->toBe('score_too_low');
    });

    it('accepts with 2 of 3 signals (visits + games)', function () {
        UserAppVisit::factory()->create([
            'user_id' => $this->user->id,
            'visit_date' => now()->subDay()->toDateString(),
        ]);
        UserAppVisit::factory()->create([
            'user_id' => $this->user->id,
            'visit_date' => now()->toDateString(),
        ]);

        $game = Game::factory()->create([
            'date_time' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'approved',
            'created_at' => now()->subDay(),
        ]);

        $result = $this->service->isEligible($this->user);

        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('baseline+score')
            ->and($result->reason)->toBe('engagement_threshold_met');
    });
});

// ── Session Caching ────────────────────────────────────

describe('Session caching', function () {
    it('caches result in session with 1-hour TTL', function () {
        // Make user ineligible (no signals)
        $result = $this->service->isEligible($this->user);

        $cacheKey = "pwa_eligibility_{$this->user->id}";
        $cached = session($cacheKey);

        expect($cached)->not->toBeNull()
            ->and($cached['eligible'])->toBe($result->eligible)
            ->and($cached['reason'])->toBe($result->reason)
            ->and($cached['source'])->toBe($result->source)
            ->and($cached['expires'])->toBeGreaterThan(now()->timestamp);
    });

    it('returns cached result on subsequent calls', function () {
        // First call — no signals → not eligible
        $result1 = $this->service->isEligible($this->user);
        expect($result1->eligible)->toBeFalse();

        // Add a qualifying signal
        $follower = User::factory()->create();
        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Follow,
        ]);

        // Second call — should return cached (not eligible) since cache hasn't expired
        $result2 = $this->service->isEligible($this->user);
        expect($result2->eligible)->toBeFalse()
            ->and($result2->reason)->toBe('score_too_low');
    });

    it('reevaluate bypasses cache', function () {
        // First call — cached
        $this->service->isEligible($this->user);

        // Add a qualifying signal (game invitation)
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        // Reevaluate should detect the trypass
        $result = $this->service->reevaluate($this->user);
        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass');
    });

    it('expires cached result after TTL', function () {
        // First call
        $this->service->isEligible($this->user);

        // Manually expire the cache
        $cacheKey = "pwa_eligibility_{$this->user->id}";
        $cached = session($cacheKey);
        $cached['expires'] = now()->subMinute()->timestamp;
        session([$cacheKey => $cached]);

        // Add a qualifying signal (game invitation)
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        // Should re-evaluate (cache expired)
        $result = $this->service->isEligible($this->user);
        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass');
    });
});

// ── Edge Cases ─────────────────────────────────────────

describe('Edge cases', function () {
    it('single visit day does not count as 2', function () {
        UserAppVisit::factory()->create([
            'user_id' => $this->user->id,
            'visit_date' => now()->toDateString(),
        ]);

        // Only 1 signal (visits), need 2 of 3 → not eligible
        $result = $this->service->isEligible($this->user);
        expect($result->eligible)->toBeFalse()
            ->and($result->reason)->toBe('score_too_low');
    });

    it('does not count rejected or pending game participation', function () {
        $game = Game::factory()->create(['date_time' => now()->addDays(30)]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'rejected',
        ]);

        $result = $this->service->isEligible($this->user);
        expect($result->eligible)->toBeFalse();
    });

    it('does not count block relationships as social investment', function () {
        $other = User::factory()->create();
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $other->id,
            'type' => RelationshipType::Block,
        ]);

        $result = $this->service->isEligible($this->user);
        expect($result->eligible)->toBeFalse();
    });

    it('does not trypass for pending participant on upcoming game', function () {
        $game = Game::factory()->create(['date_time' => now()->addDays(3)]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $result = $this->service->isEligible($this->user);
        // Pending participant should not trigger trypass_game_upcoming
        expect($result->reason)->not->toBe('trypass_game_upcoming');
    });

    it('trypass game_joined uses participant created_at, not game created_at', function () {
        // Create an OLD game (created days ago, date_time in the past so it's not "upcoming")
        $oldGame = Game::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'created_at' => now()->subDays(7),
            'date_time' => now()->subDays(7),
        ]);

        // Participant was just approved (created_at = now, auto-set by model)
        GameParticipant::create([
            'user_id' => $this->user->id,
            'game_id' => $oldGame->id,
            'status' => 'approved',
            'role' => 'player',
        ]);

        $result = $this->service->isEligible($this->user);

        // SHOULD trigger trypass_game_joined because the participant's created_at
        // is within the 5-minute window, even though the game itself is old
        expect($result->eligible)->toBeTrue();
        expect($result->reason)->toBe('trypass_game_joined');
    });
});

// ── Logging Observability ───────────────────────────────

describe('Logging observability', function () {
    it('logs evaluated on first call and cache_hit on subsequent calls', function () {
        Log::shouldReceive('channel')->with('daily')->andReturnSelf();

        // First call → evaluated log (info level)
        Log::shouldReceive('info')
            ->once()
            ->with('pwa.eligibility.evaluated', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $this->user->id));

        $this->service->isEligible($this->user);

        // Second call → cache_hit log (debug level — reduced from info to avoid log spam)
        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('debug')
            ->once()
            ->with('pwa.eligibility.cache_hit', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $this->user->id));

        $this->service->isEligible($this->user);
    });

    it('logs cache_hit includes eligibility result', function () {
        // First call populates cache
        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('info')->once();
        $this->service->isEligible($this->user);

        // Second call — verify cache_hit log carries the eligibility fields (debug level)
        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('debug')
            ->once()
            ->with('pwa.eligibility.cache_hit', \Mockery::on(function ($ctx) {
                return $ctx['user_id'] === $this->user->id
                    && array_key_exists('eligible', $ctx)
                    && array_key_exists('reason', $ctx)
                    && array_key_exists('source', $ctx);
            }));

        $this->service->isEligible($this->user);
    });
});
