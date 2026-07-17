<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ParticipantLifecycle;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Support\Facades\Config;
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
