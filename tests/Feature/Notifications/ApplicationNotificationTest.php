<?php

use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Livewire\Campaigns\ApplyToCampaign;
use App\Livewire\Games\ApplyToGame;
use App\Livewire\Games\ManageParticipants;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\NewApplication;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

/**
 * Helper: set up mutual friendship between two users.
 */
function makeFriendsApplication(User $a, User $b): void
{
    UserRelationship::create(['user_id' => $a->id, 'related_user_id' => $b->id, 'type' => RelationshipType::Follow]);
    UserRelationship::create(['user_id' => $b->id, 'related_user_id' => $a->id, 'type' => RelationshipType::Follow]);
}

// ══════════════════════════════════════════════════════
// New Application (Game)
// ══════════════════════════════════════════════════════

describe('Apply to protected game → NewApplication', function () {
    it('dispatches NewApplication to game owner for protected games', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        makeFriendsApplication($owner, $applicant);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($applicant)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Please let me join!')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $notifications = $owner->notifications()->where('type', NewApplication::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('new_application')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['applicant_id'])->toBe($applicant->id)
            ->and($data)->toHaveKey('action_url');
    })->group('smoke');

    it('does not dispatch NewApplication for public (auto-approved) games', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($applicant)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me in')
            ->call('submitApplication')
            ->assertHasNoErrors();

        expect($owner->notifications()->where('type', NewApplication::class)->count())->toBe(0);
    });

    it('does not dispatch when preferences are off', function () {
        $owner = User::factory()->create([
            'profile_complete' => true,
            'notification_settings' => array_merge(
                NotificationCategory::defaultSettings(),
                ['new_application' => ['database' => false, 'mail' => false]]
            ),
        ]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        makeFriendsApplication($owner, $applicant);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($applicant)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Join please')
            ->call('submitApplication')
            ->assertHasNoErrors();

        expect($owner->notifications()->where('type', NewApplication::class)->count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════
// New Application (Campaign)
// ══════════════════════════════════════════════════════

describe('Apply to protected campaign → NewApplication', function () {
    it('dispatches NewApplication to campaign owner for protected campaigns', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        makeFriendsApplication($owner, $applicant);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
            'status' => 'active',
        ]);

        Livewire::actingAs($applicant)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'I want to join this campaign')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $notifications = $owner->notifications()->where('type', NewApplication::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('new_application')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['applicant_id'])->toBe($applicant->id)
            ->and($data)->toHaveKey('action_url');
    });

    it('does not dispatch for public campaigns', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire::actingAs($applicant)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        expect($owner->notifications()->where('type', NewApplication::class)->count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════
// Application Approved
// ══════════════════════════════════════════════════════

describe('Approve application → ApplicationApproved', function () {
    it('dispatches ApplicationApproved to applicant', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create(['owner_id' => $owner->id]);

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', (string) $participant->id);

        $notifications = $applicant->notifications()->where('type', ApplicationApproved::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('application_approved')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['approver_id'])->toBe($owner->id)
            ->and($data)->toHaveKey('action_url');
    })->group('smoke');
});

// ══════════════════════════════════════════════════════
// Application Rejected
// ══════════════════════════════════════════════════════

describe('Reject application → ApplicationRejected', function () {
    it('dispatches ApplicationRejected to applicant', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create(['owner_id' => $owner->id]);

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('rejectApplication', (string) $participant->id);

        $notifications = $applicant->notifications()->where('type', ApplicationRejected::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('application_rejected')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['rejector_id'])->toBe($owner->id)
            ->and($data)->toHaveKey('action_url');
    })->group('smoke');
});
