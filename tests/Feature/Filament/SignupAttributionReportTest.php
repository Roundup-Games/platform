<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Filament\Pages\Reports\SignupAttributionReport;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// SignupAttributionReport feature coverage (S02/T05).
//
// Pins three contracts:
//   1. Access control — the page inherits Filament's default admin auth via
//      canAccess() -> isAdmin() (NO dedicated policy, matching the
//      EventAttendanceReport / MembershipReport convention).
//   2. Filter correctness — the three table filters (provider SelectFilter,
//      content-type SelectFilter, created_at date-range Filter) narrow the
//      user-grain table, and the filter-aware user-grain widgets
//      (providerBreakdown / topRefererDomains / topSignupContentTypes) follow
//      the active filters because they read getFilteredTableQuery().
//   3. Aggregation reconciliation — the user-grain widget totals match raw
//      SELECT count(*) GROUP BY counts over users, and joinSourceBreakdown
//      aggregates across BOTH game_participants and campaign_participants
//      (different grain — intentionally unfiltered).

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Filament::setCurrentPanel('admin');

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->regularUser = User::factory()->create();
});

/**
 * Seed a user with the five write-once signup-attribution columns set.
 * The base UserFactory does not define these columns (they are write-once at
 * signup, not a factory default), so tests set them explicitly.
 */
function attributionUser(array $attribution): User
{
    return User::factory()->create($attribution);
}

// ── Access control ────────────────────────────────────

describe('Access control', function () {
    test('guest is redirected from the signup attribution report', function () {
        get('/admin/signup-attribution-report')->assertRedirect();
    });

    test('authenticated non-admin is forbidden from the signup attribution report', function () {
        actingAs($this->regularUser);

        get('/admin/signup-attribution-report')->assertForbidden();
    });

    test('admin can load the report and sees all four summary sections', function () {
        // Seed at least one user with attribution so the table + widgets are
        // populated.
        attributionUser([
            'signup_oauth_provider' => 'discord',
            'first_touch_referer_domain' => 'discord.com',
            'signup_content_type' => 'game',
            'signup_content_slug' => 'curse-of-strahd',
        ]);

        actingAs($this->platformAdmin);

        get('/admin/signup-attribution-report')
            ->assertSuccessful()
            ->assertSee('Signups by Provider')
            ->assertSee('Top Referer Domains')
            ->assertSee('Top Signup Content Types')
            ->assertSee('Participants by Join Source');
    });
});

// ── Filters narrow the user-grain table ──────────────

describe('Table filters', function () {
    test('provider filter narrows the table to the selected provider', function () {
        $discord = attributionUser(['signup_oauth_provider' => 'discord']);
        $google = attributionUser(['signup_oauth_provider' => 'google']);
        $email = attributionUser(['signup_oauth_provider' => 'email']);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(SignupAttributionReport::class)
            ->filterTable('signup_oauth_provider', 'discord')
            ->assertCanSeeTableRecords([$discord])
            ->assertCanNotSeeTableRecords([$google, $email]);
    });

    test('content type filter narrows the table to the selected content type', function () {
        $game = attributionUser([
            'signup_oauth_provider' => 'google',
            'signup_content_type' => 'game',
            'signup_content_slug' => 'dnd-5e',
        ]);
        $campaign = attributionUser([
            'signup_oauth_provider' => 'discord',
            'signup_content_type' => 'campaign',
            'signup_content_slug' => 'curse-of-strahd',
        ]);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(SignupAttributionReport::class)
            ->filterTable('signup_content_type', 'game')
            ->assertCanSeeTableRecords([$game])
            ->assertCanNotSeeTableRecords([$campaign]);
    });

    test('date range filter narrows the table to the selected window', function () {
        $january = attributionUser(['signup_oauth_provider' => 'discord', 'created_at' => '2026-01-15 12:00:00']);
        $march = attributionUser(['signup_oauth_provider' => 'google', 'created_at' => '2026-03-10 12:00:00']);
        $may = attributionUser(['signup_oauth_provider' => 'email', 'created_at' => '2026-05-20 12:00:00']);

        actingAs($this->platformAdmin);

        // Window covering only the February–April signups (March user in, January + May out).
        Livewire\Livewire::test(SignupAttributionReport::class)
            ->filterTable('created_at', [
                'signed_up_from' => '2026-02-01',
                'signed_up_until' => '2026-04-30',
            ])
            ->assertCanSeeTableRecords([$march])
            ->assertCanNotSeeTableRecords([$january, $may]);
    });
});

// ── Aggregation correctness + reconciliation ─────────

describe('Aggregation and reconciliation', function () {
    test('filter-aware user widgets reconcile with raw counts and respect active filters', function () {
        // Seed a known distribution across providers, referer domains, and
        // content types. The beforeEach platform admin also exists as a user
        // with NULL attribution — it must land in the "Unknown" provider
        // bucket and be excluded from the referer / content widgets (NULLs
        // carry no attribution signal).
        $discordGame = attributionUser([
            'signup_oauth_provider' => 'discord',
            'first_touch_referer_domain' => 'discord.com',
            'signup_content_type' => 'game',
            'signup_content_slug' => 'curse-of-strahd',
        ]);
        $googleCampaign = attributionUser([
            'signup_oauth_provider' => 'google',
            'first_touch_referer_domain' => 'google.com',
            'signup_content_type' => 'campaign',
            'signup_content_slug' => 'waterdeep',
        ]);
        $googleGame = attributionUser([
            'signup_oauth_provider' => 'google',
            'first_touch_referer_domain' => 'google.com',
            'signup_content_type' => 'game',
            'signup_content_slug' => 'dnd-5e',
        ]);
        $emailNoTouch = attributionUser([
            'signup_oauth_provider' => 'email',
        ]);

        actingAs($this->platformAdmin);

        $report = Livewire\Livewire::test(SignupAttributionReport::class)->instance();

        // ── providerBreakdown reconciles with raw GROUP BY ──
        $rawProviders = DB::table('users')
            ->select('signup_oauth_provider', DB::raw('count(*) as total'))
            ->groupBy('signup_oauth_provider')
            ->pluck('total', 'signup_oauth_provider');

        $providers = $report->providerBreakdown();
        expect($providers['Google'])->toBe((int) ($rawProviders['google'] ?? 0))
            ->and($providers['Discord'])->toBe((int) ($rawProviders['discord'] ?? 0))
            ->and($providers['Email'])->toBe((int) ($rawProviders['email'] ?? 0))
            ->and($providers)->toHaveKey('Unknown') // the admin + any null-provider user
            ->and($providers['Unknown'])->toBe((int) ($rawProviders[null] ?? 0));

        // ── topRefererDomains excludes NULLs and orders by count desc ──
        $referer = $report->topRefererDomains(5);
        expect(array_sum($referer))->toBe((int) DB::table('users')->whereNotNull('first_touch_referer_domain')->where('first_touch_referer_domain', '!=', '')->count())
            ->and($referer['google.com'])->toBe(2)
            ->and($referer['discord.com'])->toBe(1);

        // ── topSignupContentTypes excludes NULLs and labels with ucfirst ──
        $contentTypes = $report->topSignupContentTypes(5);
        expect(array_sum($contentTypes))->toBe((int) DB::table('users')->whereNotNull('signup_content_type')->where('signup_content_type', '!=', '')->count())
            ->and($contentTypes['Game'])->toBe(2)
            ->and($contentTypes['Campaign'])->toBe(1);

        // ── Filter-awareness: applying the provider filter narrows the
        //    user-grain widgets but leaves the (different-grain) join-source
        //    widget untouched. ──
        $report = Livewire\Livewire::test(SignupAttributionReport::class)
            ->filterTable('signup_oauth_provider', 'google')
            ->instance();

        $filteredProviders = $report->providerBreakdown();
        expect($filteredProviders['Google'])->toBe(2)
            ->and($filteredProviders['Discord'])->toBe(0)
            ->and($filteredProviders['Email'])->toBe(0);

        $filteredReferer = $report->topRefererDomains(5);
        expect($filteredReferer)->toBe(['google.com' => 2]);

        $filteredContent = $report->topSignupContentTypes(5);
        // Order is NOT the claim here — ties break alphabetically by
        // signup_content_type, so 'Campaign' precedes 'Game'. The load-bearing
        // assertion is that BOTH content types survive at count 1 each under
        // the provider filter (filter-awareness), so check keys independently.
        expect($filteredContent)->toHaveKey('Game', 1)
            ->and($filteredContent)->toHaveKey('Campaign', 1)
            ->and(array_sum($filteredContent))->toBe(2);
    });

    test('join source breakdown aggregates across both game and campaign participants', function () {
        // The join-source widget is at participant grain (separate from the
        // user-grain signup table) and intentionally NOT narrowed by the user
        // attribution filters. It must SUM counts across game_participants AND
        // campaign_participants for the same join_source value.
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        // Same join_source (friend_invite) on BOTH tables → must add to 2.
        GameParticipant::create([
            'game_id' => Game::factory()->create()->id,
            'user_id' => $userA->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved,
            'join_source' => JoinSource::FriendInvite,
        ]);
        CampaignParticipant::create([
            'campaign_id' => Campaign::factory()->create()->id,
            'user_id' => $userB->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
            'join_source' => JoinSource::FriendInvite,
        ]);
        // A different join_source on the game table.
        GameParticipant::create([
            'game_id' => Game::factory()->create()->id,
            'user_id' => $userC->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved,
            'join_source' => JoinSource::ShareLink,
        ]);

        actingAs($this->platformAdmin);

        $report = Livewire\Livewire::test(SignupAttributionReport::class)->instance();
        $joinSources = $report->joinSourceBreakdown();

        // Reconcile against raw SQL across both tables.
        $rawGame = DB::table('game_participants')
            ->select('join_source', DB::raw('count(*) as total'))
            ->groupBy('join_source')
            ->pluck('total', 'join_source');
        $rawCampaign = DB::table('campaign_participants')
            ->select('join_source', DB::raw('count(*) as total'))
            ->groupBy('join_source')
            ->pluck('total', 'join_source');

        $expectedFriend = (int) ($rawGame[JoinSource::FriendInvite->value] ?? 0)
            + (int) ($rawCampaign[JoinSource::FriendInvite->value] ?? 0);
        $expectedShare = (int) ($rawGame[JoinSource::ShareLink->value] ?? 0)
            + (int) ($rawCampaign[JoinSource::ShareLink->value] ?? 0);

        expect($joinSources[JoinSource::FriendInvite->label()])->toBe($expectedFriend)
            ->and($joinSources[JoinSource::ShareLink->label()])->toBe($expectedShare);

        // The cross-table SUM is the load-bearing assertion: friend_invite
        // appears once on each table and must total 2, not 1.
        expect($joinSources[JoinSource::FriendInvite->label()])->toBe(2);

        // Join-source widget is grain-isolated from user attribution filters:
        // applying a provider filter does not change the participant counts.
        $filtered = Livewire\Livewire::test(SignupAttributionReport::class)
            ->filterTable('signup_oauth_provider', 'google')
            ->instance()
            ->joinSourceBreakdown();
        expect($filtered[JoinSource::FriendInvite->label()])->toBe($expectedFriend)
            ->and($filtered[JoinSource::ShareLink->label()])->toBe($expectedShare);
    });
});
