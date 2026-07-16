<?php

use App\Enums\ActivityType;
use App\Enums\AttendanceStatus;
use App\Jobs\EnrichPostHogProfile;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\PostHogClient;
use Illuminate\Support\Facades\Config;
use Tests\Helpers\TestablePostHogClient;

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');

    $this->posthogClient = new TestablePostHogClient;
    $this->app->instance(PostHogClient::class, $this->posthogClient);
});

// Helper: create a resolved participation for a user in a game with a system.
function createAttendedGame(User $user, GameSystem $system, array $gameAttrs = [], AttendanceStatus $status = AttendanceStatus::Attended): GameParticipant
{
    $game = Game::factory()->create(array_merge(['owner_id' => $user->id], $gameAttrs));
    $game->gameSystems()->attach($system->id);

    return GameParticipant::factory()->create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'attendance_status' => $status,
    ]);
}

describe('EnrichPostHogProfile — computed decision-grade properties', function () {
    it('sets modality based on participation history', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        // 3 online attendances → modality 'online'
        createAttendedGame($user, $system, ['location' => ['type' => 'online']]);
        createAttendedGame($user, $system, ['location' => ['type' => 'online']]);
        createAttendedGame($user, $system, ['location' => ['type' => 'online']]);

        EnrichPostHogProfile::dispatchSync(
            ActivityType::PlayerJoined->value,
            (string) $user->id,
            null,
            null,
            true,
        );

        $identify = collect($this->posthogClient->identifyCalls)->last();
        $set = $identify['properties']['$set'] ?? [];

        expect($set['modality'])->toBe('online');
    });

    it('classifies mixed modality when both online and in-person', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        createAttendedGame($user, $system, ['location' => ['type' => 'online']]);
        createAttendedGame($user, $system, ['location' => ['type' => 'venue']]);
        createAttendedGame($user, $system, ['location' => ['type' => 'venue']]);
        createAttendedGame($user, $system, ['location' => ['type' => 'venue']]);

        EnrichPostHogProfile::dispatchSync(
            ActivityType::PlayerJoined->value,
            (string) $user->id,
            null,
            null,
            true,
        );

        $set = collect($this->posthogClient->identifyCalls)->last()['properties']['$set'] ?? [];
        // 1 online / 4 total = 0.25 → in_person
        expect($set['modality'])->toBe('in_person');
    });

    it('leaves modality null when fewer than 3 attendances', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        createAttendedGame($user, $system, ['location' => ['type' => 'online']]);

        EnrichPostHogProfile::dispatchSync(
            ActivityType::PlayerJoined->value,
            (string) $user->id,
            null,
            null,
            true,
        );

        $set = collect($this->posthogClient->identifyCalls)->last()['properties']['$set'] ?? [];
        expect($set)->not->toHaveKey('modality');
    });

    it('sets primary_game_system as the most-attended system', function () {
        $user = User::factory()->create();
        $dnd = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $pathfinder = GameSystem::factory()->create(['name' => 'Pathfinder']);

        createAttendedGame($user, $dnd);
        createAttendedGame($user, $dnd);
        createAttendedGame($user, $pathfinder);

        EnrichPostHogProfile::dispatchSync(
            ActivityType::PlayerJoined->value,
            (string) $user->id,
            null,
            null,
            true,
        );

        $set = collect($this->posthogClient->identifyCalls)->last()['properties']['$set'] ?? [];
        expect($set['primary_game_system'])->toBe('D&D 5e');
    });

    it('sets reliability_tier from the cached reliability_score column', function () {
        $user = User::factory()->create([
            'reliability_score' => ['score' => 92.0, 'game_count' => 10, 'tier' => 'reliable'],
        ]);

        EnrichPostHogProfile::dispatchSync(
            ActivityType::PlayerJoined->value,
            (string) $user->id,
            null,
            null,
            true,
        );

        $set = collect($this->posthogClient->identifyCalls)->last()['properties']['$set'] ?? [];
        expect($set['reliability_tier'])->toBe('reliable');
    });

    it('still sets the legacy games_joined_count alongside computed properties', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();
        createAttendedGame($user, $system);
        createAttendedGame($user, $system);

        EnrichPostHogProfile::dispatchSync(
            ActivityType::PlayerJoined->value,
            (string) $user->id,
            null,
            null,
            true,
        );

        $set = collect($this->posthogClient->identifyCalls)->last()['properties']['$set'] ?? [];
        expect($set['games_joined_count'])->toBe(2);
    });
});

describe('EnrichPostHogProfile — consent gating', function () {
    it('skips enrichment when hasConsent is false', function () {
        $user = User::factory()->create();

        EnrichPostHogProfile::dispatchSync(
            ActivityType::PlayerJoined->value,
            (string) $user->id,
            null,
            null,
            false, // no consent
        );

        expect($this->posthogClient->identifyCalls)->toHaveCount(0);
    });
});
