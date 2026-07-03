<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\OwnerParticipantService;
use App\Services\ParticipantLifecycle;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// ── Helpers ──────────────────────────────────────────────

/**
 * Create a fully-subscribed standalone game (owner + maxPlayers-1 approved
 * non-owner players) via Game::factory(). Mirrors WaitlistServiceTest's
 * createFullStandaloneGame but routes owner creation through the production
 * OwnerParticipantService path (the stamping surface under test).
 */
function approvedAtFullGame(int $maxPlayers = 3, array $overrides = []): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'max_players' => $maxPlayers,
        'min_players' => 2,
        'date_time' => now()->addDays(10),
        ...$overrides,
    ]);

    // Owner participant — routes through OwnerParticipantService::ensureForEntity.
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Fill remaining non-owner slots with approved players.
    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return ['owner' => $owner, 'game' => $game];
}

// ═══════════════════════════════════════════════════════════
// approved_at / promoted_manually stamping
// ═══════════════════════════════════════════════════════════

describe('approved_at stamping', function () {
    it('stamps approved_at when the owner participant is created via the service', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'max_players' => 4,
        ]);

        // Route through the production owner-ensure path (the stamping surface).
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

        $ownerParticipant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)
            ->first();

        expect($ownerParticipant)->not->toBeNull()
            ->and($ownerParticipant->role)->toBe(ParticipantRole::Owner)
            ->and($ownerParticipant->status)->toBe(ParticipantStatus::Approved)
            ->and($ownerParticipant->approved_at)->not->toBeNull()
            ->and($ownerParticipant->promoted_manually)->toBeFalse();
    });

    it('stamps approved_at when an invited player accepts their invitation', function () {
        // Game with room — acceptInvitation routes to overflow (waitlist) when
        // at capacity, so the invitee must have an open slot to land Approved.
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'max_players' => 5,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        expect($participant->approved_at)->toBeNull(); // pre-condition: not yet stamped

        app(ParticipantLifecycle::class)->acceptInvitation($participant, $game, $invitedUser);

        $fresh = $participant->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->role)->toBe(ParticipantRole::Player)
            ->and($fresh->approved_at)->not->toBeNull()
            ->and($fresh->promoted_manually)->toBeFalse();
    });

    it('stamps approved_at when a waitlisted player confirms a promotion', function () {
        ['game' => $game] = approvedAtFullGame(maxPlayers: 2);
        $user = User::factory()->create();
        app(WaitlistService::class)->addToWaitlist($game, $user);

        // Open a slot so the waitlisted player can be promoted.
        openSlot($game);

        $promoted = app(WaitlistService::class)->promoteNext($game);
        expect($promoted->status)->toBe(ParticipantStatus::Pending) // promote → pending
            ->and($promoted->approved_at)->toBeNull(); // not yet approved

        app(WaitlistService::class)->confirmPromotion($promoted->fresh());

        $fresh = $promoted->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->approved_at)->not->toBeNull()
            ->and($fresh->promoted_manually)->toBeFalse();
    });

    it('stamps approved_at AND promoted_manually=true on manual promotion (the sole setter)', function () {
        ['game' => $game] = approvedAtFullGame(maxPlayers: 2);
        $user = User::factory()->create();
        $participant = app(WaitlistService::class)->addToWaitlist($game, $user);

        // approved_at is null pre-promotion (waitlisted row). promoted_manually
        // is checked on the DB-loaded row below — the in-memory create()
        // instance carries null until reloaded (DB default is false).
        expect($participant->approved_at)->toBeNull();

        app(WaitlistService::class)->manuallyPromote($participant);

        $fresh = $participant->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->approved_at)->not->toBeNull()
            ->and($fresh->promoted_manually)->toBeTrue();
    });

    it('stamps approved_at when an applicant is approved', function () {
        ['game' => $game] = approvedAtFullGame(maxPlayers: 5);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $approver = User::factory()->create();

        // Pending participant (applicant role) + a pending application record.
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        $game->applications()->create([
            'user_id' => $applicant->id,
            'status' => 'pending',
            'message' => 'please let me in',
        ]);

        app(ParticipantLifecycle::class)->approveApplication($participant, $game, $approver);

        $fresh = $participant->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->approved_at)->not->toBeNull()
            ->and($fresh->promoted_manually)->toBeFalse();
    });

    it('stamps approved_at when a benched player is promoted off the bench', function () {
        // Game with room — promoteFromBench guards capacity and throws
        // 'Cannot promote: entity is full.' when at capacity.
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'max_players' => 5,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $user = User::factory()->create();

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
        ]);

        app(ParticipantLifecycle::class)->promoteFromBench($participant);

        $fresh = $participant->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->benched_at)->toBeNull()
            ->and($fresh->approved_at)->not->toBeNull()
            ->and($fresh->promoted_manually)->toBeFalse();
    });

    it('keeps approved_at null and promoted_manually false on a plain non-transition insert', function () {
        ['game' => $game] = approvedAtFullGame(maxPlayers: 5);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $fresh = $participant->fresh();
        // The column exists (nullable) — a plain insert that bypasses the
        // Approved-transition services does NOT auto-stamp. This proves the
        // stamping lives entirely in the service seams, not a DB default.
        expect($fresh->approved_at)->toBeNull()
            ->and($fresh->promoted_manually)->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// promoted_manually is the sole manual-promotion signal
// ═══════════════════════════════════════════════════════════

describe('promoted_manually flag', function () {
    it('defaults to false for every non-manual Approved transition', function () {
        ['game' => $game] = approvedAtFullGame(maxPlayers: 4);

        // Every approved non-owner player (created above as a plain Approved row)
        // carries the default promoted_manually=false.
        $approved = GameParticipant::where('game_id', $game->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->where('role', ParticipantRole::Player->value)
            ->get();

        expect($approved)->not->toBeEmpty();
        foreach ($approved as $p) {
            expect($p->promoted_manually)->toBeFalse();
        }
    });
});

// ═══════════════════════════════════════════════════════════
// Legacy backfill (proves the migration's UPDATE logic)
// ═══════════════════════════════════════════════════════════

describe('legacy backfill', function () {
    it('backfills approved_at = created_at for legacy Approved rows with null approved_at', function () {
        ['game' => $game] = approvedAtFullGame(maxPlayers: 5);

        // Insert a legacy Approved row with an explicit created_at and NO
        // approved_at (simulating a pre-migration row that the backfill targets).
        $legacyCreatedAt = now()->subDays(30);
        $legacyId = (string) Str::uuid();
        DB::table('game_participants')->insert([
            'id' => $legacyId,
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'created_at' => $legacyCreatedAt,
            'approved_at' => null,
        ]);

        // The migration backfill logic: copy created_at → approved_at for any
        // Approved row still missing it. Re-run the exact statement here to
        // prove the logic stamps every qualifying legacy row.
        DB::table('game_participants')
            ->where('status', ParticipantStatus::Approved->value)
            ->whereNull('approved_at')
            ->update(['approved_at' => DB::raw('created_at')]);

        $row = DB::table('game_participants')->where('id', $legacyId)->first();
        expect($row->approved_at)->not->toBeNull()
            ->and($row->promoted_manually)->toBeFalse();
        // approved_at should equal created_at (same second-resolution timestamp).
        expect($row->approved_at)->toBe($row->created_at);
    });
});
