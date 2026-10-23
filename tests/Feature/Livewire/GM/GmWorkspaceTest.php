<?php

use App\Dto\CharterContent;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\GM\GmWorkspace;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\PreviouslyOnDraft;
use App\Models\Review;
use App\Models\SessionZeroSurvey;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
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
    describe('revokeLink authorization', function () {
        it('404s another GM\'s link id — the lookup is scoped to the caller', function () {
            $gm = $this->createSubscribedGm();
            $other = $this->createSubscribedGm();

            $otherGame = Game::factory()->create([
                'owner_id' => $other->id,
                'status' => 'scheduled',
                'date_time' => now()->addDays(3),
                'name' => ['en' => 'Other GMs session'],
            ]);

            $link = ShortLink::create([
                'user_id' => $other->id,
                'code' => 'gws-cross-user',
                'url' => 'https://example.com/other',
                'linkable_type' => 'game',
                'linkable_id' => $otherGame->id,
                'label' => 'Other GM',
                'purpose' => 'manual',
            ]);

            $component = Livewire\Livewire::actingAs($gm)->test(GmWorkspace::class);

            $component->call('revokeLink', $link->id);

            // whereBelongsTo scoped the lookup to the caller: the row was
            // never found, let alone deleted.
            expect($link->fresh())->not->toBeNull();
        });

        it('refuses a foreign linkable entity behind an own link — defense in depth', function () {
            $gm = $this->createSubscribedGm();
            $other = $this->createSubscribedGm();

            $foreignGame = Game::factory()->create([
                'owner_id' => $other->id,
                'status' => 'scheduled',
                'date_time' => now()->addDays(3),
                'name' => ['en' => 'Not this GMs game'],
            ]);

            $link = ShortLink::create([
                'user_id' => $gm->id,
                'code' => 'gws-foreign-entity',
                'url' => 'https://example.com/foreign',
                'linkable_type' => 'game',
                'linkable_id' => $foreignGame->id,
                'label' => 'Foreign entity',
                'purpose' => 'manual',
            ]);

            Livewire\Livewire::actingAs($gm)
                ->test(GmWorkspace::class)
                ->call('revokeLink', $link->id)
                ->assertHasNoErrors();

            // The ownership-mismatch branch refused: warning logged and
            // the link left intact (a success path would have deleted it).
            expect($link->fresh())->not->toBeNull();
        });
    });

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

// ═══════════════════════════════════════════════════════════
// CHARTER HEALTH (M070/S04-T03)
// ═══════════════════════════════════════════════════════════

/**
 * A chartered campaign for a subscribed GM, published through the real
 * lifecycle (deterministic quick-start inputs, expected_shape filled —
 * the CharterSigningServiceTest fixture idiom).
 *
 * @return array{0: Campaign, 1: SessionZeroSurvey}
 */
function workspace_chartered_campaign(User $gm, string $name): array
{
    $campaign = Campaign::factory()->create([
        'owner_id' => $gm->id,
        'name' => ['en' => $name],
        'description' => ['en' => "A campaign named {$name}."],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);
    $charter = CharterService::start($gm, $campaign);
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';
    CharterService::publish($gm, $charter, $name, $content);

    return [$campaign, $charter];
}

describe('GmWorkspace Charter Health', function () {
    it('shows one row per charter with percent signed, pending count, and days outstanding', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00'));

        $gm = $this->createSubscribedGm();
        [$campaign, $charter] = workspace_chartered_campaign($gm, 'Threshold Charter Watch');

        [$signer, $pending] = [User::factory()->create(), User::factory()->create()];
        CampaignParticipant::factory()->create(['campaign_id' => $campaign->id, 'user_id' => $signer->id]);
        CampaignParticipant::factory()->create(['campaign_id' => $campaign->id, 'user_id' => $pending->id]);

        CharterSigningService::sign($signer, $charter, [
            'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        ]);

        // Five days pass with the second signature still outstanding.
        Carbon::setTestNow(Carbon::parse('2026-10-24 09:00:00'));

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Threshold Charter Watch')
            ->assertSeeText(__('charter.label_percent_signed', ['percent' => 50], 'en'))
            ->assertSeeText(__('charter.label_pending_count', ['count' => 1], 'en'))
            ->assertSeeText(trans_choice('charter.label_days_outstanding', 5, [], 'en'))
            ->assertSee(route('charter.view', $charter->uuid))
            ->assertSee(route('campaigns.charter', $campaign->id));
    });

    it('shows the empty state for a GM without charters', function () {
        $gm = $this->createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSeeText(__('charter.heading_workspace_charters', [], 'en'))
            ->assertSeeText(__('charter.content_workspace_empty', [], 'en'));
    });

    it('renders draft and archived rows with their status chips and routes actions per status', function () {
        $gm = $this->createSubscribedGm();

        $draftCampaign = Campaign::factory()->create([
            'owner_id' => $gm->id,
            'name' => ['en' => 'Draft Watch'],
            'description' => ['en' => 'A campaign still being planned.'],
            'max_players' => 5,
            'session_duration' => 3,
            'recurrence' => 'weekly',
        ]);
        CharterService::start($gm, $draftCampaign);

        [$archivedCampaign, $archivedCharter] = workspace_chartered_campaign($gm, 'Archived Watch');
        CharterService::archive($gm, $archivedCharter);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSeeText(__('charter.status_draft', [], 'en'))
            ->assertSeeText(__('charter.status_archived', [], 'en'))
            // Participant-less charters show the honest zero state.
            ->assertSeeText(__('charter.label_no_participants', [], 'en'))
            // Draft row: composer link plus the public view link.
            ->assertSee(route('campaigns.charter', $draftCampaign->id))
            ->assertSee(route('charter.view', $draftCampaign->charter->uuid))
            // Archived row: the public view link only — no composer route.
            ->assertSee(route('charter.view', $archivedCharter->uuid))
            ->assertDontSee(route('campaigns.charter', $archivedCampaign->id));
    });
});

describe('GmWorkspace charter starter + needs-attention', function () {
    it('lists owned active campaigns without a charter and links each into the composer', function () {
        $gm = $this->createSubscribedGm();
        $campaign = Campaign::factory()->create([
            'owner_id' => $gm->id,
            'name' => ['en' => 'Charterless Watch'],
            'description' => ['en' => 'A campaign awaiting its charter.'],
            'max_players' => 5,
            'session_duration' => 3,
            'recurrence' => 'weekly',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSeeText(__('gws.heading_charterless_campaigns', [], 'en'))
            ->assertSee('Charterless Watch')
            ->assertSee(route('campaigns.charter', $campaign));
    });

    it('hides the starter list when every active campaign is chartered', function () {
        $gm = $this->createSubscribedGm();
        workspace_chartered_campaign($gm, 'Fully Chartered');

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSeeText(__('gws.heading_charterless_campaigns', [], 'en'))
            ->assertDontSeeText(__('charter.content_workspace_empty', [], 'en'));
    });

    it('surfaces an unpublished Previously On draft as needing attention', function () {
        $gm = $this->createSubscribedGm();
        [$campaign] = workspace_chartered_campaign($gm, 'Digest Watch');
        $next = Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(2),
            'name' => ['en' => 'Digest Watch Session'],
        ]);
        PreviouslyOnDraft::factory()->forSession($campaign, $next)->create();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSeeText(__('gws.heading_attention', [], 'en'))
            ->assertSeeText(__('gws.heading_digests_waiting', [], 'en'))
            ->assertSee('Digest Watch')
            ->assertSee(__('gws.action_review_publish', [], 'en'));
    });

    it('lists a completed campaign session without a recap as recap-due', function () {
        $gm = $this->createSubscribedGm();
        [$campaign] = workspace_chartered_campaign($gm, 'Recap Watch');
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'status' => 'completed',
            'date_time' => now()->subDays(2),
            'name' => ['en' => 'Recap Watch Session'],
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSeeText(__('gws.heading_recap_due', [], 'en'))
            ->assertSee('Recap Watch Session')
            ->assertSee(__('gws.action_write_recap', [], 'en'));
    });

    it('hides the attention panel when nothing needs the GM', function () {
        $gm = $this->createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSeeText(__('gws.heading_attention', [], 'en'));
    });
});
