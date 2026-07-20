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

// S02/T06 — UAT: realistic seed data, verify all widgets + filters end-to-end.
//
// Distinct from SignupAttributionReportTest (T05), which pins the report's
// contracts with small synthetic fixtures (3–4 users). This file drives the
// report through the UAT runbook (56-02-UAT.md) with a PRODUCTION-SHAPED
// dataset: 24 attribution users across all four provider buckets, five
// non-NULL referer domains, three content types, signups spread across six
// months, and participants across all five JoinSource values on BOTH
// game_participants and campaign_participants.
//
// Each test maps 1:1 to a numbered UAT step so the runbook stays in lock-step
// with the autonomous pre-flight. Step 8 is the load-bearing raw-SQL
// reconciliation across every widget.
//
// Livewire::test(SignupAttributionReport::class) drives the REAL Filament
// render pipeline — it mounts the page, calls the real table()/
// providerBreakdown()/topRefererDomains()/topSignupContentTypes()/
// joinSourceBreakdown() methods, and renders the real blade via
// getFilteredTableQuery(). A human clicking through
// /admin/signup-attribution-report exercises the same code path; the only
// difference is the visual chrome. The NEEDS-HUMAN runbook in 56-02-UAT.md
// covers the live visual confirmation.
//
// Table-narrowing assertions use representative records (one in-filter visible,
// one out-of-filter excluded) rather than the full filtered set because the
// report paginates by design — a production signup table has thousands of
// rows. Asserting all 8 google users render on one page would couple the UAT
// to the pagination page size; the filter's correctness is fully proven by
// spot-checking inclusion + exclusion plus the widget-count reconciliation.

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Filament::setCurrentPanel('admin');

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    // Seed the production-shaped dataset once per test. The seeder is
    // deterministic (fixed providers/domains/content/dates) so expected counts
    // are stable and the raw-SQL reconciliation has fixed targets.
    seedRealisticAttributionDataset();
});

/**
 * Seed a production-shaped attribution dataset.
 *
 * Deterministic distribution (verified against raw GROUP BY before fixing):
 *   Users (24 attribution + 1 beforeEach admin = 25 total):
 *     Providers:  google ×8, discord ×7, email ×6, NULL ×3 (+ admin NULL → Unknown = 4)
 *     Referers:   google.com ×4, discord.com ×4, t.co ×3, facebook.com ×2,
 *                 reddit.com ×2, NULL ×10 (non-NULL total = 15)
 *     Content:    game ×9, campaign ×3, venue ×3, NULL ×9 (non-NULL total = 15)
 *     Dates:      Jan(4) Feb(5) Mar(5) Apr(3) May(4) Jun(3) — spread for date-filter tests
 *
 * Participants (separate grain) — reuse attribution users (the same people who
 * signed up also join games/campaigns), so no extra NULL-attribution users
 * inflate the provider Unknown bucket:
 *   game_participants: friend_invite ×3, share_link ×2, application ×2,
 *                      email_invite ×1, short_link ×1   (= 9)
 *   campaign_participants: friend_invite ×2, share_link ×1, application ×1 (= 4)
 *   Cross-table friend_invite SUM = 5 (the load-bearing reconciliation target).
 */
function seedRealisticAttributionDataset(): void
{
    // ── Users: provider × referer × content type, dates spanning 6 months ──
    // Each row: [provider, refererDomain, contentType, contentSlug, month]
    // Realistic landing paths mirror what FirstTouch::detectContentContext
    // would actually parse (games/<slug>, campaigns/<slug>, venues/<slug>).
    $spread = [
        // Google signups (8) — mostly game/venue discovery via search
        ['google', 'google.com', 'game', 'curse-of-strahd', 1],
        ['google', 'google.com', 'game', 'waterdeep-dragonsheist', 1],
        ['google', 'google.com', 'venue', 'berlin-tavern', 2],
        ['google', 'google.com', 'game', 'phandelver', 3],
        ['google', 't.co', 'campaign', 'eberron', 3],
        ['google', null, null, null, 4],
        ['google', null, 'game', 'homebrew-west-marches', 5],
        ['google', 'reddit.com', null, null, 6],

        // Discord signups (7) — community-bridge cohort
        ['discord', 'discord.com', 'game', 'curse-of-strahd', 2],
        ['discord', 'discord.com', 'campaign', 'waterdeep', 2],
        ['discord', 'discord.com', 'game', 'tomb-of-annihilation', 3],
        ['discord', 'discord.com', null, null, 4],
        ['discord', null, 'venue', 'flgs-kreuzberg', 5],
        ['discord', 't.co', null, null, 5],
        ['discord', null, null, null, 6],

        // Email signups (6) — application/email-invite cohort
        ['email', null, null, null, 1],
        ['email', 'facebook.com', 'game', 'one-shot-cyoa', 2],
        ['email', null, 'campaign', 'rime-of-the-frostmaiden', 3],
        ['email', 'facebook.com', null, null, 4],
        ['email', null, 'game', 'storm-kings-thunder', 5],
        ['email', 't.co', null, null, 6],

        // NULL-provider signups (3) — pre-attribution-era users or direct deep-links
        [null, 'reddit.com', 'game', 'wildemount', 1],
        [null, null, null, null, 2],
        [null, null, 'venue', 'community-hq', 3],
    ];

    // Capture attribution user IDs so participants can reuse them (the same
    // people who signed up also join games/campaigns). This keeps the users
    // table at 25 rows — factory-creating fresh users for participants would
    // inflate the NULL-provider "Unknown" bucket and break the provider
    // reconciliation in STEP 2/8.
    $attributionUserIds = [];
    foreach ($spread as [$provider, $referer, $contentType, $slug, $month]) {
        $attributionUserIds[] = User::factory()->create([
            'signup_oauth_provider' => $provider,
            'first_touch_referer_domain' => $referer,
            'first_touch_path' => $contentType ? "/{$contentType}s/{$slug}" : null,
            'signup_content_type' => $contentType,
            'signup_content_slug' => $slug,
            'created_at' => "2026-0{$month}-15 12:00:00",
        ])->id;
    }

    // Cycle through attribution users for participant rows — a user can join
    // multiple games/campaigns, so reuse is safe and production-realistic.
    // The User model uses string IDs, so ->id is a string — leave the
    // closure's return type unspecified so it accepts whatever key type the
    // model uses (the 'user_id' => ... fill accepts string IDs natively).
    $participantIndex = 0;
    $nextUserId = function () use (&$participantIndex, $attributionUserIds) {
        $id = $attributionUserIds[$participantIndex % count($attributionUserIds)];
        $participantIndex++;

        return $id;
    };

    // ── Game participants: all 5 join sources ──
    // Each game's owner is an attribution user (GameFactory defaults to
    // spawning a fresh User via 'owner_id' => User::factory(), which would
    // add 13 NULL-provider organizer users and break STEP 2's Unknown
    // reconciliation). Assigning an attribution user as owner keeps the users
    // table at 25 rows.
    $gameSpread = [
        [JoinSource::FriendInvite, 3],
        [JoinSource::ShareLink, 2],
        [JoinSource::Application, 2],
        [JoinSource::EmailInvite, 1],
        [JoinSource::ShortLink, 1],
    ];
    foreach ($gameSpread as [$source, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $game = Game::factory()->create(['owner_id' => $nextUserId()]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $nextUserId(),
                'role' => 'player',
                'status' => ParticipantStatus::Approved,
                'join_source' => $source,
            ]);
        }
    }

    // ── Campaign participants: subset of join sources (cross-table SUM load-bearing) ──
    $campaignSpread = [
        [JoinSource::FriendInvite, 2],
        [JoinSource::ShareLink, 1],
        [JoinSource::Application, 1],
    ];
    foreach ($campaignSpread as [$source, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $campaign = Campaign::factory()->create(['owner_id' => $nextUserId()]);
            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $nextUserId(),
                'role' => 'player',
                'status' => ParticipantStatus::Approved->value,
                'join_source' => $source,
            ]);
        }
    }
}

// ── UAT runbook (mirrors 56-02-UAT.md) ───────────────

describe('UAT — realistic dataset end-to-end', function () {
    test('STEP 1: admin loads the report and all four summary sections render', function () {
        actingAs($this->platformAdmin);

        get('/admin/signup-attribution-report')
            ->assertSuccessful()
            ->assertSee('Signups by Provider')
            ->assertSee('Top Referer Domains')
            ->assertSee('Top Signup Content Types')
            ->assertSee('Participants by Join Source');
    });

    test('STEP 2: Signups-by-Provider widget shows all four buckets including Unknown (NULL provider)', function () {
        actingAs($this->platformAdmin);

        $report = Livewire\Livewire::test(SignupAttributionReport::class)->instance();
        $providers = $report->providerBreakdown();

        // Realistic dataset: google=8, discord=7, email=6, Unknown=4 (3 NULL + 1 admin).
        // Participants reuse attribution users (no extra NULL-provider rows),
        // so the Unknown bucket is exactly the 3 NULL-provider attribution
        // users + the beforeEach admin.
        expect($providers['Google'])->toBe(8)
            ->and($providers['Discord'])->toBe(7)
            ->and($providers['Email'])->toBe(6)
            ->and($providers['Unknown'])->toBe(4)
            ->and(array_sum($providers))->toBe(25); // 24 attribution users + admin
    });

    test('STEP 3: Top Referer Domains widget shows top 5 with NULLs excluded', function () {
        actingAs($this->platformAdmin);

        $report = Livewire\Livewire::test(SignupAttributionReport::class)->instance();
        $referers = $report->topRefererDomains(5);

        // Realistic dataset non-NULL referer totals: google.com=4, discord.com=4,
        // t.co=3, facebook.com=2, reddit.com=2. Five distinct domains → all
        // appear within the top-5 cap; ties break alphabetically.
        expect($referers['google.com'])->toBe(4)
            ->and($referers['discord.com'])->toBe(4)
            ->and($referers['t.co'])->toBe(3)
            ->and($referers['facebook.com'])->toBe(2)
            ->and($referers['reddit.com'])->toBe(2)
            ->and(array_sum($referers))->toBe(15); // every non-NULL referer
    });

    test('STEP 4: Top Signup Content Types widget shows Game/Campaign/Venue with NULLs excluded', function () {
        actingAs($this->platformAdmin);

        $report = Livewire\Livewire::test(SignupAttributionReport::class)->instance();
        $contentTypes = $report->topSignupContentTypes(5);

        // Realistic dataset non-NULL content totals: game=9, venue=3, campaign=3.
        expect($contentTypes['Game'])->toBe(9)
            ->and($contentTypes['Venue'])->toBe(3)
            ->and($contentTypes['Campaign'])->toBe(3)
            ->and(array_sum($contentTypes))->toBe(15);
    });

    test('STEP 5: Participants-by-Join-Source widget shows all five sources summed across both tables', function () {
        actingAs($this->platformAdmin);

        $report = Livewire\Livewire::test(SignupAttributionReport::class)->instance();
        $joinSources = $report->joinSourceBreakdown();

        // Cross-table SUM: game + campaign per source.
        // friend_invite = 3 + 2 = 5  ← load-bearing
        // share_link    = 2 + 1 = 3
        // application   = 2 + 1 = 3
        // email_invite  = 1 + 0 = 1
        // short_link    = 1 + 0 = 1
        expect($joinSources[JoinSource::FriendInvite->label()])->toBe(5)
            ->and($joinSources[JoinSource::ShareLink->label()])->toBe(3)
            ->and($joinSources[JoinSource::Application->label()])->toBe(3)
            ->and($joinSources[JoinSource::EmailInvite->label()])->toBe(1)
            ->and($joinSources[JoinSource::ShortLink->label()])->toBe(1);
    });

    test('STEP 6: provider filter narrows the table AND the three user-grain widgets; join-source widget is grain-isolated', function () {
        actingAs($this->platformAdmin);

        // Use representative records (not the full filtered set) because the
        // report paginates by design — spot-checking inclusion + exclusion
        // proves the filter narrowed correctly without coupling to page size.
        $oneGoogleUser = User::where('signup_oauth_provider', 'google')->first();
        $oneDiscordUser = User::where('signup_oauth_provider', 'discord')->first();

        $component = Livewire\Livewire::test(SignupAttributionReport::class)
            ->filterTable('signup_oauth_provider', 'google');

        $component->assertCanSeeTableRecords([$oneGoogleUser])
            ->assertCanNotSeeTableRecords([$oneDiscordUser]);

        $report = $component->instance();

        // User-grain widgets follow the filter (8 google users total).
        $providers = $report->providerBreakdown();
        expect($providers['Google'])->toBe(8)
            ->and($providers['Discord'])->toBe(0)
            ->and($providers['Email'])->toBe(0)
            ->and($providers)->not->toHaveKey('Unknown'); // NULL providers excluded by filter

        $referers = $report->topRefererDomains(5);
        // Only google-provider users' referer domains survive:
        // google.com×4 (rows 0,1,2,3), t.co×1 (row 4), reddit.com×1 (row 7). Total = 6.
        expect($referers['google.com'])->toBe(4)
            ->and($referers['t.co'])->toBe(1)
            ->and($referers['reddit.com'])->toBe(1)
            ->and(array_sum($referers))->toBe(6);

        $content = $report->topSignupContentTypes(5);
        // Google-provider content: game×4 (rows 0,1,3,6), venue×1 (row 2), campaign×1 (row 4).
        expect($content['Game'])->toBe(4)
            ->and($content['Venue'])->toBe(1)
            ->and($content['Campaign'])->toBe(1)
            ->and(array_sum($content))->toBe(6);

        // Join-source widget is at participant grain — UNCHANGED by the user filter.
        $joinSources = $report->joinSourceBreakdown();
        expect($joinSources[JoinSource::FriendInvite->label()])->toBe(5)
            ->and($joinSources[JoinSource::ShortLink->label()])->toBe(1);
    });

    test('STEP 7: content-type filter and date-range filter each narrow the table', function () {
        actingAs($this->platformAdmin);

        // ── Content-type filter: game ──
        // Spot-check: one game-content user visible, one campaign-content user excluded.
        $oneGameUser = User::where('signup_content_type', 'game')->first();
        $oneCampaignUser = User::where('signup_content_type', 'campaign')->first();
        Livewire\Livewire::test(SignupAttributionReport::class)
            ->filterTable('signup_content_type', 'game')
            ->assertCanSeeTableRecords([$oneGameUser])
            ->assertCanNotSeeTableRecords([$oneCampaignUser]);

        // ── Date-range filter: Q2 tail (May–Jun 2026) ──
        // The table sorts created_at DESC with 10-per-page pagination, so the
        // newest attribution users render on page 1. A May–Jun window captures
        // those page-1 users (May 4 + Jun 3 = 7 users, all on page 1 after the
        // admin — who is July — is excluded by the window). We assert a May
        // user IS visible (in-window AND on page 1) and an April user IS NOT
        // visible (out-of-window, filtered out).
        $mayUser = User::whereDate('created_at', '2026-05-15')->first();
        $aprilUser = User::whereDate('created_at', '2026-04-15')->first();
        Livewire\Livewire::test(SignupAttributionReport::class)
            ->filterTable('created_at', [
                'signed_up_from' => '2026-05-01',
                'signed_up_until' => '2026-06-30',
            ])
            ->assertCanSeeTableRecords([$mayUser])
            ->assertCanNotSeeTableRecords([$aprilUser]);
    });

    test('STEP 8 (load-bearing): every widget reconciles against raw SELECT GROUP BY counts', function () {
        // This is the load-bearing UAT assertion: every number shown to an
        // admin must match a raw SQL re-derivation. If any widget drifts from
        // the underlying data, this test fails.
        actingAs($this->platformAdmin);

        $report = Livewire\Livewire::test(SignupAttributionReport::class)->instance();

        // ── Provider widget vs raw GROUP BY ──
        $rawProviders = DB::table('users')
            ->select('signup_oauth_provider', DB::raw('count(*) as total'))
            ->groupBy('signup_oauth_provider')
            ->pluck('total', 'signup_oauth_provider');
        $providers = $report->providerBreakdown();
        expect($providers['Google'])->toBe((int) ($rawProviders['google'] ?? 0))
            ->and($providers['Discord'])->toBe((int) ($rawProviders['discord'] ?? 0))
            ->and($providers['Email'])->toBe((int) ($rawProviders['email'] ?? 0))
            ->and($providers['Unknown'])->toBe((int) ($rawProviders[null] ?? 0))
            ->and(array_sum($providers))->toBe((int) DB::table('users')->count());

        // ── Referer widget vs raw GROUP BY (NULLs excluded) ──
        $rawReferers = DB::table('users')
            ->whereNotNull('first_touch_referer_domain')
            ->where('first_touch_referer_domain', '!=', '')
            ->select('first_touch_referer_domain', DB::raw('count(*) as total'))
            ->groupBy('first_touch_referer_domain')
            ->orderByDesc('total')
            ->orderBy('first_touch_referer_domain')
            ->pluck('total', 'first_touch_referer_domain');
        $referers = $report->topRefererDomains(5);
        expect(array_sum($referers))->toBe((int) $rawReferers->sum());
        // Top entry matches the raw top entry (count desc, then alpha tiebreak).
        $rawTop = $rawReferers->keys()->first();
        expect(array_keys($referers)[0])->toBe($rawTop);

        // ── Content-type widget vs raw GROUP BY (NULLs excluded) ──
        $rawContent = DB::table('users')
            ->whereNotNull('signup_content_type')
            ->where('signup_content_type', '!=', '')
            ->select('signup_content_type', DB::raw('count(*) as total'))
            ->groupBy('signup_content_type')
            ->orderByDesc('total')
            ->orderBy('signup_content_type')
            ->pluck('total', 'signup_content_type');
        $content = $report->topSignupContentTypes(5);
        expect(array_sum($content))->toBe((int) $rawContent->sum());

        // ── Join-source widget vs raw cross-table GROUP BY ──
        $rawGame = DB::table('game_participants')
            ->select('join_source', DB::raw('count(*) as total'))
            ->groupBy('join_source')
            ->pluck('total', 'join_source');
        $rawCampaign = DB::table('campaign_participants')
            ->select('join_source', DB::raw('count(*) as total'))
            ->groupBy('join_source')
            ->pluck('total', 'join_source');
        $joinSources = $report->joinSourceBreakdown();
        foreach (JoinSource::cases() as $source) {
            $expected = (int) ($rawGame[$source->value] ?? 0) + (int) ($rawCampaign[$source->value] ?? 0);
            expect($joinSources[$source->label()])->toBe($expected);
        }
        // Cross-table SUM is structurally proven: friend_invite appears on both
        // tables and must total 5 (3 game + 2 campaign), not 3.
        expect($joinSources[JoinSource::FriendInvite->label()])->toBe(5);
    });
});
