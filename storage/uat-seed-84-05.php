<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$pw = Hash::make('UatPass84!x');
$mk = function (string $email, string $name) use ($pw): User {
    return User::firstOrCreate(['email' => $email], ['name' => $name, 'profile_complete' => true, 'password' => $pw]);
};
$H = $mk('uat-host-h@example.test', 'UAT Host H');
$X = $mk('uat-second-x@example.test', 'UAT Second X');
$P = $mk('uat-player-p@example.test', 'UAT Player P');
$P2 = $mk('uat-player-p2@example.test', 'UAT Player P2');

if (GameParticipant::where('user_id', $H->id)->count() === 0) {
    GameParticipant::factory()->count(4)->create(['user_id' => $H->id]);
}

$ensure = function (string $marker, array $attrs, array $players = []) use ($H): Game {
    $g = Game::query()->where('name->en', $marker)->first();
    if ($g) {
        return $g;
    }
    $g = Game::factory()->create(array_merge([
        'owner_id' => $H->id, 'campaign_id' => null, 'name' => ['en' => $marker],
        'min_players' => 2, 'max_players' => 3,
    ], $attrs));
    GameParticipant::create(['game_id' => $g->id, 'user_id' => $H->id, 'role' => ParticipantRole::Owner->value, 'status' => ParticipantStatus::Approved->value]);
    foreach ($players as $p) {
        GameParticipant::create(['game_id' => $g->id, 'user_id' => $p->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);
    }

    return $g;
};

$G = $ensure('UAT-G recreate target', ['status' => GameStatus::Scheduled->value, 'date_time' => now()->addHours(12)], [$P, $P2]);
$G2 = $ensure('UAT-G2 far out', ['status' => GameStatus::Scheduled->value, 'date_time' => now()->addHours(72)]);
$G3 = $ensure('UAT-G3 abandon case', ['status' => GameStatus::Scheduled->value, 'date_time' => now()->addHours(10)], [$P, $P2]);
$G4 = $ensure('UAT-G4 deutsch fall', ['status' => GameStatus::Scheduled->value, 'date_time' => now()->addHours(11)], [$P, $P2]);
$C = $ensure('UAT-C plain cancelled', ['status' => GameStatus::Canceled->value,  'date_time' => now()->addHours(6)], [$P]);

echo json_encode([
    'host' => $H->only(['id', 'email']),
    'reliability_before' => $H->fresh()->reliability_score,
    'G' => $G->id, 'G2' => $G2->id, 'G3' => $G3->id, 'G4' => $G4->id, 'C' => $C->id,
    'P' => $P->id, 'P2' => $P2->id, 'X' => $X->id,
    'roster_G' => $G->participants()->count(),
], JSON_PRETTY_PRINT), PHP_EOL;
