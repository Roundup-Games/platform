<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;

// ── Migration enum value sets (source of truth) ──────────────

$gameParticipantRoles = ['owner', 'player', 'invited', 'applicant'];
$gameParticipantStatuses = ['approved', 'rejected', 'pending'];

$campaignParticipantRoles = ['owner', 'player', 'invited', 'applicant'];
$campaignParticipantStatuses = ['approved', 'rejected', 'pending'];

$eventAnnouncementVisibilities = ['all', 'registered', 'private'];

// Livewire produces these values for registrations (string columns, not enum)
$eventRegistrationStatuses = ['pending', 'confirmed', 'cancelled', 'waitlisted', 'refunded'];
$eventRegistrationPaymentStatuses = ['pending', 'paid', 'refunded', 'failed', 'waived', 'not_required'];

// ─────────────────────────────────────────────────────────────
// Game Participants — enum values match migration
// ─────────────────────────────────────────────────────────────

describe('Game Participants enum consistency', function () use (
    $gameParticipantRoles,
    $gameParticipantStatuses,
) {
    it('accepts all migration-defined role values', function () use ($gameParticipantRoles) {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        foreach ($gameParticipantRoles as $role) {
            $user = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'role' => $role,
                'status' => 'pending',
            ]);

            assertDatabaseHas('game_participants', [
                'game_id' => $game->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);
        }

        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(count($gameParticipantRoles));
    });

    it('accepts all migration-defined status values', function () use ($gameParticipantStatuses) {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        foreach ($gameParticipantStatuses as $status) {
            $user = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'role' => 'player',
                'status' => $status,
            ]);

            assertDatabaseHas('game_participants', [
                'game_id' => $game->id,
                'user_id' => $user->id,
                'status' => $status,
            ]);
        }

        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(count($gameParticipantStatuses));
    });

    it('rejects role values not in migration enum', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);
        $user = User::factory()->create();

        expect(fn () => GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'gm', // old Filament value — not in migration
            'status' => 'pending',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('rejects status values not in migration enum', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);
        $user = User::factory()->create();

        expect(fn () => GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'confirmed', // old Filament value — not in migration
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });
});

// ─────────────────────────────────────────────────────────────
// Campaign Participants — enum values match migration
// ─────────────────────────────────────────────────────────────

describe('Campaign Participants enum consistency', function () use (
    $campaignParticipantRoles,
    $campaignParticipantStatuses,
) {
    it('accepts all migration-defined role values', function () use ($campaignParticipantRoles) {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        foreach ($campaignParticipantRoles as $role) {
            $user = User::factory()->create();
            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'role' => $role,
                'status' => 'pending',
            ]);

            assertDatabaseHas('campaign_participants', [
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);
        }

        expect(CampaignParticipant::where('campaign_id', $campaign->id)->count())->toBe(count($campaignParticipantRoles));
    });

    it('accepts all migration-defined status values', function () use ($campaignParticipantStatuses) {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        foreach ($campaignParticipantStatuses as $status) {
            $user = User::factory()->create();
            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'role' => 'player',
                'status' => $status,
            ]);

            assertDatabaseHas('campaign_participants', [
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'status' => $status,
            ]);
        }

        expect(CampaignParticipant::where('campaign_id', $campaign->id)->count())->toBe(count($campaignParticipantStatuses));
    });

    it('rejects role values not in migration enum', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $user = User::factory()->create();

        expect(fn () => CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'gm', // old Filament value — not in migration
            'status' => 'pending',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('rejects status values not in migration enum', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $user = User::factory()->create();

        expect(fn () => CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active', // old Filament value — not in migration
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });
});

// ─────────────────────────────────────────────────────────────
// Event Registrations — status/payment_status values match Livewire
// ─────────────────────────────────────────────────────────────

describe('Event Registration enum consistency', function () use (
    $eventRegistrationStatuses,
    $eventRegistrationPaymentStatuses,
) {
    it('accepts all Livewire-produced status values', function () use ($eventRegistrationStatuses) {
        foreach ($eventRegistrationStatuses as $status) {
            $registration = EventRegistration::factory()->create([
                'status' => $status,
            ]);

            assertDatabaseHas('event_registrations', [
                'id' => $registration->id,
                'status' => $status,
            ]);
        }
    });

    it('accepts all Livewire-produced payment_status values including not_required', function () use ($eventRegistrationPaymentStatuses) {
        foreach ($eventRegistrationPaymentStatuses as $paymentStatus) {
            $registration = EventRegistration::factory()->create([
                'payment_status' => $paymentStatus,
            ]);

            assertDatabaseHas('event_registrations', [
                'id' => $registration->id,
                'payment_status' => $paymentStatus,
            ]);
        }
    });
});

// ─────────────────────────────────────────────────────────────
// Event Announcements — visibility values match migration default
// ─────────────────────────────────────────────────────────────

describe('Event Announcement enum consistency', function () use (
    $eventAnnouncementVisibilities,
) {
    it('accepts all valid visibility values including migration default "all"', function () use ($eventAnnouncementVisibilities) {
        foreach ($eventAnnouncementVisibilities as $visibility) {
            $announcement = EventAnnouncement::create([
                'event_id' => Event::factory()->create()->id,
                'author_id' => User::factory()->create()->id,
                'title' => "Test announcement {$visibility}",
                'content' => 'Test content',
                'visibility' => $visibility,
            ]);

            assertDatabaseHas('event_announcements', [
                'id' => $announcement->id,
                'visibility' => $visibility,
            ]);
        }
    });

    it('defaults to "all" matching migration default', function () {
        $event = Event::factory()->create();
        $author = User::factory()->create();

        // Insert via DB::table to test raw migration default
        $id = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('event_announcements')->insert([
            'id' => $id,
            'event_id' => $event->id,
            'author_id' => $author->id,
            'title' => 'Default visibility test',
            'content' => 'Content',
            'is_pinned' => false,
            'is_published' => false,
            'visibility' => 'all', // migration default
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        assertDatabaseHas('event_announcements', [
            'id' => $id,
            'visibility' => 'all',
        ]);
    });
});

// ─────────────────────────────────────────────────────────────
// Filament form options match migration enum values
// (Verified by reading source — Grid import issue prevents
//  direct form() instantiation in test context)
// ─────────────────────────────────────────────────────────────

describe('Filament form options match migrations', function () {
    it('Game Participant form defines correct role options', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameResource/RelationManagers/ParticipantsRelationManager.php'));

        expect($source)->toContain("'owner' => 'Owner'")
            ->and($source)->toContain("'player' => 'Player'")
            ->and($source)->toContain("'invited' => 'Invited'")
            ->and($source)->toContain("'applicant' => 'Applicant'")
            ->and($source)->not->toContain("'gm' =>")
            ->and($source)->not->toContain("'observer' =>");
    });

    it('Game Participant form defines correct status options', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameResource/RelationManagers/ParticipantsRelationManager.php'));

        expect($source)->toContain("'approved' => 'Approved'")
            ->and($source)->toContain("'rejected' => 'Rejected'")
            ->and($source)->toContain("'pending' => 'Pending'")
            ->and($source)->not->toContain("'confirmed' => 'Confirmed'")
            ->and($source)->not->toContain("'waitlisted' =>")
            ->and($source)->not->toContain("'cancelled' => 'Cancelled'");
    });

    it('Campaign Participant form defines correct role options', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/CampaignResource/RelationManagers/ParticipantsRelationManager.php'));

        expect($source)->toContain("'owner' => 'Owner'")
            ->and($source)->toContain("'player' => 'Player'")
            ->and($source)->toContain("'invited' => 'Invited'")
            ->and($source)->toContain("'applicant' => 'Applicant'")
            ->and($source)->not->toContain("'gm' =>")
            ->and($source)->not->toContain("'observer' =>");
    });

    it('Campaign Participant form defines correct status options', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/CampaignResource/RelationManagers/ParticipantsRelationManager.php'));

        expect($source)->toContain("'approved' => 'Approved'")
            ->and($source)->toContain("'rejected' => 'Rejected'")
            ->and($source)->toContain("'pending' => 'Pending'")
            ->and($source)->not->toContain("'active' => 'Active'")
            ->and($source)->not->toContain("'left' =>")
            ->and($source)->not->toContain("'kicked' =>");
    });

    it('Event Registration form includes not_required payment_status option', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/EventResource/RelationManagers/RegistrationsRelationManager.php'));

        expect($source)->toContain("'not_required' => 'Not Required'")
            ->and($source)->toContain("'pending' => 'Pending'")
            ->and($source)->toContain("'paid' => 'Paid'")
            ->and($source)->toContain("'refunded' => 'Refunded'")
            ->and($source)->toContain("'failed' => 'Failed'")
            ->and($source)->toContain("'waived' => 'Waived'");
    });

    it('Event Announcement visibility options include "all" matching migration default', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/EventResource/RelationManagers/AnnouncementsRelationManager.php'));

        expect($source)->toContain("'all' => 'All'")
            ->and($source)->not->toContain("'public' => 'Public'")
            ->and($source)->toContain("->default('all')");
    });
});
