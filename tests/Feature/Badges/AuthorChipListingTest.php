<?php

use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Game-listing author chip (D237, spec §2.4 "subscriber + Compleat-class
 * only, avoid noise"): the host's whitelisted badge renders as a chip on
 * listing cards — the discovery card (shared by the discovery forks, the
 * city hub and game-system landing) and the My Games board byline.
 *
 * Whitelist reading (b): only subscriber badges (Supporting Member /
 * Founding Supporter) and Compleat-class badges may appear; every other
 * badge stays on the profile shelf and the GM Directory. Lapsed/revoked
 * holds never chip. Wording-agnostic assertions throughout (K004).
 */
// Badge-fixture serialization: see serializeBadgeDefinitionWrites() in tests/Pest.php.
beforeEach(fn () => serializeBadgeDefinitionWrites());

describe('AuthorChip — discovery cards', function () {
    it('renders the chip for a Compleat-class host', function () {
        $host = User::factory()->create();
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'campaign_compleat',
                'name_key' => 'badges.content_badge_campaign_compleat_name',
            ]))
            ->create(['tier' => 'bronze']);

        Game::factory()->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Compleat Host Table'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Compleat Host Table')
            ->assertSee(__('badges.content_badge_campaign_compleat_name', [], 'en'));
    });

    it('renders the chip for an active Supporting Member', function () {
        $host = User::factory()->create();
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'supporting_member',
                'name_key' => 'badges.content_badge_supporting_member_name',
            ]))
            ->create();

        Game::factory()->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Supporter Hosted Table'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Supporter Hosted Table')
            ->assertSee(__('badges.content_badge_supporting_member_name', [], 'en'));
    });

    it('renders no chip for non-whitelisted badges — Regulars Table stays on the shelf', function () {
        $host = User::factory()->create();
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'regulars_table',
                'name_key' => 'badges.content_badge_regulars_table_name',
            ]))
            ->create();

        Game::factory()->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Regulars Table Host'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Regulars Table Host')
            ->assertDontSee(__('badges.content_badge_regulars_table_name', [], 'en'));
    });

    it('renders no chip for a lapsed Supporting Member — the membership no longer certifies', function () {
        $host = User::factory()->create();
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'supporting_member',
                'name_key' => 'badges.content_badge_supporting_member_name',
            ]))
            ->lapsed()
            ->create();

        Game::factory()->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Lapsed Supporter Table'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Lapsed Supporter Table')
            ->assertDontSee(__('badges.content_badge_supporting_member_name', [], 'en'));
    });

    it('prefers the Compleat chip when the host also holds an active subscriber badge', function () {
        $host = User::factory()->create();
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'supporting_member',
                'name_key' => 'badges.content_badge_supporting_member_name',
            ]))
            ->create();
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'campaign_compleat',
                'name_key' => 'badges.content_badge_campaign_compleat_name',
            ]))
            ->create();

        Game::factory()->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Both Badges Host'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Both Badges Host')
            ->assertSee(__('badges.content_badge_campaign_compleat_name', [], 'en'))
            ->assertDontSee(__('badges.content_badge_supporting_member_name', [], 'en'));
    });
});

describe('AuthorChip — My Games board', function () {
    it('shows the chip next to the host byline for games the viewer plays in', function () {
        $host = User::factory()->create();
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'campaign_compleat',
                'name_key' => 'badges.content_badge_campaign_compleat_name',
            ]))
            ->create();

        $player = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Joined With Badged Host'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);
        GameParticipant::factory()->for($game)->for($player)->create();

        actingAs($player)
            ->get('/en/dashboard/games')
            ->assertSee('Joined With Badged Host')
            ->assertSee(__('badges.content_badge_campaign_compleat_name', [], 'en'));
    });

    it('shows no chip on the viewer’s own hosting cards', function () {
        $host = User::factory()->create(['profile_complete' => true]);
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'campaign_compleat',
                'name_key' => 'badges.content_badge_campaign_compleat_name',
            ]))
            ->create();

        Game::factory()->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'My Own Hosted Table'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        actingAs($host)
            ->get('/en/dashboard/games')
            ->assertSee('My Own Hosted Table')
            ->assertDontSee(__('badges.content_badge_campaign_compleat_name', [], 'en'));
    });
});

describe('AuthorChip — game-system landing', function () {
    it('renders the chip on upcoming tables after the TTL cache hydrates', function () {
        $system = GameSystem::factory()->create(['slug' => 'chip-landing-system']);
        $host = User::factory()->create();
        BadgeUser::factory()
            ->for($host)
            ->for(BadgeDefinition::factory()->create([
                'key' => 'campaign_compleat',
                'name_key' => 'badges.content_badge_campaign_compleat_name',
            ]))
            ->create();

        Game::factory()->withGameSystems([$system->id])->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Landing Badged Table'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertSee('Landing Badged Table')
            ->assertSee(__('badges.content_badge_campaign_compleat_name', [], 'en'));
    });
});

describe('AuthorChip — query budget', function () {
    it('resolves the whole discovery page in one batched holds query', function () {
        $compleat = BadgeDefinition::factory()->create([
            'key' => 'campaign_compleat',
            'name_key' => 'badges.content_badge_campaign_compleat_name',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $host = User::factory()->create();
            BadgeUser::factory()->for($host)->for($compleat)->create();

            Game::factory()->create([
                'owner_id' => $host->id,
                'name' => ['en' => "Badged Host Table {$i}"],
                'visibility' => 'public',
                'status' => 'scheduled',
                'date_time' => now()->addDays($i + 1),
            ]);
        }

        DB::enableQueryLog();

        Livewire\Livewire::test(BoardGamesDiscovery::class)->assertOk();

        $badgeUserQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, '"badge_user"'));
        DB::disableQueryLog();

        // One batched holds query for the whole page — never one per card
        // (GmDirectoryTest::test_top_badge_lookup_issues_one_batched_query_per_page
        // guard, applied to the listing surface).
        expect($badgeUserQueries)->toHaveCount(1);
    });

    it('resolves the whole My Games board in one batched holds query', function () {
        $player = User::factory()->create(['profile_complete' => true]);
        $compleat = BadgeDefinition::factory()->create([
            'key' => 'campaign_compleat',
            'name_key' => 'badges.content_badge_campaign_compleat_name',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $host = User::factory()->create();
            BadgeUser::factory()->for($host)->for($compleat)->create();

            $game = Game::factory()->create([
                'owner_id' => $host->id,
                'name' => ['en' => "Board Badged Table {$i}"],
                'visibility' => 'public',
                'status' => 'scheduled',
                'date_time' => now()->addDays($i + 1),
            ]);
            GameParticipant::factory()->for($game)->for($player)->create();
        }

        DB::enableQueryLog();

        actingAs($player)->get('/en/dashboard/games')->assertOk();

        $badgeUserQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, '"badge_user"'));
        DB::disableQueryLog();

        expect($badgeUserQueries)->toHaveCount(1);
    });
});
