<?php

namespace Tests\Feature\Services;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\EntityJoinGate;
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

function entityJoinGate(): EntityJoinGate
{
    return app(EntityJoinGate::class);
}

/**
 * Eligible active campaign owned by someone else — the happy-path baseline
 * for the campaign share-link surface (CampaignDetail).
 */
function gateJoinableCampaign(array $attributes = []): Campaign
{
    $owner = User::factory()->create();

    return Campaign::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'status' => CampaignStatus::Active->value,
    ], $attributes));
}

// ── canJoinViaShareLink — eligibility matrix ────────

describe('canJoinViaShareLink', function () {
    it('returns false for a guest (null viewer)', function () {
        expect(entityJoinGate()->canJoinViaShareLink(gateJoinableGame(), null, true, false))->toBeFalse();
    });

    it('returns true for an eligible viewer with a valid share token', function () {
        $game = gateJoinableGame(['visibility' => Visibility::Protected->value]);
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaShareLink($game, $viewer, true, false))->toBeTrue();
    });

    it('returns true with a valid short link even without a share token', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaShareLink($game, $viewer, false, true))->toBeTrue();
    });

    it('returns false without a valid share token or short link', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaShareLink($game, $viewer, false, false))->toBeFalse();
    });

    it('returns false for the owner', function () {
        $owner = User::factory()->create();
        $game = gateJoinableGame(['owner_id' => $owner->id]);

        expect(entityJoinGate()->canJoinViaShareLink($game, $owner, true, false))->toBeFalse();
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

        expect(entityJoinGate()->canJoinViaShareLink($game, $viewer->fresh(), true, false))->toBeFalse();
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

        expect(entityJoinGate()->canJoinViaShareLink($game, $viewer->fresh(), true, false))->toBeTrue();
    });

    it('returns false for a completed game', function () {
        $game = gateJoinableGame(['status' => GameStatus::Completed->value]);
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaShareLink($game, $viewer, true, false))->toBeFalse();
    });

    it('returns false for a canceled game', function () {
        $game = gateJoinableGame(['status' => GameStatus::Canceled->value]);
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaShareLink($game, $viewer, true, false))->toBeFalse();
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

        expect(entityJoinGate()->canJoinViaShareLink($game, $viewer, true, false))->toBeTrue();
    });
});

// ── canJoinViaDiscord — eligibility matrix ──────────

describe('canJoinViaDiscord', function () {
    it('returns false for a guest (null viewer)', function () {
        expect(entityJoinGate()->canJoinViaDiscord(gateJoinableGame(), null))->toBeFalse();
    });

    it('returns true for an eligible viewer on a public game', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaDiscord($game, $viewer))->toBeTrue();
    });

    it('returns false for non-public games (?discord_join=1 is forgeable; screening bypass lock)', function (Visibility $visibility) {
        $game = gateJoinableGame(['visibility' => $visibility->value]);
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaDiscord($game, $viewer))->toBeFalse();
    })->with([Visibility::Protected, Visibility::Private]);

    it('returns false for the owner', function () {
        $owner = User::factory()->create();
        $game = gateJoinableGame(['owner_id' => $owner->id]);

        expect(entityJoinGate()->canJoinViaDiscord($game, $owner))->toBeFalse();
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

        expect(entityJoinGate()->canJoinViaDiscord($game, $viewer->fresh()))->toBeFalse();
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

        expect(entityJoinGate()->canJoinViaDiscord($game, $viewer))->toBeFalse();
    });

    it('returns false for a canceled game', function () {
        $game = gateJoinableGame(['status' => GameStatus::Canceled->value]);
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaDiscord($game, $viewer))->toBeFalse();
    });
});

// ── signup cutoff (decision D124) ───────────────────

describe('signup cutoff', function () {
    it('blocks both surfaces once the cutoff has passed', function () {
        $game = gateJoinableGame(['signup_cutoff_at' => now()->subHour()]);
        $viewer = User::factory()->create();

        $gate = entityJoinGate();

        expect($gate->canJoinViaShareLink($game, $viewer, true, false))->toBeFalse()
            ->and($gate->canJoinViaDiscord($game, $viewer))->toBeFalse();
    });

    it('allows both surfaces while the cutoff is in the future', function () {
        $game = gateJoinableGame(['signup_cutoff_at' => now()->addDays(5)]);
        $viewer = User::factory()->create();

        $gate = entityJoinGate();

        expect($gate->canJoinViaShareLink($game, $viewer, true, false))->toBeTrue()
            ->and($gate->canJoinViaDiscord($game, $viewer))->toBeTrue();
    });

    it('allows both surfaces with a null cutoff (signups open)', function () {
        $game = gateJoinableGame(['signup_cutoff_at' => null]);
        $viewer = User::factory()->create();

        $gate = entityJoinGate();

        expect($gate->canJoinViaShareLink($game, $viewer, true, false))->toBeTrue()
            ->and($gate->canJoinViaDiscord($game, $viewer))->toBeTrue();
    });
});

// ── join rate limit ─────────────────────────────────

describe('join rate limit', function () {
    it('allows the configured attempts per viewer per game, then blocks', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();
        $gate = entityJoinGate();

        for ($attempt = 0; $attempt < EntityJoinGate::JOIN_ATTEMPT_LIMIT; $attempt++) {
            expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeTrue();
        }

        expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeFalse();
    });

    it('records attempts under the historical share-join key format', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();

        entityJoinGate()->allowsShareJoinAttempt($game, $viewer);

        expect(RateLimiter::attempts('share-join:'.$viewer->id.':'.$game->id))->toBe(1);
    });

    it('records discord attempts under a separate discord-join bucket', function () {
        $game = gateJoinableGame();
        $viewer = User::factory()->create();
        $gate = entityJoinGate();

        // Exhaust the share bucket entirely.
        for ($attempt = 0; $attempt < EntityJoinGate::JOIN_ATTEMPT_LIMIT; $attempt++) {
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
        $gate = entityJoinGate();

        for ($attempt = 0; $attempt < EntityJoinGate::JOIN_ATTEMPT_LIMIT; $attempt++) {
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
        $gate = entityJoinGate();

        for ($attempt = 0; $attempt < EntityJoinGate::JOIN_ATTEMPT_LIMIT; $attempt++) {
            $gate->allowsShareJoinAttempt($game, $viewer);
        }
        expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeFalse();

        $this->travel(EntityJoinGate::JOIN_ATTEMPT_DECAY_SECONDS + 1)->seconds();

        expect($gate->allowsShareJoinAttempt($game, $viewer))->toBeTrue();
    });
});

// ── campaigns — the same gate serves CampaignDetail's share-link surface ──

describe('campaigns', function () {
    it('allows an eligible viewer with a valid share token', function () {
        $campaign = gateJoinableCampaign();
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaShareLink($campaign, $viewer, true, false))->toBeTrue();
    });

    it('returns false for a guest and for the owner', function () {
        $owner = User::factory()->create();
        $campaign = gateJoinableCampaign(['owner_id' => $owner->id]);
        $viewer = User::factory()->create();
        $gate = entityJoinGate();

        expect($gate->canJoinViaShareLink($campaign, null, true, false))->toBeFalse()
            ->and($gate->canJoinViaShareLink($campaign, $owner, true, false))->toBeFalse();
    });

    it('blocks host-removed participants from self-rejoining (moderation lock)', function () {
        $campaign = gateJoinableCampaign();
        $viewer = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $viewer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Removed->value,
        ]);

        expect(entityJoinGate()->canJoinViaShareLink($campaign, $viewer->fresh(), true, false))->toBeFalse();
    });

    it('returns true for a rejected row (declined invitations stay rejoined)', function () {
        $campaign = gateJoinableCampaign();
        $viewer = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $viewer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Rejected->value,
        ]);

        expect(entityJoinGate()->canJoinViaShareLink($campaign, $viewer->fresh(), true, false))->toBeTrue();
    });

    it('returns false for cancelled and completed campaigns', function (CampaignStatus $status) {
        $campaign = gateJoinableCampaign(['status' => $status->value]);
        $viewer = User::factory()->create();

        expect(entityJoinGate()->canJoinViaShareLink($campaign, $viewer, true, false))->toBeFalse();
    })->with([CampaignStatus::Cancelled, CampaignStatus::Completed]);

    it('rate-limits share attempts under the same share-join bucket format', function () {
        $campaign = gateJoinableCampaign();
        $viewer = User::factory()->create();

        entityJoinGate()->allowsShareJoinAttempt($campaign, $viewer);

        expect(RateLimiter::attempts('share-join:'.$viewer->id.':'.$campaign->id))->toBe(1);
    });
});
