<?php

use App\Enums\AttendanceResolutionMethod;
use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;

uses(DatabaseTransactions::class);

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Create a completed game with host + players and an open attendance window.
 */
function createCompletedGameWithAttendanceWindow(int $totalParticipants = 3): array
{
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();

    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subHours(3),
        'attendance_window_opens_at' => now()->subHour(),
        'attendance_window_closes_at' => now()->addHours(72),
    ]);

    // Host participant
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $players = [];
    for ($i = 1; $i < $totalParticipants; $i++) {
        $user = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $players[] = $user;
    }

    return ['game' => $game, 'host' => $host, 'players' => $players];
}

// ═══════════════════════════════════════════════════════════════════════════
// State 1: Form — interactive attendance reporting
// ═══════════════════════════════════════════════════════════════════════════

describe('State 1: Form (window open, not submitted)', function () {
    it('shows submit attendance form when window is open', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('games.title_submit_attendance'))
            ->assertSee(__('games.action_submit_attendance_report'));
    });

    it('excludes self from reportable participants list', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $component = Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id]);

        // The attendanceReports should not include the viewer's own participant
        $reports = $component->get('attendanceReports');
        $viewerParticipant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);

        expect($reports)->not->toHaveKey($viewerParticipant->id);
        // Should have the other player and host as reportable
        expect(count($reports))->toBe(2); // host + other player
    });

    it('defaults all participants to attended', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $component = Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id]);

        $reports = $component->get('attendanceReports');
        foreach ($reports as $participantId => $data) {
            expect($data['status'])->toBe(AttendanceStatus::Attended->value);
            expect($data['reason'])->toBeNull();
        }
    });

    it('host sees Excused option in form', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $host = $data['host'];

        Livewire::actingAs($host)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('attendance.status_excused'));
    });

    it('non-host player does not see Excused option', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $response = Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertDontSee(__('attendance.status_excused'));
    });

    it('shows time remaining for open attendance window', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $component = Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id]);

        $timeRemaining = $component->get('attendanceTimeRemaining');
        expect($timeRemaining)->not->toBeNull();
        // Should contain time units (e.g., "2d 23h", "71h", "45m", "1h 30m")
        expect($timeRemaining)->toMatch('/\d+[dhms]/');
    });

    it('shows pre-game statuses as non-interactive', function () {
        $host = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $system->id,
            'status' => GameStatus::Completed->value,
            'date_time' => now()->subHours(3),
            'attendance_window_opens_at' => now()->subHour(),
            'attendance_window_closes_at' => now()->addHours(72),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $player = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'attendance_status' => AttendanceStatus::LateCancel->value,
        ]);

        $otherPlayer = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $otherPlayer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // When the viewer (otherPlayer) loads the form, the late-cancel player
        // should appear with opacity-60 class (non-interactive) and no interactive pills
        Livewire::actingAs($otherPlayer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee($player->name)
            ->assertSee(AttendanceStatus::LateCancel->label())
            ->assertSee('opacity-60');
    });

    it('submits attendance report successfully', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];
        $otherPlayer = $data['players'][1];
        $host = $data['host'];

        $otherParticipant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $otherPlayer->id);
        $hostParticipant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $host->id);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->set("attendanceReports.{$otherParticipant->id}.status", 'no_show')
            ->call('submitAttendanceReport')
            ->assertHasNoErrors();

        // Verify reports were created
        expect(AttendanceReport::where('game_id', $data['game']->id)
            ->where('reporter_id', $player->id)
            ->count())->toBe(2); // host + other player (both defaulted to attended except the one we changed)
    });

    it('validates status must be attended, no_show, or excused', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];
        $otherPlayer = $data['players'][1];

        $otherParticipant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $otherPlayer->id);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->set("attendanceReports.{$otherParticipant->id}.status", 'invalid_status')
            ->call('submitAttendanceReport')
            ->assertHasErrors(['attendanceReports.*.status']);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// State 2: Tallies — submitted, window open, not resolved
// ═══════════════════════════════════════════════════════════════════════════

describe('State 2: Tallies (submitted, not resolved)', function () {
    it('shows tallies view after submitting report', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        // Pre-file a report so hasSubmittedAttendance = true
        AttendanceReport::create([
            'game_id' => $data['game']->id,
            'reporter_id' => $player->id,
            'reported_id' => $data['players'][1]->id,
            'status' => AttendanceStatus::Attended->value,
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('games.title_attendance_submitted'))
            ->assertDontSee(__('games.action_submit_attendance_report'));
    });

    it('shows vote tallies per participant after reports exist', function () {
        $data = createCompletedGameWithAttendanceWindow(4);
        $player1 = $data['players'][0];
        $player2 = $data['players'][1];
        $player3 = $data['players'][2];

        // Player1 reports player2 as no_show
        AttendanceReport::create([
            'game_id' => $data['game']->id,
            'reporter_id' => $player1->id,
            'reported_id' => $player2->id,
            'status' => AttendanceStatus::NoShow->value,
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        // Player3 also reports player2 as no_show
        AttendanceReport::create([
            'game_id' => $data['game']->id,
            'reporter_id' => $player3->id,
            'reported_id' => $player2->id,
            'status' => AttendanceStatus::NoShow->value,
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        // Player1 reports player3 as attended
        AttendanceReport::create([
            'game_id' => $data['game']->id,
            'reporter_id' => $player1->id,
            'reported_id' => $player3->id,
            'status' => AttendanceStatus::Attended->value,
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        $component = Livewire::actingAs($player1)
            ->test(GameDetail::class, ['id' => $data['game']->id]);

        $tallies = $component->get('attendanceTallies');

        // Player2 should have 2 no_show reports
        expect($tallies[$player2->id][AttendanceStatus::NoShow->value])->toBe(2);
        // Player3 should have 1 attended report
        expect($tallies[$player3->id][AttendanceStatus::Attended->value])->toBe(1);
    });

    it('does not show submit button after reporting', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        AttendanceReport::create([
            'game_id' => $data['game']->id,
            'reporter_id' => $player->id,
            'reported_id' => $data['players'][1]->id,
            'status' => AttendanceStatus::Attended->value,
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertDontSee(__('games.action_submit_attendance_report'));
    });

    it('shows all reports submitted when no time remaining and all reported', function () {
        $data = createCompletedGameWithAttendanceWindow(2); // host + 1 player
        $player = $data['players'][0];

        AttendanceReport::create([
            'game_id' => $data['game']->id,
            'reporter_id' => $player->id,
            'reported_id' => $data['host']->id,
            'status' => AttendanceStatus::Attended->value,
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        $component = Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id]);

        // hasSubmittedAttendance should be true
        $hasSubmitted = $component->get('hasSubmittedAttendance');
        expect($hasSubmitted)->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// State 3: Resolved — window closed, resolution complete
// ═══════════════════════════════════════════════════════════════════════════

describe('State 3: Resolved (window closed, resolved)', function () {
    it('shows resolved header when attendance is resolved', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        // Mark game as resolved
        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(), // window closed
        ]);

        // Set the player's own attendance status
        $participant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);
        $participant->update(['attendance_status' => AttendanceStatus::Attended->value]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('games.title_attendance_resolved'));
    });

    it('shows resolution method', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(),
        ]);

        $participant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);
        $participant->update(['attendance_status' => AttendanceStatus::Attended->value]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('games.label_resolution_consensus'));
    });

    it('shows dispute button for NoShow users', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(),
        ]);

        // Mark player as NoShow
        $participant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);
        $participant->update(['attendance_status' => AttendanceStatus::NoShow->value]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('games.action_dispute_attendance'));
    });

    it('does not show dispute button for attended users', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(),
        ]);

        $participant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);
        $participant->update(['attendance_status' => AttendanceStatus::Attended->value]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertDontSee(__('games.action_dispute_attendance'));
    });

    it('disputeAttendance marks participant as disputed', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(),
        ]);

        $participant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);
        $participant->update(['attendance_status' => AttendanceStatus::NoShow->value]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->set('disputeReason', 'I was present at the game the entire time')
            ->call('disputeAttendance', $participant->id);

        $participant->refresh();
        expect($participant->attendance_disputed_at)->not->toBeNull();
    });

    it('does not allow dispute from unrelated user', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player1 = $data['players'][0];
        $player2 = $data['players'][1];

        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(),
        ]);

        $participant2 = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player2->id);
        $participant2->update(['attendance_status' => AttendanceStatus::NoShow->value]);

        // player1 tries to dispute player2's status (not allowed)
        Livewire::actingAs($player1)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->call('disputeAttendance', $participant2->id);

        $participant2->refresh();
        expect($participant2->attendance_disputed_at)->toBeNull();
    });

    it('does not show dispute button if already disputed', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(),
        ]);

        $participant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);
        $participant->update([
            'attendance_status' => AttendanceStatus::NoShow->value,
            'attendance_disputed_at' => now(),
        ]);

        // Should not see dispute button since already disputed
        $html = Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->html();

        // The dispute button text should not appear since already disputed
        $disputeText = __('games.action_dispute_attendance');
        // Count occurrences — should be 0 since this player's row should not show dispute
        expect(substr_count($html, $disputeText))->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Cross-state: window closed, not resolved → no attendance UI
// ═══════════════════════════════════════════════════════════════════════════

describe('Cross-state edge cases', function () {
    it('does not show attendance UI for non-completed games', function () {
        $host = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $system->id,
            'status' => GameStatus::Scheduled->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($host)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('games.title_submit_attendance'))
            ->assertDontSee(__('games.title_attendance_resolved'));
    });

    it('does not show attendance UI for non-participants', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $stranger = User::factory()->create(['profile_complete' => true]);

        Livewire::actingAs($stranger)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertDontSee(__('games.title_submit_attendance'))
            ->assertDontSee(__('games.title_attendance_resolved'));
    });

    it('shows all three resolution methods correctly', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $player = $data['players'][0];

        $participant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);

        // Test early_consensus
        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(),
        ]);
        $participant->update(['attendance_status' => AttendanceStatus::Attended->value]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('games.label_resolution_consensus'));

        // Test timeout
        $data['game']->update([
            'attendance_resolution_method' => AttendanceResolutionMethod::Timeout->value,
        ]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('games.label_resolution_timeout'));

        // Test manual
        $data['game']->update([
            'attendance_resolution_method' => AttendanceResolutionMethod::Manual->value,
        ]);

        Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->assertSee(__('games.label_resolution_host_override'));
    });

    it('host cannot dispute on behalf of a NoShow player in new system', function () {
        $data = createCompletedGameWithAttendanceWindow(3);
        $host = $data['host'];
        $player = $data['players'][0];

        $data['game']->update([
            'attendance_resolved_at' => now(),
            'attendance_resolution_method' => AttendanceResolutionMethod::EarlyConsensus->value,
            'attendance_window_closes_at' => now()->subHour(),
        ]);

        $participant = $data['game']->participants
            ->first(fn ($p) => $p->user_id === $player->id);
        $participant->update(['attendance_status' => AttendanceStatus::NoShow->value]);

        // Host attempts to dispute on behalf — should fail (only the affected participant can)
        Livewire::actingAs($host)
            ->test(GameDetail::class, ['id' => $data['game']->id])
            ->set('disputeReason', 'They were present and I can confirm their attendance')
            ->call('disputeAttendance', $participant->id);

        $participant->refresh();
        expect($participant->attendance_disputed_at)->toBeNull();
    });
});
