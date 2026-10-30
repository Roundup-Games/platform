<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ParticipantLifecycle;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\Helpers\TestablePostHogClient;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    $setup = $this->createGameWithOwner([
        'date_time' => now()->addDays(7),
    ]);
    $this->owner = $setup['owner'];
    $this->game = $setup['game'];
    $this->player = User::factory()->create(['profile_complete' => true]);
    $this->participant = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $this->player->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);
});

it('records removed_by and removed_at on departure (closes the audit gap)', function () {
    app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->owner);

    $row = $this->participant->fresh();

    expect($row->removed_by)->toBe($this->owner->id)
        ->and($row->removed_at)->not->toBeNull()
        ->and($row->status)->toBe(ParticipantStatus::Rejected);
});

it('records the participant themselves as remover on self-leave', function () {
    app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->player);

    expect($this->participant->fresh()->removed_by)->toBe($this->player->id);
});

it('emits the pre-removal status (not "removed") in the participant.removed analytics event', function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');
    $posthog = new TestablePostHogClient;
    $this->app->instance(PostHogClient::class, $posthog);
    $checker = $this->mock(PostHogConsentChecker::class);
    $checker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    $this->app->instance(PostHogConsentChecker::class, $checker);

    app(ParticipantLifecycle::class)->removeParticipant($this->participant->fresh(), $this->game, $this->owner);

    $removed = collect($posthog->capturedCalls)
        ->first(fn (array $c) => ($c['event'] ?? null) === 'participant.removed');
    expect($removed)->not->toBeNull()
        ->and($removed['properties']['previous_status'])->toBe('approved');
});

it('nulls removed_by when no remover is supplied (system-initiated)', function () {
    app(ParticipantLifecycle::class)->depart($this->participant->fresh());

    $row = $this->participant->fresh();

    expect($row->removed_by)->toBeNull()
        ->and($row->removed_at)->not->toBeNull();
});

it('scores attendance LateCancel when departing within the late-cancel window', function () {
    // Game 6 hours out — inside the default 24h late-cancel window.
    $this->game->update(['date_time' => now()->addHours(6)]);

    app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->owner);

    expect($this->participant->fresh()->attendance_status)->toBe(AttendanceStatus::LateCancel);
});

it('scores attendance CancelledEarly when departing outside the late-cancel window', function () {
    // Game 7 days out — well outside the 24h window.
    app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->owner);

    expect($this->participant->fresh()->attendance_status)->toBe(AttendanceStatus::CancelledEarly);
});

it('does not score attendance when the participant was not Approved', function () {
    $this->participant->update(['status' => ParticipantStatus::Waitlisted->value]);

    app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->owner);

    expect($this->participant->fresh()->attendance_status)->toBeNull();
});

it('does not score attendance when the game has no future date_time', function () {
    $this->game->update(['date_time' => now()->subDays(1)]);

    app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->owner);

    expect($this->participant->fresh()->attendance_status)->toBeNull();
});

it('does not score attendance for campaign departures (campaign_participants has no attendance_status column)', function () {
    $setup = $this->createCampaignWithOwner();
    $owner = $setup['owner'];
    $campaign = $setup['campaign'];
    $player = User::factory()->create(['profile_complete' => true]);
    $participant = CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $player->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    app(ParticipantLifecycle::class)->depart($participant->fresh(), $player);

    $row = $participant->fresh();

    // Audit gap still closes for campaigns even though attendance scoring
    // is game-only — the audit trail is columnar, not scoring-specific.
    expect($row->removed_by)->toBe($player->id)
        ->and($row->removed_at)->not->toBeNull()
        ->and($row->status)->toBe(ParticipantStatus::Rejected);
});

// ── Departure cascade ownership (M072/S02) ────────────
//
// The cascade (waitlist promotion + below-min warning) is absorbed into
// ParticipantLifecycle: depart() and removeParticipant() invoke
// Roster::onDeparture themselves; seatless transitions never do.

it('cascades on depart: promotes the next waitlisted player and warns below-min exactly once', function () {
    $this->game->update(['min_players' => 3]);
    $waitlistedUser = User::factory()->create(['profile_complete' => true]);
    $waitlisted = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $waitlistedUser->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);

    Log::spy();

    app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->player);

    // The freed seat is offered to the next waitlisted player.
    expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Pending);

    // The cascade — including its below-min warnings — fired exactly once.
    // A call site hand-sequencing Roster::onDeparture() after depart() would
    // double-fire these (the double-cascade guard).
    Log::shouldHaveReceived('warning')
        ->with('game.below_min_players', Mockery::on(
            fn (array $context): bool => $context['game_id'] === $this->game->id,
        ))
        ->once();
    Log::shouldHaveReceived('warning')
        ->with('waitlist.below_min_players', Mockery::on(
            fn (array $context): bool => $context['game_id'] === $this->game->id,
        ))
        ->once();
});

it('cascades on removeParticipant: the next waitlisted player is promoted', function () {
    $waitlistedUser = User::factory()->create(['profile_complete' => true]);
    $waitlisted = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $waitlistedUser->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);

    $result = app(ParticipantLifecycle::class)->removeParticipant(
        $this->participant->fresh(),
        $this->game,
        $this->owner,
    );

    expect($result->success)->toBeTrue()
        ->and($this->participant->fresh()->status)->toBe(ParticipantStatus::Removed)
        ->and($waitlisted->fresh()->status)->toBe(ParticipantStatus::Pending);
});

it('does not cascade on application rejection — the waitlist is untouched', function () {
    // Approved (owner + player) sits below the factory max, so an open slot
    // exists: a wrongly fired departure cascade WOULD promote the waitlisted
    // player. A Pending(Applicant) row occupies no seat — rejecting it frees
    // nothing, so the cascade must not fire.
    $applicant = User::factory()->create(['profile_complete' => true]);
    $applicantRow = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $applicant->id,
        'role' => ParticipantRole::Applicant->value,
        'status' => ParticipantStatus::Pending->value,
    ]);
    GameApplication::create([
        'game_id' => $this->game->id,
        'user_id' => $applicant->id,
        'status' => ParticipantStatus::Pending->value,
        'message' => null,
    ]);
    $waitlistedUser = User::factory()->create(['profile_complete' => true]);
    $waitlisted = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $waitlistedUser->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);

    $result = app(ParticipantLifecycle::class)->rejectApplication(
        $applicantRow,
        $this->game,
        $this->owner,
    );

    expect($result->success)->toBeTrue()
        ->and($applicantRow->fresh())->toBeNull()
        ->and($waitlisted->fresh()->status)->toBe(ParticipantStatus::Waitlisted)
        ->and($waitlisted->fresh()->removed_at)->toBeNull();
});

it('does not cascade on invitation decline — the waitlist is untouched', function () {
    // Same seatless rule as rejectApplication: a Pending(Invited) row
    // occupies no seat, so declining frees nothing despite the open slot.
    $invitee = User::factory()->create(['profile_complete' => true]);
    $invited = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $invitee->id,
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
    ]);
    $waitlistedUser = User::factory()->create(['profile_complete' => true]);
    $waitlisted = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $waitlistedUser->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);

    $result = app(ParticipantLifecycle::class)->declineInvitation($invited, $invitee);

    // The decline is still audited (removed_by/removed_at via the internal
    // departure write) — it just never triggers the cascade.
    expect($result->success)->toBeTrue()
        ->and($invited->fresh()->status)->toBe(ParticipantStatus::Rejected)
        ->and($invited->fresh()->removed_by)->toBe($invitee->id)
        ->and($waitlisted->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
});

// ── M072 review follow-ups: cascade atomicity + rejoin policy ──────────

it('rolls the departure back when the seat-refill cascade fails', function () {
    $this->mock(WaitlistService::class)
        ->shouldReceive('promoteAllOnCancel')
        ->once()
        ->andThrow(new RuntimeException('cascade down'));

    expect(fn () => app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->player))
        ->toThrow(RuntimeException::class, 'cascade down');

    // The departure did not commit: the roster is unchanged, so a retry
    // lands on a consistent state instead of stranding an open seat with a
    // waitlisted player nothing re-promotes (the pre-fix swallow committed
    // the departure and logged the failure away).
    expect($this->participant->fresh()->status)->toBe(ParticipantStatus::Approved)
        ->and($this->participant->fresh()->removed_at)->toBeNull();
});

it('deletes the application row on departure so a re-application is not blocked', function () {
    $this->game->applications()->create([
        'user_id' => $this->player->id,
        'status' => 'approved',
    ]);

    app(ParticipantLifecycle::class)->depart($this->participant->fresh(), $this->player);

    expect($this->game->applications()->where('user_id', $this->player->id)->exists())->toBeFalse();
});

it('routes an approval to the waitlist when the game filled up meanwhile', function () {
    // Owner + the beforeEach player = 2 approved rows; max_players 2 = full.
    $this->game->update(['max_players' => 2]);

    $applicant = User::factory()->create(['profile_complete' => true]);
    $applicantRow = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $applicant->id,
        'role' => ParticipantRole::Applicant->value,
        'status' => ParticipantStatus::Pending->value,
    ]);
    $this->game->applications()->create([
        'user_id' => $applicant->id,
        'status' => 'pending',
    ]);

    $result = app(ParticipantLifecycle::class)->approveApplication(
        $applicantRow->fresh(),
        $this->game,
        $this->owner,
    );

    $row = $applicantRow->fresh();
    expect($result->success)->toBeTrue()
        ->and($result->messageKey)->toBe('common.flash_application_approved_waitlisted')
        ->and($row->status)->toBe(ParticipantStatus::Waitlisted)
        ->and($row->waitlisted_at)->not->toBeNull()
        ->and($row->approved_at)->toBeNull()
        // The application itself is resolved either way.
        ->and($this->game->applications()->where('user_id', $applicant->id)->first()->status)
        ->toBe(ParticipantStatus::Approved->value);
});

it('seats an approval when the game has room', function () {
    $applicant = User::factory()->create(['profile_complete' => true]);
    $applicantRow = GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $applicant->id,
        'role' => ParticipantRole::Applicant->value,
        'status' => ParticipantStatus::Pending->value,
    ]);
    $this->game->applications()->create([
        'user_id' => $applicant->id,
        'status' => 'pending',
    ]);

    $result = app(ParticipantLifecycle::class)->approveApplication(
        $applicantRow->fresh(),
        $this->game,
        $this->owner,
    );

    $row = $applicantRow->fresh();
    expect($result->success)->toBeTrue()
        ->and($result->messageKey)->toBe('common.flash_application_approved')
        ->and($row->status)->toBe(ParticipantStatus::Approved)
        ->and($row->role)->toBe(ParticipantRole::Player)
        ->and($row->approved_at)->not->toBeNull();
});
