<?php

use App\Dto\JoinIntent;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\User;
use App\Services\OwnerParticipantService;
use App\Services\ParticipantLifecycle;
use App\Services\WaitlistService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── Helpers ──────────────────────────────────────────────

/**
 * A campaign with room to spare (the owner row and nobody else), so every
 * approval path below lands Approved rather than on an overflow branch.
 * Owner creation routes through OwnerParticipantService — a stamping surface.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{owner: User, campaign: Campaign}
 */
function campaignApprovedAtCampaignWithOwner(array $overrides = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'max_players' => 5,
        'min_players' => 2,
        ...$overrides,
    ]);

    app(OwnerParticipantService::class)->ensureCampaignOwnerParticipant($campaign);

    return ['owner' => $owner, 'campaign' => $campaign];
}

/**
 * A campaign at capacity (owner + maxPlayers-1 approved players) in waitlist
 * mode — the precondition WaitlistService::addToWaitlist() enforces.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{owner: User, campaign: Campaign}
 */
function campaignApprovedAtFullCampaign(int $maxPlayers = 2, array $overrides = []): array
{
    $setup = campaignApprovedAtCampaignWithOwner([
        'max_players' => $maxPlayers,
        'min_players' => 2,
        'bench_mode' => false,
        ...$overrides,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        CampaignParticipant::factory()->create([
            'campaign_id' => $setup['campaign']->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $setup;
}

/**
 * Free one seat by departing a non-owner player, so the next waitlisted
 * member can be promoted.
 */
function campaignApprovedAtOpenSlot(Campaign $campaign): void
{
    $campaign->participants()
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $campaign->owner_id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);
}

/**
 * Insert a participant row the way pre-migration data looks: a raw DB insert
 * that bypasses both the lifecycle services and the observer, so approved_at
 * is exactly what the caller passes (null unless stated).
 *
 * @param  array<string, mixed>  $overrides
 */
function campaignApprovedAtInsertRawRow(string $campaignId, array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('campaign_participants')->insert(array_merge([
        'id' => $id,
        'campaign_id' => $campaignId,
        'user_id' => User::factory()->create()->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
        'created_at' => now()->subDays(30),
        'approved_at' => null,
    ], $overrides));

    return $id;
}

/**
 * The migration's legacy backfill, re-run verbatim. Keep in lockstep with
 * database/migrations/2026_11_23_201812_add_approved_at_to_campaign_participants_table.php
 * (its up() runs the same statement once, at deploy time).
 */
function campaignApprovedAtRunLegacyBackfill(): void
{
    DB::table('campaign_participants')
        ->where('status', 'approved')
        ->whereNull('approved_at')
        ->update([
            'approved_at' => DB::raw(
                'coalesce('
                .'campaign_participants.created_at, '
                .'(select campaigns.created_at from campaigns where campaigns.id = campaign_participants.campaign_id), '
                .'now()'
                .')',
            ),
        ]);
}

// ═══════════════════════════════════════════════════════════
// approved_at stamping — every campaign approval write
// ═══════════════════════════════════════════════════════════

describe('campaign approved_at stamping', function () {
    it('stamps approved_at when the owner participant is created via the service', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        // The production owner-ensure path: no explicit stamp, the observer supplies it.
        app(OwnerParticipantService::class)->ensureCampaignOwnerParticipant($campaign);

        $ownerParticipant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $owner->id)
            ->first();

        expect($ownerParticipant)->not->toBeNull()
            ->and($ownerParticipant->role)->toBe(ParticipantRole::Owner)
            ->and($ownerParticipant->status)->toBe(ParticipantStatus::Approved)
            ->and($ownerParticipant->approved_at)->not->toBeNull();
    });

    it('stamps approved_at when a member joins directly with room available (join intake)', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        $joiner = User::factory()->create(['profile_complete' => true]);

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            JoinIntent::join($campaign, $joiner, JoinSource::ShareLink),
        );

        expect($participant->status)->toBe(ParticipantStatus::Approved)
            ->and($participant->fresh()->approved_at)->not->toBeNull();
    });

    it('stamps approved_at when an invited member accepts their invitation', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        expect($participant->approved_at)->toBeNull(); // pre-condition: not yet stamped

        $result = app(ParticipantLifecycle::class)->acceptInvitation($participant->fresh(), $campaign, $invitedUser);

        $fresh = $participant->fresh();
        expect($result->success)->toBeTrue()
            ->and($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->role)->toBe(ParticipantRole::Player)
            ->and($fresh->approved_at)->not->toBeNull();
    });

    it('stamps approved_at when an applicant is approved', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        $applicant = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Pending->value,
            'message' => null,
        ]);

        $result = app(ParticipantLifecycle::class)->approveApplication($participant->fresh(), $campaign, $owner);

        $fresh = $participant->fresh();
        expect($result->success)->toBeTrue()
            ->and($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->role)->toBe(ParticipantRole::Player)
            ->and($fresh->approved_at)->not->toBeNull();
    });

    it('stamps approved_at when a waitlisted member confirms a promotion', function () {
        ['campaign' => $campaign] = campaignApprovedAtFullCampaign();
        $member = User::factory()->create(['profile_complete' => true]);

        $waitlisted = app(WaitlistService::class)->addToWaitlist($campaign, $member);
        expect($waitlisted->approved_at)->toBeNull(); // pre-condition

        campaignApprovedAtOpenSlot($campaign);
        $promoted = app(WaitlistService::class)->promoteNext($campaign);
        expect($promoted)->not->toBeNull()
            ->and($promoted->status)->toBe(ParticipantStatus::Pending)
            ->and($promoted->approved_at)->toBeNull(); // a promotion offer is not a seat yet

        app(WaitlistService::class)->confirmPromotion($promoted);

        $fresh = $promoted->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->approved_at)->not->toBeNull();
    });

    it('stamps approved_at on a manual host promotion off the waitlist', function () {
        ['campaign' => $campaign] = campaignApprovedAtFullCampaign();
        $member = User::factory()->create(['profile_complete' => true]);
        $waitlisted = app(WaitlistService::class)->addToWaitlist($campaign, $member);

        app(WaitlistService::class)->manuallyPromote($waitlisted);

        $fresh = $waitlisted->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->approved_at)->not->toBeNull();
    });

    it('stamps approved_at when a benched member is promoted off the bench', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        $benched = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => ParticipantStatus::Benched->value,
            'benched_at' => now()->subDay(),
        ]);

        app(ParticipantLifecycle::class)->promoteFromBench($benched);

        $fresh = $benched->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Approved)
            ->and($fresh->benched_at)->toBeNull()
            ->and($fresh->approved_at)->not->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// Only a confirmed seat is stamped — pending / waitlisted / benched never are
// (they are not a promise, so they must never lock the agreement)
// ═══════════════════════════════════════════════════════════

describe('approved_at stays null without a confirmed seat', function () {
    it('leaves approved_at null for a member who was never approved', function (ParticipantStatus $status, array $extra) {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => $status->value,
            ...$extra,
        ]);

        expect($participant->fresh()->approved_at)->toBeNull();
    })->with([
        'pending (invited)' => [ParticipantStatus::Pending, []],
        'waitlisted' => [ParticipantStatus::Waitlisted, ['waitlisted_at' => now()]],
        'benched' => [ParticipantStatus::Benched, ['benched_at' => now()]],
    ]);

    it('leaves approved_at null when an approval overflows to the waitlist (not a seat)', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignApprovedAtFullCampaign();
        $applicant = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Pending->value,
            'message' => null,
        ]);

        app(ParticipantLifecycle::class)->approveApplication($participant->fresh(), $campaign, $owner);

        $fresh = $participant->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($fresh->approved_at)->toBeNull();
    });

    it('keeps approved_at null on a raw DB insert that bypasses the model entirely', function () {
        // Same contract as game_participants (ApprovedAtStampingTest): the
        // column carries no DB-level default — the stamp comes from the
        // application layer (lifecycle seams + observer), never from the schema.
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();

        $rawId = campaignApprovedAtInsertRawRow($campaign->id);

        expect(DB::table('campaign_participants')->where('id', $rawId)->value('approved_at'))->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// Observer backstop (CampaignParticipantObserver::saving) — fail-closed
// ═══════════════════════════════════════════════════════════

describe('observer backstop', function () {
    it('stamps approved_at via the observer when a create omits it', function () {
        // A future approval path that forgets the explicit stamp must not
        // leave a joined campaign silently unlocked.
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            // explicitly NO approved_at — the observer must supply it
        ]);

        expect($participant->fresh()->approved_at)->not->toBeNull();
    });

    it('stamps approved_at via the observer on an update that transitions to Approved', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => ParticipantStatus::Pending->value,
        ]);
        expect($participant->approved_at)->toBeNull();

        $participant->update(['status' => ParticipantStatus::Approved->value]);

        expect($participant->fresh()->approved_at)->not->toBeNull();
    });

    it('does not overwrite an existing stamp when an approved row is re-saved', function () {
        // Only stamps when missing: an unrelated re-save must not move the
        // approval time (the lock displays "locked since …").
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $approvedAt = $participant->fresh()->approved_at;
        expect($approvedAt)->not->toBeNull();

        $this->travelTo(now()->addHour(), fn () => $participant->fresh()->update(['confirmation_attempts' => 2]));

        expect($participant->fresh()->approved_at?->equalTo($approvedAt))->toBeTrue();
    });

    it('keeps the stamp when an approved member departs ("has EVER had a confirmed member")', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        $member = User::factory()->create(['profile_complete' => true]);
        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $member->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $approvedAt = $participant->fresh()->approved_at;
        expect($approvedAt)->not->toBeNull();

        app(ParticipantLifecycle::class)->depart($participant->fresh(), $member);

        $row = $participant->fresh();
        expect($row->status)->toBe(ParticipantStatus::Rejected)
            ->and($row->approved_at)->not->toBeNull()
            ->and($row->approved_at?->equalTo($approvedAt))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// Legacy backfill (proves the migration's UPDATE logic)
// ═══════════════════════════════════════════════════════════

describe('legacy backfill', function () {
    it('backfills approved_at = created_at for legacy Approved rows with null approved_at', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();

        // A pre-migration Approved row: an explicit created_at, no approved_at.
        $legacyId = campaignApprovedAtInsertRawRow($campaign->id);

        campaignApprovedAtRunLegacyBackfill();

        $row = DB::table('campaign_participants')->where('id', $legacyId)->first();
        expect($row->approved_at)->not->toBeNull()
            ->and($row->approved_at)->toBe($row->created_at);
    });

    it('falls back to the campaign created_at for legacy Approved rows that predate the created_at column', function () {
        // campaign_participants.created_at was added nullable WITHOUT a
        // backfill (e06ed724, 2026-07-13), so every row older than that
        // carries NULL created_at. A bare created_at copy would leave those
        // rows — the oldest campaigns' confirmed members — unstamped, and
        // their campaigns unlocked after deploy.
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        $legacyId = campaignApprovedAtInsertRawRow($campaign->id, ['created_at' => null]);

        campaignApprovedAtRunLegacyBackfill();

        $row = DB::table('campaign_participants')->where('id', $legacyId)->first();
        expect($row->approved_at)->not->toBeNull()
            ->and($row->approved_at)->toBe(DB::table('campaigns')->where('id', $campaign->id)->value('created_at'));
    });

    it('never leaves a legacy Approved row unstamped — the last resort is now()', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        DB::table('campaigns')->where('id', $campaign->id)->update(['created_at' => null]);
        $legacyId = campaignApprovedAtInsertRawRow($campaign->id, ['created_at' => null]);

        campaignApprovedAtRunLegacyBackfill();

        $row = DB::table('campaign_participants')->where('id', $legacyId)->first();
        expect($row->approved_at)->not->toBeNull();

        // The DB's now() and PHP's now() can straddle a second boundary, so
        // assert a window rather than an exact string: the value is a fresh
        // stamp, not either NULL source.
        $stampedAt = Carbon::parse((string) $row->approved_at);
        expect($stampedAt->gte(now()->subMinute()))->toBeTrue()
            ->and($stampedAt->lte(now()->addMinute()))->toBeTrue();
    });

    it('leaves legacy rows that are not Approved untouched', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();

        $notApproved = [
            campaignApprovedAtInsertRawRow($campaign->id, ['status' => ParticipantStatus::Rejected->value]),
            campaignApprovedAtInsertRawRow($campaign->id, ['status' => ParticipantStatus::Removed->value]),
            campaignApprovedAtInsertRawRow($campaign->id, ['status' => ParticipantStatus::Waitlisted->value]),
            campaignApprovedAtInsertRawRow($campaign->id, ['status' => ParticipantStatus::Benched->value]),
        ];

        campaignApprovedAtRunLegacyBackfill();

        $stamped = DB::table('campaign_participants')->whereIn('id', $notApproved)->whereNotNull('approved_at')->count();
        expect($stamped)->toBe(0);
    });

    it('respects an existing approved_at (the backfill is idempotent)', function () {
        ['campaign' => $campaign] = campaignApprovedAtCampaignWithOwner();
        $legacyId = campaignApprovedAtInsertRawRow($campaign->id, ['approved_at' => now()->subDays(5)]);
        $explicit = DB::table('campaign_participants')->where('id', $legacyId)->value('approved_at');
        expect($explicit)->not->toBeNull();

        campaignApprovedAtRunLegacyBackfill();

        expect(DB::table('campaign_participants')->where('id', $legacyId)->value('approved_at'))->toBe($explicit);
    });
});
