<?php

use App\Models\User;
use App\Models\UserAppVisit;

beforeEach(function () {
    $this->user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
});

// ── Model Attributes ───────────────────────────────────

it('has correct fillable attributes', function () {
    $visit = UserAppVisit::create([
        'user_id' => $this->user->id,
        'visit_date' => '2026-04-27',
    ]);

    expect($visit->user_id)->toBe($this->user->id)
        ->and($visit->visit_date->toDateString())->toBe('2026-04-27');
});

it('casts visit_date to a Carbon date instance', function () {
    $visit = UserAppVisit::factory()->create([
        'user_id' => $this->user->id,
        'visit_date' => '2026-04-27',
    ]);

    $fresh = UserAppVisit::find($visit->id);
    expect($fresh->visit_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('has timestamps enabled', function () {
    $visit = UserAppVisit::factory()->create([
        'user_id' => $this->user->id,
    ]);

    expect($visit->created_at)->not->toBeNull()
        ->and($visit->updated_at)->not->toBeNull();
});

// ── Upsert Behavior ────────────────────────────────────

it('upsert creates a new row for a new day', function () {
    UserAppVisit::upsert(
        ['user_id' => $this->user->id, 'visit_date' => now()->toDateString()],
        ['user_id', 'visit_date'],
    );

    expect(UserAppVisit::where('user_id', $this->user->id)->count())->toBe(1);
});

it('upsert is idempotent for the same user and day', function () {
    $date = now()->toDateString();

    UserAppVisit::upsert(
        ['user_id' => $this->user->id, 'visit_date' => $date],
        ['user_id', 'visit_date'],
    );
    UserAppVisit::upsert(
        ['user_id' => $this->user->id, 'visit_date' => $date],
        ['user_id', 'visit_date'],
    );
    UserAppVisit::upsert(
        ['user_id' => $this->user->id, 'visit_date' => $date],
        ['user_id', 'visit_date'],
    );

    expect(UserAppVisit::where('user_id', $this->user->id)->count())->toBe(1);
});

it('allows different dates for the same user', function () {
    UserAppVisit::create([
        'user_id' => $this->user->id,
        'visit_date' => now()->subDays(2)->toDateString(),
    ]);
    UserAppVisit::create([
        'user_id' => $this->user->id,
        'visit_date' => now()->subDay()->toDateString(),
    ]);
    UserAppVisit::create([
        'user_id' => $this->user->id,
        'visit_date' => now()->toDateString(),
    ]);

    expect(UserAppVisit::where('user_id', $this->user->id)->count())->toBe(3);
});

// ── User Relationship ──────────────────────────────────

it('belongs to a user', function () {
    $visit = UserAppVisit::factory()->create([
        'user_id' => $this->user->id,
    ]);

    expect($visit->user)->toBeInstanceOf(User::class)
        ->and($visit->user->id)->toBe($this->user->id);
});

it('cascades on user delete', function () {
    UserAppVisit::factory()->create([
        'user_id' => $this->user->id,
    ]);

    expect(UserAppVisit::where('user_id', $this->user->id)->count())->toBe(1);

    $this->user->delete();

    expect(UserAppVisit::where('user_id', $this->user->id)->count())->toBe(0);
});

// ── Scopes ─────────────────────────────────────────────

it('scopeForUser filters by user id', function () {
    $otherUser = User::factory()->create();

    UserAppVisit::factory()->create([
        'user_id' => $this->user->id,
        'visit_date' => now()->toDateString(),
    ]);
    UserAppVisit::factory()->create([
        'user_id' => $otherUser->id,
        'visit_date' => now()->toDateString(),
    ]);

    $results = UserAppVisit::forUser($this->user->id)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->user_id)->toBe($this->user->id);
});

it('scopeSinceDate filters visits on or after the given date', function () {
    $april25 = '2026-04-25';
    $april26 = '2026-04-26';
    $april27 = '2026-04-27';

    UserAppVisit::create(['user_id' => $this->user->id, 'visit_date' => $april25]);
    UserAppVisit::create(['user_id' => $this->user->id, 'visit_date' => $april26]);
    UserAppVisit::create(['user_id' => $this->user->id, 'visit_date' => $april27]);

    $results = UserAppVisit::forUser($this->user->id)
        ->sinceDate($april26)
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results->pluck('visit_date')->map->toDateString()->toArray())
        ->toContain($april26, $april27);
});

it('factory creates a valid visit record', function () {
    $visit = UserAppVisit::factory()->create();

    expect($visit)->toBeInstanceOf(UserAppVisit::class)
        ->and($visit->user_id)->not->toBeNull()
        ->and($visit->visit_date)->not->toBeNull();
});
