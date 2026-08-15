<?php

use App\Models\User;
use App\Models\UserAppVisit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

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
            ->getJson('/api/v1/geocode?q=Berlin');

        expect(UserAppVisit::count())->toBe($countBefore);
    });

    it('skips Livewire component update requests', function () {
        $countBefore = UserAppVisit::count();

        actingAs($this->user)
            ->withHeaders(['X-Livewire' => 'true'])
            ->get(route('dashboard'));

        expect(UserAppVisit::count())->toBe($countBefore);
    });

    // ── Failure path ────────────────────────────────────────────────

    it('releases the daily cache gate when the upsert fails so a later request retries', function () {
        $today = now()->toDateString();
        $cacheKey = "pwa:visit:{$this->user->id}:{$today}";

        // Simulate a transient DB failure: a CHECK constraint that rejects
        // today's row forces a QueryException inside the upsert (same pattern
        // as ResendWebhookTest's varchar-overflow). The constraint and the
        // aborted statement roll back with the test's transaction; the array
        // cache is unaffected, so the gate-release assertion below is exact.
        DB::statement("ALTER TABLE user_app_visits ADD CONSTRAINT tmp_fail_visit CHECK (visit_date < '1900-01-01')");

        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertStatus(500);

        // The gate was released — the day's visit isn't silently lost to a
        // transient failure; the next request will retry the upsert.
        expect(Cache::has($cacheKey))->toBeFalse();
    });

});
