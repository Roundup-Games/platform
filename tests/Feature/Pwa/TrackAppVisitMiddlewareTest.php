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

    // ── Request filtering ──────────────────────────

    it('skips POST requests', function () {
        $countBefore = UserAppVisit::count();

        // Use a POST to a known route — the response code doesn't matter,
        // we just need to verify no visit record was created
        actingAs($this->user)
            ->post('/en/logout');

        // POST should not create a visit record
        expect(UserAppVisit::count())->toBe($countBefore);
    });

    it('skips requests to api paths', function () {
        $countBefore = UserAppVisit::count();

        actingAs($this->user)
            ->getJson('/api/geocode?q=Berlin');

        expect(UserAppVisit::count())->toBe($countBefore);
    });

    it('skips Livewire component update requests', function () {
        $countBefore = UserAppVisit::count();

        actingAs($this->user)
            ->withHeaders(['X-Livewire' => 'true'])
            ->get(route('dashboard'));

        expect(UserAppVisit::count())->toBe($countBefore);
    });

});
