<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Livewire\Dashboard;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Cache::flush();
    Log::spy();
    Queue::fake();
    URL::defaults(['locale' => 'en']);

    // Ensure the Game Master role exists for tests
    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
});

// Helper: create an established user (has completed game participation)
function createEstablishedUser(): User
{
    $user = User::factory()->create(['profile_complete' => true]);
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();

    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subDays(10),
    ]);

    GameParticipant::factory()->create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return $user;
}

// ── Dashboard mode ─────────────────────────────────────

test('established user sees established mode', function () {
    $user = createEstablishedUser();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class);
    expect($component->viewData('dashboard')->mode)->toBe('established');
});

// ── Schedule timeline ──────────────────────────────────

test('schedule timeline shows grouped upcoming games', function () {
    // Freeze to Wednesday to ensure "2 days out" is always "this week"
    $this->travelTo(now()->startOfWeek()->addDays(2)->setHour(10));

    $user = createEstablishedUser();
    $system = GameSystem::factory()->create();
    $this->actingAs($user);

    // Game today (hosted) — 30 min from now
    Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $system->id,
        'date_time' => now()->addMinutes(30),
        'status' => GameStatus::Scheduled->value,
    ]);

    // Game this week (participated) — 2 days out is safely within this week
    $host = User::factory()->create(['profile_complete' => true]);
    $weekGame = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'date_time' => now()->addDays(2)->startOfDay()->addHours(10),
        'status' => GameStatus::Scheduled->value,
    ]);
    GameParticipant::factory()->create([
        'game_id' => $weekGame->id,
        'user_id' => $user->id,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Game coming up (next week)
    $nextWeekGame = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'date_time' => now()->addWeek()->addDays(2)->addHours(10),
        'status' => GameStatus::Scheduled->value,
    ]);
    GameParticipant::factory()->create([
        'game_id' => $nextWeekGame->id,
        'user_id' => $user->id,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $component = Livewire::test(Dashboard::class);

    $scheduleGroups = $component->viewData('dashboard')->established->scheduleGroups;
    expect($scheduleGroups['today'])->toHaveCount(1);
    expect($scheduleGroups['this_week'])->toHaveCount(1);
    expect($scheduleGroups['coming_up'])->toHaveCount(1);
});

// ── Host-again bridge ──────────────────────────────────

test('host-again bridge appears when no upcoming games and links to clone url', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $this->actingAs($user);

    // Make user established by participating in a completed game
    $host = User::factory()->create(['profile_complete' => true]);
    $participatedGame = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subDays(10),
    ]);
    GameParticipant::factory()->create([
        'game_id' => $participatedGame->id,
        'user_id' => $user->id,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Host a completed game (for the bridge)
    $completedGame = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subDays(3),
    ]);

    $component = Livewire::test(Dashboard::class);

    $bridge = $component->viewData('dashboard')->established->hostAgainBridge;
    expect($bridge)->not->toBeNull();
    expect($bridge['game']['id'])->toBe($completedGame->id);
    expect($bridge['clone_url'])->toContain('clone='.$completedGame->id);
});

// ── Nearby games with relevance tags ───────────────────

test('nearby games show with correct relevance tags', function () {
    $user = createEstablishedUser();
    $system = GameSystem::factory()->create();
    // Use a unique location to avoid collision with other tests
    $location = Location::factory()->create([
        'latitude' => 35.6762,
        'longitude' => 139.6503,
    ]);
    $user->update(['location_id' => $location->id]);
    $user->refresh();
    $this->actingAs($user);

    // Preferred system for matches_your_taste
    $preferredSystem = GameSystem::factory()->create();
    $user->gameSystemPreferences()->attach($preferredSystem->id, ['preference_type' => 'favorite']);

    $otherUser = User::factory()->create(['profile_complete' => true]);
    Game::factory()->create([
        'owner_id' => $otherUser->id,
        'game_system_id' => $preferredSystem->id,
        'location_id' => $location->id,
        'date_time' => now()->addDays(3),
        'status' => GameStatus::Scheduled->value,
        'visibility' => 'public',
        'max_players' => 6,
    ]);

    $component = Livewire::test(Dashboard::class);

    $nearby = $component->viewData('dashboard')->established->nearbyNoteworthy;
    expect($nearby)->not->toBeEmpty();
    expect($nearby[0]['relevance_tags'])->toContain('matches_your_taste');
});

// ── Community Pulse ────────────────────────────────────

test('community pulse absent when user has fewer than 3 follows', function () {
    $user = createEstablishedUser();
    $this->actingAs($user);

    // createEstablishedUser() creates a GameParticipant which now auto-follows
    // the host (S03′). The user therefore starts with 1 follow edge.
    // To stay below the community-pulse threshold (3), follow only 1 more
    // user explicitly — total 2, still under the threshold.
    $extra = User::factory()->create();
    UserRelationship::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'related_user_id' => $extra->id,
        'type' => RelationshipType::Follow->value,
    ]);

    $component = Livewire::test(Dashboard::class);
    expect($component->viewData('dashboard')->established->shouldShowCommunityPulse)->toBeFalse();
});

// ── Your Story / Milestone cards ───────────────────────

test('your story absent when user has fewer than 3 attended games', function () {
    $user = createEstablishedUser();
    $this->actingAs($user);

    // The helper creates 1 completed participation, which is not enough for any milestone
    $component = Livewire::test(Dashboard::class);

    $milestoneCards = $component->viewData('dashboard')->established->milestoneCards;
    expect($milestoneCards)->toBeEmpty();
});

test('your story shows earned milestone cards when user qualifies', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $this->actingAs($user);

    // Make user established: participate in 1 completed game
    $host = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subDays(10),
    ]);
    GameParticipant::factory()->create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Host 10 completed games (veteran_host milestone)
    for ($i = 0; $i < 10; $i++) {
        Game::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'date_time' => now()->subDays(30 - $i),
            'status' => GameStatus::Completed->value,
        ]);
    }

    $component = Livewire::test(Dashboard::class);

    $milestoneCards = $component->viewData('dashboard')->established->milestoneCards;
    expect($milestoneCards)->not->toBeEmpty();

    $keys = array_column($milestoneCards, 'key');
    expect($keys)->toContain('veteran_host');
});

// ── Quick Actions ──────────────────────────────────────

test('quick actions show role-appropriate buttons for player', function () {
    $user = createEstablishedUser();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class);

    $quickActions = $component->viewData('dashboard')->established->establishedQuickActions;
    expect($quickActions)->not->toBeEmpty();

    // Plain player: primary should be discover games
    expect($quickActions[0]['label'])->toBe('profile.dashboard_quick_discover');
    expect($quickActions[0]['style'])->toBe('primary');
});

test('quick actions show gm workspace for gm with upcoming games', function () {
    $user = createEstablishedUser();
    $system = GameSystem::factory()->create();
    $user->assignRole('Game Master');
    $this->actingAs($user);

    // Give them an upcoming game — 30 min from now to avoid crossing midnight
    Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $system->id,
        'date_time' => now()->addMinutes(30),
        'status' => GameStatus::Scheduled->value,
    ]);

    $component = Livewire::test(Dashboard::class);

    $quickActions = $component->viewData('dashboard')->established->establishedQuickActions;
    expect($quickActions[0]['label'])->toBe('profile.dashboard_quick_gm_workspace');
    expect($quickActions[0]['style'])->toBe('primary');
});

// ── No empty boxes ─────────────────────────────────────

test('sections without data pass empty arrays not null', function () {
    $user = createEstablishedUser();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class);

    // Schedule groups should be arrays
    $scheduleGroups = $component->viewData('dashboard')->established->scheduleGroups;
    expect($scheduleGroups)->toBeArray();
    expect($scheduleGroups['today'])->toBeArray();
    expect($scheduleGroups['this_week'])->toBeArray();
    expect($scheduleGroups['coming_up'])->toBeArray();

    // Nearby noteworthy should be array
    expect($component->viewData('dashboard')->established->nearbyNoteworthy)->toBeArray();

    // Milestone cards should be array
    expect($component->viewData('dashboard')->established->milestoneCards)->toBeArray();

    // Quick actions should be array
    expect($component->viewData('dashboard')->established->establishedQuickActions)->toBeArray();
});
