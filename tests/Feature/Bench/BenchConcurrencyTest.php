<?php

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\BenchService;

beforeEach(function () {
    $this->service = app(BenchService::class);
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

it('prevents double-bench with concurrent requests', function () {
    $campaign = Campaign::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => 'Concurrency Test Campaign',
        'description' => 'Testing concurrency',
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => 2,
    ]);

    // Fill campaign
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $applicant = User::factory()->create();

    // First bench succeeds
    $this->service->addToBench($campaign, $applicant);

    // Second bench attempt should fail because user is already a participant
    expect(fn () => $this->service->addToBench($campaign, $applicant))
        ->toThrow(\LogicException::class, 'User is already a participant.');

    // Confirm only one benched participant exists
    $benchedCount = $campaign->participants()
        ->where('status', ParticipantStatus::Benched->value)
        ->where('user_id', $applicant->id)
        ->count();

    expect($benchedCount)->toBe(1);
});

it('prevents double-promote with concurrent requests', function () {
    $campaign = Campaign::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => 'Promote Concurrency Test',
        'description' => 'Testing promote concurrency',
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => 2,
    ]);

    // Fill campaign
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $benchedUser = User::factory()->create();
    $participant = $this->service->addToBench($campaign, $benchedUser);

    // Open a slot
    $campaign->participants()
        ->where('role', 'player')
        ->where('status', ParticipantStatus::Approved->value)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);

    // First promote succeeds
    $this->service->promoteFromBench($participant->id, 'campaign');

    // Refresh participant — now approved
    $participant->refresh();
    expect($participant->status)->toBe(ParticipantStatus::Approved);

    // Second promote should fail because participant is no longer benched
    expect(fn () => $this->service->promoteFromBench($participant->id, 'campaign'))
        ->toThrow(\LogicException::class, 'Participant is not on the bench.');
});
