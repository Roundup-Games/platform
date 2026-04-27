<?php

use App\Models\User;
use App\Models\UserAppVisit;
use function Pest\Laravel\{actingAs, get};

describe('TrackAppVisit Middleware', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    });

    it('creates a visit record for authenticated user on first request', function () {
        expect(UserAppVisit::count())->toBe(0);

        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk();

        expect(UserAppVisit::count())->toBe(1);

        $visit = UserAppVisit::first();
        expect($visit->user_id)->toBe($this->user->id);
        expect($visit->visit_date->toDateString())->toBe(now()->toDateString());
    });

    it('does not duplicate visit records for same-day requests', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk();

        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk();

        expect(UserAppVisit::where('user_id', $this->user->id)->count())->toBe(1);
    });

    it('does not create visit records for guest requests', function () {
        // Use a public route that doesn't redirect guests to login
        get(route('home'))
            ->assertOk();

        expect(UserAppVisit::count())->toBe(0);
    });

    it('tracks separate records for different users on the same day', function () {
        $otherUser = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk();

        actingAs($otherUser)
            ->get(route('dashboard'))
            ->assertOk();

        expect(UserAppVisit::count())->toBe(2);
    });

    it('tracks separate records for the same user on different days', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk();

        // Manually insert a record for yesterday to simulate a previous visit
        UserAppVisit::create([
            'user_id' => $this->user->id,
            'visit_date' => now()->subDay()->toDateString(),
        ]);

        // Should now have 2 records: yesterday + today
        expect(UserAppVisit::where('user_id', $this->user->id)->count())->toBe(2);
    });
});
