<?php

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\User;
use App\Services\WaitlistService;
use App\Enums\ParticipantRole;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createFullCampaign(int $maxPlayers = 3, array $overrides = []): array
{
    $owner = User::factory()->create();
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'max_players' => $maxPlayers,
        'min_players' => 2,
        'bench_mode' => false,
        ...$overrides,
    ]);

    // Fill campaign with approved participants (including owner)
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return ['owner' => $owner, 'campaign' => $campaign];
}

function openCampaignSlot(Campaign $campaign): void
{
    $campaign->participants()
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $campaign->owner_id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);
}

// ── 1. Campaign (bench_mode=false) full → user joins waitlist ──

describe('campaign waitlist join', function () {
    it('adds user to waitlist when campaign is full and bench_mode=false', function () {
        ['campaign' => $campaign] = createFullCampaign();
        $user = User::factory()->create();

        $participant = $this->service->addToWaitlist($campaign, $user);

        expect($participant->status)->toBe(ParticipantStatus::Waitlisted);
        expect($participant->waitlisted_at)->not->toBeNull();
        expect($participant->user_id)->toBe($user->id);
    });
});

// ── 2. promoteNext promotes campaign participant → status pending, confirmation_expires_at set ──

describe('promoteNext for campaign', function () {
    it('promotes first waitlisted campaign participant to pending with confirmation deadline', function () {
        ['campaign' => $campaign] = createFullCampaign(maxPlayers: 2);
        $waitUser = User::factory()->create();
        $this->service->addToWaitlist($campaign, $waitUser);

        openCampaignSlot($campaign);

        $promoted = $this->service->promoteNext($campaign);

        expect($promoted)->not->toBeNull();
        expect($promoted->status)->toBe(ParticipantStatus::Pending);
        expect($promoted->confirmation_expires_at)->not->toBeNull();
        expect($promoted->user_id)->toBe($waitUser->id);

        // Campaigns use 'far' window (12h = 720min) since they have no date_time
        $minutesUntilExpiry = (int) round(now()->diffInMinutes($promoted->confirmation_expires_at, false));
        expect($minutesUntilExpiry)->toBe(720);
    });
});

// ── 3. confirmPromotion → status approved ──

describe('confirmPromotion for campaign', function () {
    it('confirms a campaign promotion within the window', function () {
        ['campaign' => $campaign] = createFullCampaign(maxPlayers: 2);
        $user = User::factory()->create();
        $this->service->addToWaitlist($campaign, $user);

        openCampaignSlot($campaign);

        $promoted = $this->service->promoteNext($campaign);

        $this->service->confirmPromotion($promoted);

        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Approved);
        expect($promoted->fresh()->confirmation_expires_at)->toBeNull();
    });
});

// ── 4. declinePromotion → status rejected, next promoted ──

describe('declinePromotion for campaign', function () {
    it('rejects campaign participant and promotes next in line', function () {
        ['campaign' => $campaign] = createFullCampaign(maxPlayers: 2);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->service->addToWaitlist($campaign, $user1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($campaign, $user2);

        openCampaignSlot($campaign);

        $promoted = $this->service->promoteNext($campaign);
        expect($promoted->user_id)->toBe($user1->id);

        $this->service->declinePromotion($promoted);

        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Rejected);

        // user2 should now be promoted
        $nextPromoted = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user2->id)
            ->first();
        expect($nextPromoted->status)->toBe(ParticipantStatus::Pending);
    });
});

// ── 5. handleExpiredConfirmation → back to waitlisted (attempts < MAX) ──

describe('handleExpiredConfirmation for campaign', function () {
    it('moves expired campaign participant to back of queue when under max attempts', function () {
        ['campaign' => $campaign] = createFullCampaign(maxPlayers: 2);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->service->addToWaitlist($campaign, $user1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($campaign, $user2);

        openCampaignSlot($campaign);

        $promoted = $this->service->promoteNext($campaign);
        expect($promoted->user_id)->toBe($user1->id);

        $originalWaitlistedAt = $promoted->waitlisted_at;

        $this->service->handleExpiredConfirmation($promoted);

        $refreshed = $promoted->fresh();
        expect($refreshed->status)->toBe(ParticipantStatus::Waitlisted);
        expect($refreshed->waitlisted_at)->not->toBeNull();
        expect($refreshed->waitlisted_at->isAfter($originalWaitlistedAt))->toBeTrue();
        expect($refreshed->confirmation_expires_at)->toBeNull();

        // user2 should now be promoted
        $user2Participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user2->id)
            ->first();
        expect($user2Participant->status)->toBe(ParticipantStatus::Pending);
    });
});

// ── 6. handleExpiredConfirmation → permanent rejection (attempts >= MAX) ──

describe('handleExpiredConfirmation max attempts', function () {
    it('permanently rejects campaign participant when max confirmation expirations reached', function () {
        ['campaign' => $campaign] = createFullCampaign(maxPlayers: 2);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->service->addToWaitlist($campaign, $user1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($campaign, $user2);

        openCampaignSlot($campaign);

        $promoted = $this->service->promoteNext($campaign);
        expect($promoted->user_id)->toBe($user1->id);

        // Simulate that this participant has already hit max expirations
        // promoteNext increments confirmation_attempts by 1, so we set it to MAX
        $promoted->update([
            'confirmation_attempts' => WaitlistService::MAX_CONFIRMATION_EXPIRATIONS,
        ]);

        $this->service->handleExpiredConfirmation($promoted);

        $refreshed = $promoted->fresh();
        expect($refreshed->status)->toBe(ParticipantStatus::Rejected);
        expect($refreshed->confirmation_expires_at)->toBeNull();

        // user2 should now be promoted
        $user2Participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user2->id)
            ->first();
        expect($user2Participant->status)->toBe(ParticipantStatus::Pending);
    });
});

// ── 7. Campaign cancellation rejects all waitlisted participants ──

describe('handleCampaignCancellation', function () {
    it('rejects all waitlisted campaign participants on cancellation', function () {
        ['campaign' => $campaign] = createFullCampaign();

        $waitUser = User::factory()->create();

        $this->service->addToWaitlist($campaign, $waitUser);

        $this->service->handleCampaignCancellation($campaign);

        $waitlisted = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $waitUser->id)->first();

        expect($waitlisted->status)->toBe(ParticipantStatus::Rejected);
    });

    it('does not reject benched participants (BenchService responsibility)', function () {
        ['campaign' => $campaign] = createFullCampaign();

        $benchUser = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $benchUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
        ]);

        $this->service->handleCampaignCancellation($campaign);

        // WaitlistService only handles waitlisted — benched should remain unchanged
        $benched = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $benchUser->id)->first();

        expect($benched->status)->toBe(ParticipantStatus::Benched);
    });

    it('does not affect approved participants on cancellation', function () {
        ['campaign' => $campaign, 'owner' => $owner] = createFullCampaign();

        $waitUser = User::factory()->create();
        $this->service->addToWaitlist($campaign, $waitUser);

        $this->service->handleCampaignCancellation($campaign);

        $ownerParticipant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $owner->id)->first();
        expect($ownerParticipant->status)->toBe(ParticipantStatus::Approved);
    });
});
