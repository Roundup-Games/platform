<?php

use App\Enums\AttendanceStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\AttendanceReported;
use App\Notifications\ConfirmationExpired;
use App\Notifications\DisputeResolved;
use App\Services\AttendanceService;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ══════════════════════════════════════════════════════
// ConfirmationExpired — WaitlistService::handleExpiredConfirmation
// ══════════════════════════════════════════════════════

describe('ConfirmationExpired notification', function () {
    it('dispatches when confirmation expires', function () {
        Notification::fake();

        $service = app(WaitlistService::class);
        ['game' => $game] = notificationCreateFullStandaloneGame(maxPlayers: 2);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $service->addToWaitlist($game, $user2);

        // Open a slot and promote user1
        $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $service->promoteNext($game);
        expect($promoted->user_id)->toBe($user1->id);

        // Handle expiration
        $service->handleExpiredConfirmation($promoted);

        // user1 should receive ConfirmationExpired notification
        Notification::assertSentTo(
            $user1,
            ConfirmationExpired::class,
            function ($notification) use ($game) {
                return $notification->entity->id === $game->id;
            }
        );
    });

});

// ══════════════════════════════════════════════════════
// AttendanceReported — AttendanceService::reportAttendance
// ══════════════════════════════════════════════════════

describe('AttendanceReported notification', function () {
    it('dispatches to reported user when attendance is reported', function () {
        Notification::fake();

        [$game, $host, $reporter, $reported] = notificationCreatePastGameWithParticipants(2);

        $service = app(AttendanceService::class);
        $result = $service->reportAttendance($game, $reporter, $reported, 'attended');
        expect($result['success'])->toBeTrue();

        // The reported user should receive the notification
        Notification::assertSentTo(
            $reported,
            AttendanceReported::class,
            function ($notification) use ($game) {
                return $notification->game->id === $game->id;
            }
        );
    });

    it('does not dispatch to the reporter', function () {
        Notification::fake();

        [$game, $host, $reporter, $reported] = notificationCreatePastGameWithParticipants(2);

        $service = app(AttendanceService::class);
        $service->reportAttendance($game, $reporter, $reported, 'attended');

        // Reporter should NOT receive an AttendanceReported notification
        Notification::assertNotSentTo($reporter, AttendanceReported::class);
    });
});

// ══════════════════════════════════════════════════════
// DisputeResolved — AttendanceService::resolveDispute
// ══════════════════════════════════════════════════════

describe('DisputeResolved notification', function () {
    it('dispatches when dispute is resolved in player favor', function () {
        Notification::fake();

        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = notificationCreateDisputeGameWithParticipants(5);
        $reported = $participants[4];
        $service = app(AttendanceService::class);

        // Report no_show, then corroborating attended reports
        $service->reportAttendance($game, $participants[1], $reported, 'no_show');
        $service->reportAttendance($game, $participants[2], $reported, 'attended');
        $service->reportAttendance($game, $participants[3], $reported, 'attended');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $resolution = $service->resolveDispute($participant);
        expect($resolution)->toBe('resolved_favor');

        Notification::assertSentTo(
            $reported,
            DisputeResolved::class,
            function ($notification) use ($game) {
                return $notification->game->id === $game->id
                    && $notification->resolution === 'resolved_favor';
            }
        );
    });

    it('dispatches when dispute is upheld', function () {
        Notification::fake();

        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = notificationCreateDisputeGameWithParticipants(3);
        $reported = $participants[2];
        $service = app(AttendanceService::class);

        $service->reportAttendance($game, $participants[1], $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $resolution = $service->resolveDispute($participant);
        expect($resolution)->toBe('upheld');

        Notification::assertSentTo(
            $reported,
            DisputeResolved::class,
            function ($notification) use ($game) {
                return $notification->game->id === $game->id
                    && $notification->resolution === 'upheld';
            }
        );
    });
});

// ── Test helpers ─────────────────────────────────────────

function notificationCreateFullStandaloneGame(int $maxPlayers = 3, array $overrides = []): array
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

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return ['owner' => $owner, 'game' => $game];
}

function notificationCreatePastGameWithParticipants(int $extraPlayers = 0): array
{
    $host = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'date_time' => now()->subDays(1),
        'status' => 'completed',
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $players = [];
    for ($i = 0; $i < $extraPlayers; $i++) {
        $player = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        $players[] = $player;
    }

    return [$game, $host, ...$players];
}

function notificationCreateDisputeGameWithParticipants(int $participantCount = 3, array $gameOverrides = []): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'status' => 'completed',
        'date_time' => now()->subHours(2),
        ...$gameOverrides,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $participants = collect([$owner]);

    for ($i = 1; $i < $participantCount; $i++) {
        $user = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        $participants->push($user);
    }

    return ['owner' => $owner, 'game' => $game, 'participants' => $participants];
}
