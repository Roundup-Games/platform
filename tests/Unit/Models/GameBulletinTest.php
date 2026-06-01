<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameParticipant;
use App\Models\User;

beforeEach(function () {
    $this->host = User::factory()->create();
    $this->game = Game::factory()->create(['owner_id' => $this->host->id]);
});

// ── Model Basics ──────────────────────────────────────

it('can create a bulletin via factory', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);

    expect($bulletin)->toBeInstanceOf(GameBulletin::class)
        ->and($bulletin->id)->not->toBeEmpty()
        ->and($bulletin->game_id)->toBe($this->game->id)
        ->and($bulletin->user_id)->toBe($this->host->id)
        ->and($bulletin->content)->not->toBeEmpty();
});

it('auto-generates UUID on creation', function () {
    $bulletin = GameBulletin::create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'content' => 'Test bulletin',
    ]);

    expect($bulletin->id)->toMatch('/^[0-9a-f-]{36}$/');
});

it('casts expires_at to datetime', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'expires_at' => now()->addHour(),
    ]);

    expect($bulletin->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('limits content to 280 characters via db column', function () {
    $this->expectException(\Illuminate\Database\QueryException::class);

    GameBulletin::create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'content' => str_repeat('x', 281),
    ]);
});

// ── Relationships ─────────────────────────────────────

it('belongs to a game', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);

    expect($bulletin->game)->toBeInstanceOf(Game::class)
        ->and($bulletin->game->id)->toBe($this->game->id);
});

it('belongs to a user (author)', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);

    expect($bulletin->user)->toBeInstanceOf(User::class)
        ->and($bulletin->user->id)->toBe($this->host->id);
});

it('is accessible via game bulletins relationship', function () {
    GameBulletin::factory()->count(3)->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);

    expect($this->game->fresh()->bulletins)->toHaveCount(3);
});

// ── Scopes ────────────────────────────────────────────

it('scopeNotExpired includes bulletins without expires_at', function () {
    GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'expires_at' => null,
    ]);

    $results = GameBulletin::notExpired()->get();
    expect($results)->toHaveCount(1);
});

it('scopeNotExpired includes bulletins with future expires_at', function () {
    GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'expires_at' => now()->addHour(),
    ]);

    $results = GameBulletin::notExpired()->get();
    expect($results)->toHaveCount(1);
});

it('scopeNotExpired excludes expired bulletins', function () {
    GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'expires_at' => now()->subHour(),
    ]);

    $results = GameBulletin::notExpired()->get();
    expect($results)->toHaveCount(0);
});

// ── Accessor ──────────────────────────────────────────

it('is_expired returns false when expires_at is null', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'expires_at' => null,
    ]);

    expect($bulletin->is_expired)->toBeFalse();
});

it('is_expired returns false when expires_at is in the future', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'expires_at' => now()->addHour(),
    ]);

    expect($bulletin->is_expired)->toBeFalse();
});

it('is_expired returns true when expires_at is in the past', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
        'expires_at' => now()->subHour(),
    ]);

    expect($bulletin->is_expired)->toBeTrue();
});

// ── Policy ────────────────────────────────────────────

it('allows host to create bulletin', function () {
    $policy = new \App\Policies\GameBulletinPolicy;

    expect($policy->create($this->host, $this->game))->toBeTrue();
});

it('denies non-host to create bulletin', function () {
    $otherUser = User::factory()->create();
    $policy = new \App\Policies\GameBulletinPolicy;

    expect($policy->create($otherUser, $this->game))->toBeFalse();
});

it('allows host to view bulletin', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);
    $policy = new \App\Policies\GameBulletinPolicy;

    expect($policy->view($this->host, $bulletin))->toBeTrue();
});

it('allows approved participant to view bulletin', function () {
    $participant = User::factory()->create();
    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $participant->id,
        'status' => ParticipantStatus::Approved,
    ]);

    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);
    $policy = new \App\Policies\GameBulletinPolicy;

    expect($policy->view($participant, $bulletin))->toBeTrue();
});

it('denies non-approved user to view bulletin', function () {
    $stranger = User::factory()->create();

    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);
    $policy = new \App\Policies\GameBulletinPolicy;

    expect($policy->view($stranger, $bulletin))->toBeFalse();
});

it('allows host to delete bulletin', function () {
    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);
    $policy = new \App\Policies\GameBulletinPolicy;

    expect($policy->delete($this->host, $bulletin))->toBeTrue();
});

it('denies non-host to delete bulletin', function () {
    $participant = User::factory()->create();
    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $participant->id,
        'status' => ParticipantStatus::Approved,
    ]);

    $bulletin = GameBulletin::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->host->id,
    ]);
    $policy = new \App\Policies\GameBulletinPolicy;

    expect($policy->delete($participant, $bulletin))->toBeFalse();
});
