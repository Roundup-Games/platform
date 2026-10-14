<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\GM\GmWorkspace;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\ShortLink;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

// Safety net for the de-locale regression test below: never let a pinned
// "now" leak into other tests if an assertion fails mid-test.
afterEach(function () {
    Carbon::setTestNow();
});

// ═══════════════════════════════════════════════════════════
// ACCESS CONTROL
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Access Control', function () {
    it('redirects unauthenticated users', function () {
        $this->get(route('gm.workspace', 'en'))
            ->assertRedirect(route('login', 'en'));
    });

    it('redirects non-GM users to dashboard', function () {
        $user = $this->createSubscribedNonGm();

        $this->actingAs($user)
            ->get(route('gm.workspace', 'en'))
            ->assertRedirect(route('dashboard', 'en'));
    });

    it('redirects GMs without subscription to dashboard', function () {
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $user->assignRole('Game Master');
        GMProfile::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        // No subscription created → subscribed() returns false
        $this->actingAs($user)
            ->get(route('gm.workspace', 'en'))
            ->assertRedirect(route('dashboard', 'en'));
    });

    // smoke: subscription gate — active subscribed GMs can view workspace
    it('allows active subscribed GMs to view workspace', function () {
        $gm = $this->createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee(__('gws.title_gm_workspace', [], 'en'));
    })->group('smoke');

    // smoke: de-locale regression (M066/S10) — /de/gm-workspace must render
    // German dates, never English AM/PM formats. Pins the full-page render
    // after S02's unexplained browser 500 required live triage.
    it('renders the workspace in German locale with German dates', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));

        $gm = $this->createSubscribedGm();
        Game::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'scheduled',
            'date_time' => Carbon::parse('2026-10-15 14:30:00'),
            'name' => ['en' => 'German Date Session'],
        ]);

        $response = $this->actingAs($gm)->get(route('gm.workspace', 'de'));

        $response->assertOk()
            ->assertSee('15. Oktober 2026, 14:30')
            ->assertDontSee('Oct 15, 2026');

        // No English AM/PM clock anywhere on the page. Word-boundary regex so
        // random ids / CSRF tokens can't cause false positives.
        expect($response->getContent())
            ->not->toMatch('/\b(?:AM|PM)\b/');
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// UPCOMING SESSIONS
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Upcoming Sessions', function () {
    it('shows upcoming scheduled sessions within 7 days', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'name' => ['en' => 'Dragon Lair Assault'],
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Dragon Lair Assault');
    });

    it('hides sessions beyond 7 days', function () {
        $gm = $this->createSubscribedGm();
        Game::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(10),
            'name' => ['en' => 'Far Future Session'],
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSee('Far Future Session');
    });

    it('hides non-scheduled sessions', function () {
        $gm = $this->createSubscribedGm();
        Game::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'completed',
            'date_time' => now()->addDays(2),
            'name' => ['en' => 'Completed Session'],
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSee('Completed Session');
    });

    it('hides other GMs sessions', function () {
        $gm = $this->createSubscribedGm();
        $otherGm = $this->createSubscribedGm(['name' => 'Other GM']);

        Game::factory()->create([
            'owner_id' => $otherGm->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(2),
            'name' => ['en' => 'Other GMs Session'],
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSee('Other GMs Session');
    });

});

// ═══════════════════════════════════════════════════════════
// REVIEW SUMMARY
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Review Summary', function () {
    it('shows average rating and review count', function () {
        $gm = $this->createSubscribedGm([], [
            'average_rating' => 4.75,
            'review_count' => 12,
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            // Rating is format-pinned (number_format 4.75 → 4.8); the count
            // is asserted via its pluralized label, not a bare numeral that
            // could match anywhere on the page.
            ->assertSee('4.8')
            ->assertSeeText(trans_choice('gws.label_reviews', 12, [], 'en'));
    });

    it('shows at most the 5 most recent reviews', function () {
        $gm = $this->createSubscribedGm();
        $gm->gmProfile->update(['review_count' => 6]);

        $game = Game::factory()->create(['owner_id' => $gm->id]);

        // Seed 6 reviews with explicit timestamps: body 6 is newest, body 1
        // the oldest — the workspace must render only the 5 newest.
        for ($i = 1; $i <= 6; $i++) {
            Review::factory()->create([
                'reviewable_type' => (new Game)->getMorphClass(),
                'reviewable_id' => $game->id,
                'gm_profile_id' => $gm->gmProfile->id,
                'reviewer_id' => User::factory()->create()->id,
                'rating' => 5,
                'body' => "Capsule review {$i}",
                'status' => 'published',
                'created_at' => now()->subDays(7 - $i),
            ]);
        }

        $response = $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'));

        $response->assertOk();

        // Component data goes through Livewire::test (full-page GET responses
        // do not expose Livewire view data to assertViewHas).
        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('recentReviews', fn ($reviews) => $reviews->count() === 5)
            ->assertSee('Capsule review 6')
            ->assertSee('Capsule review 2')
            ->assertDontSee('Capsule review 1');
    });

    it('shows last 5 reviews with star ratings', function () {
        $gm = $this->createSubscribedGm();
        $gm->gmProfile->update(['review_count' => 5]);

        $reviewer = User::factory()->create(['name' => 'Happy Player']);

        Review::factory()->create([
            'reviewable_type' => (new Game)->getMorphClass(),
            'reviewable_id' => Game::factory()->create(['owner_id' => $gm->id])->id,
            'gm_profile_id' => $gm->gmProfile->id,
            'reviewer_id' => $reviewer->id,
            'rating' => 5,
            'body' => 'Amazing session!',
            'status' => 'published',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Happy Player')
            ->assertSee('Amazing session!');
    });

    it('shows review proficiency tags', function () {
        $gm = $this->createSubscribedGm();
        $gm->gmProfile->update(['review_count' => 1]);

        Review::factory()->create([
            'reviewable_type' => (new Game)->getMorphClass(),
            'reviewable_id' => Game::factory()->create(['owner_id' => $gm->id])->id,
            'gm_profile_id' => $gm->gmProfile->id,
            'reviewer_id' => User::factory()->create()->id,
            'rating' => 4,
            'proficiency_tags' => ['storytelling', 'rule-of-cool'],
            'status' => 'published',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee(__('profile.gm_proficiency_storytelling', [], 'en'));
    });

});

// ═══════════════════════════════════════════════════════════
// PARTICIPANT STATS
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Participant Stats', function () {
    it('shows unique player count', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create(['owner_id' => $gm->id]);

        $players = User::factory()->count(3)->create();
        foreach ($players as $player) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        // Verify via the component data — the unique-players stat is a
        // precise value, not a page-wide substring match. Component data
        // goes through Livewire::test (the full-page GET does not expose
        // Livewire view data to assertViewHas).
        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('totalUniquePlayers', 3);
    });

    it('counts repeat players appearing in 2+ games', function () {
        $gm = $this->createSubscribedGm();
        $game1 = Game::factory()->create(['owner_id' => $gm->id]);
        $game2 = Game::factory()->create(['owner_id' => $gm->id]);

        $repeatPlayer = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game1->id,
            'user_id' => $repeatPlayer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game2->id,
            'user_id' => $repeatPlayer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $oneTimePlayer = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game1->id,
            'user_id' => $oneTimePlayer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk();

        // The response should contain 1 repeat player and 2 unique players
        // We check the component data via Livewire test
        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('totalUniquePlayers', 2)
            ->assertViewHas('repeatPlayers', 1);
    });

    it('shows zero stats when GM has no games', function () {
        $gm = $this->createSubscribedGm();

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('totalUniquePlayers', 0)
            ->assertViewHas('repeatPlayers', 0)
            ->assertViewHas('totalGames', 0);
    });

    it('counts total games correctly', function () {
        $gm = $this->createSubscribedGm();
        Game::factory()->count(5)->create(['owner_id' => $gm->id]);

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('totalGames', 5);
    });

    it('counts active campaigns correctly', function () {
        $gm = $this->createSubscribedGm();
        Campaign::factory()->count(3)->create([
            'owner_id' => $gm->id,
            'status' => 'active',
        ]);
        Campaign::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'completed',
        ]);

        Livewire\Livewire::actingAs($gm)
            ->test(GmWorkspace::class)
            ->assertViewHas('activeCampaigns', 3);
    });
});

// ═══════════════════════════════════════════════════════
// SHARE LINK ANALYTICS (CACHED)
// ═══════════════════════════════════════════════════════

describe('GmWorkspace Share Link Analytics', function () {
    // smoke: M066/S10 regression — the cached analytics payload must carry
    // only scalars/arrays. v1 cached Collection objects that returned from
    // the cache round-trip as __PHP_Incomplete_Class on the dev stack,
    // crashing the warm render with a hard 500 (the M066/S02 /de failure:
    // htmlspecialchars(): array given in gm-workspace.blade.php).
    it('renders the workspace cold and warm with links and a scalar-only cache payload', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'name' => ['en' => 'Linked Session'],
        ]);
        $link = ShortLink::create([
            'user_id' => $gm->id,
            'code' => 'gws-warm',
            'url' => 'https://example.com/linked',
            'linkable_type' => 'game',
            'linkable_id' => $game->id,
            'label' => 'Friends',
            'purpose' => 'manual',
        ]);

        // Cold render (cache miss, fresh compute) then warm render
        // (cache hit — previously served the broken Collection payload).
        foreach (range(1, 2) as $render) {
            $this->actingAs($gm)
                ->get(route('gm.workspace', 'de'))
                ->assertOk()
                ->assertSee('Linked Session')
                // The top-links row renders the raw user-set label verbatim
                // (labels are stored data, never translated — unlike DE
                // 'Freunde', 'Friends' here is data, not a translation pin).
                ->assertSee('Friends');
        }

        $payload = Cache::get(GmWorkspace::LINK_ANALYTICS_CACHE_KEY.":{$gm->id}");

        expect($payload)->toBeArray()
            ->and($payload[0]['linksByType'])->toBe(['Game' => 1])
            ->and($payload[1])->toBe([$link->id]);
    })->group('smoke');
});
