<?php

namespace Tests\Feature\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\GameJoinGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;

uses(DatabaseTransactions::class);

/**
 * Eligible public scheduled game owned by someone else — the happy-path
 * baseline both direct-join surfaces start from.
 */
function gateJoinableGame(array $attributes = []): Game
{
    $owner = User::factory()->create();

    return Game::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'visibility' => Visibility::Public->value,
        'status' => GameStatus::Scheduled->value,
        'max_players' => 6,
        'date_time' => now()->addDays(3),
    ], $attributes));
}

function gameJoinGate(): GameJoinGate
{
    return app(GameJoinGate::class);
}

// ── canJoinViaShareLink — eligibility matrix ────────

describe('canJoinViaShareLink', function () {
    it('returns false for a guest (null viewer)', function () {
        expect(gameJoinGate()->canJoinViaShareLink(gateJoinableGame(), null, true, false))->toBeFalse();
    });

    it('returns true for an eligible viewer with a valid share token', function () {
        $game = gateJoinableGame(['visibility' => Visibility::Protected->value]);
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaShareLink($game, $viewer, true, false))->toBeTrue();
    });

    it('returns true with a valid short link even without a share token', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaShareLink($game, $viewer, false, true))->toBeTrue();
    });

    it('returns false without a valid share token or short link', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaShareLink($game, $viewer, false, false))->toBeFalse();
    });

    it('returns false for the owner', function () {
        $owner = User::factory()->create();
        $game = gateJoinableGame(['owner_id' => $owner->id]);

        expect(gameJoinGate()->canJoinViaShareLink($game, $owner, true, false))->toBeFalse();
    });

    it('returns false for each join-blocking participant status', function (ParticipantStatus $status) {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $viewer->id,
            'role' => ParticipantRole::Player->value,
            'status' => $status->value,
        ]);

        expect(gameJoinGate()->canJoinViaShareLink($game, $viewer->fresh(), true, false))->toBeFalse();
    })->with([
        ParticipantStatus::Approved,
        ParticipantStatus::Pending,
        ParticipantStatus::Waitlisted,
        ParticipantStatus::Benched,
        // Removed is the moderation lock: host removal must not be undone by
        // a share-link self-rejoin (reactivation would flip Removed → Approved).
        ParticipantStatus::Removed,
    ]);

    it('returns true for a rejected participant row (only the five blocking statuses gate)', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $viewer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Rejected->value,
        ]);

        expect(gameJoinGate()->canJoinViaShareLink($game, $viewer->fresh(), true, false))->toBeTrue();
    });

    it('returns false for a completed game', function () {
        $game = gateJoinableGame(['status' => GameStatus::Completed->value]);
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaShareLink($game, $viewer, true, false))->toBeFalse();
    });

    it('returns false for a canceled game', function () {
        $game = gateJoinableGame(['status' => GameStatus::Canceled->value]);
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaShareLink($game, $viewer, true, false))->toBeFalse();
    });

    it('returns true for a full game (capacity is handled by overflow routing, not eligibility)', function () {
        // Owner holds the single seat (max_players=1) — the game is full, yet
        // the gate passes: the join write routes to waitlist/bench instead.
        $game = gateJoinableGame(['max_players' => 1]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $game->owner_id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaShareLink($game, $viewer, true, false))->toBeTrue();
    });
});

// ── canJoinViaDiscord — eligibility matrix ──────────

describe('canJoinViaDiscord', function () {
    it('returns false for a guest (null viewer)', function () {
        expect(gameJoinGate()->canJoinViaDiscord(gateJoinableGame(), null))->toBeFalse();
    });

    it('returns true for an eligible viewer on a public game', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaDiscord($game, $viewer))->toBeTrue();
    });

    it('returns false for non-public games (?discord_join=1 is forgeable; screening bypass lock)', function (Visibility $visibility) {
        $game = gateJoinableGame(['visibility' => $visibility->value]);
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaDiscord($game, $viewer))->toBeFalse();
    })->with([Visibility::Protected, Visibility::Private]);

    it('returns false for the owner', function () {
        $owner = User::factory()->create();
        $game = gateJoinableGame(['owner_id' => $owner->id]);

        expect(gameJoinGate()->canJoinViaDiscord($game, $owner))->toBeFalse();
    });

    it('returns false for each join-blocking participant status', function (ParticipantStatus $status) {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $viewer->id,
            'role' => ParticipantRole::Player->value,
            'status' => $status->value,
        ]);

        expect(gameJoinGate()->canJoinViaDiscord($game, $viewer->fresh()))->toBeFalse();
    })->with([
        ParticipantStatus::Approved,
        ParticipantStatus::Pending,
        ParticipantStatus::Waitlisted,
        ParticipantStatus::Benched,
        ParticipantStatus::Removed,
    ]);

    it('returns false for a completed game', function () {
        $game = gateJoinableGame(['status' => GameStatus::Completed->value]);
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaDiscord($game, $viewer))->toBeFalse();
    });

    it('returns false for a canceled game', function () {
        $game = gateJoinableGame(['status' => GameStatus::Canceled->value]);
        $viewer = User::factory()->create();

        expect(gameJoinGate()->canJoinViaDiscord($game, $viewer))->toBeFalse();
    });
});

// ── signup cutoff (decision D124) ───────────────────

describe('signup cutoff', function () {
    it('blocks both surfaces once the cutoff has passed', function () {
        $game = gateJoinableGame(['signup_cutoff_at' => now()->subHour()]);
        $viewer = User::factory()->create();

        $gate = gameJoinGate();

        expect($gate->canJoinViaShareLink($game, $viewer, true, false))->toBeFalse()
            ->and($gate->canJoinViaDiscord($game, $viewer))->toBeFalse();
    });

    it('allows both surfaces while the cutoff is in the future', function () {
        $game = gateJoinableGame(['signup_cutoff_at' => now()->addDays(5)]);
        $viewer = User::factory()->create();

        $gate = gameJoinGate();

        expect($gate->canJoinViaShareLink($game, $viewer, true, false))->toBeTrue()
            ->and($gate->canJoinViaDiscord($game, $viewer))->toBeTrue();
    });

    it('allows both surfaces with a null cutoff (signups open)', function () {
        $game = gateJoinableGame(['signup_cutoff_at' => null]);
        $viewer = User::factory()->create();

        $gate = gameJoinGate();

        expect($gate->canJoinViaShareLink($game, $viewer, true, false))->toBeTrue()
            ->and($gate->canJoinViaDiscord($game, $viewer))->toBeTrue();
    });
});

// ── join rate limit ─────────────────────────────────

describe('join rate limit', function () {
    it('allows the configured attempts per viewer per game, then blocks', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();
        $gate = gameJoinGate();

        for ($attempt = 0; $attempt < GameJoinGate::JOIN_ATTEMPT_LIMIT; $attempt++) {
            expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeTrue();
        }

        expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeFalse();
    });

    it('records attempts under the historical share-join key format', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        gameJoinGate()->allowsShareJoinAttempt($game, $viewer);

        expect(RateLimiter::attempts('share-join:'.$viewer->id.':'.$game->id))->toBe(1);
    });

    it('records discord attempts under a separate discord-join bucket', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();
        $gate = gameJoinGate();

        // Exhaust the share bucket entirely.
        for ($attempt = 0; $attempt < GameJoinGate::JOIN_ATTEMPT_LIMIT; $attempt++) {
            $gate->allowsShareJoinAttempt($game, $viewer);
        }
        expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeFalse();

        // The discord bucket is independent and still open.
        expect($gate->allowsDiscordJoinAttempt($game, $viewer))->toBeTrue()
            ->and(RateLimiter::attempts('discord-join:'.$viewer->id.':'.$game->id))->toBe(1);
    });

    it('buckets per viewer and per game', function () {
        $game = gateJoinableGame();
        $otherGame = gateJoinableGame();
        $viewer = User::factory()->create();
        $otherViewer = User::factory()->create();
        $gate = gameJoinGate();

        for ($attempt = 0; $attempt < GameJoinGate::JOIN_ATTEMPT_LIMIT; $attempt++) {
            $gate->allowsShareJoinAttempt($game, $viewer);
        }
        expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeFalse();

        // Neither a different viewer on the same game nor the same viewer on
        // a different game shares the bucket.
        expect($gate->allowsShareJoinAttempt($game, $otherViewer))->toBeTrue()
            ->and($gate->allowsShareJoinAttempt($otherGame, $viewer))->toBeTrue();
    });

    it('releases attempts after the decay window', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();
        $gate = gameJoinGate();

        for ($attempt = 0; $attempt < GameJoinGate::JOIN_ATTEMPT_LIMIT; $attempt++) {
            $gate->allowsShareJoinAttempt($game, $viewer);
        }
        expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeFalse();

        $this->travel(GameJoinGate::JOIN_ATTEMPT_DECAY_SECONDS + 1)->seconds();

        expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeTrue();
    });
});
