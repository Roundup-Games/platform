<?php

use App\Enums\ParticipantRole;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\SessionCreationService;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

// ── Helpers ──────────────────────────────────────────────

function sessionCreationDraft(array $overrides = []): array
{
    $system = $overrides['system'] ?? GameSystem::factory()->create();
    unset($overrides['system']);

    return array_merge([
        'validated' => [
            'game_type' => 'board_game',
            'game_system_id' => $system->id,
            'game_systems' => null,
            'min_players' => 2,
            'max_players' => 6,
        ],
        'translatable' => [
            'name' => ['en' => 'Service Test Session'],
            'description' => ['en' => 'Created through SessionCreationService.'],
        ],
        'safety_rules' => null,
        'vibe_flags' => [],
        'bench_mode' => false,
        'complexity' => null,
        'min_reliability_preference' => null,
        'cover_image' => null,
    ], $overrides);
}

// ═══════════════════════════════════════════════════════════
// GAME CREATION PIPELINE
// ═══════════════════════════════════════════════════════════

describe('SessionCreationService — game pipeline', function () {
    it('persists the game, syncs the canonical pivot, and ensures the owner participant', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        $game = app(SessionCreationService::class)->create(
            $user,
            'game',
            sessionCreationDraft(['system' => $system, 'vibe_flags' => ['rules_light']]),
            makeModel: function (array $context): Game {
                return Game::factory()->create([
                    'owner_id' => $context['owner_id'],
                    'game_type' => $context['validated']['game_type'],
                    'name' => $context['translatable']['name'],
                ]);
            },
        );

        // Re-read: model events during create may have lazily cached the
        // relation property before the post-create pivot sync ran.
        $freshGame = Game::find($game->id);

        expect($game)->toBeInstanceOf(Game::class)
            ->and($game->owner_id)->toBe($user->id)
            ->and($freshGame?->gameSystems->pluck('id'))->toContain($system->id)
            ->and(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->exists())->toBeTrue()
            ->and(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->first()->role)->toBe(ParticipantRole::Owner);
    });

    it('stores the host-uploaded cover outside the transaction', function () {
        $user = User::factory()->create();

        $game = app(SessionCreationService::class)->create(
            $user,
            'game',
            sessionCreationDraft(['cover_image' => UploadedFile::fake()->image('cover.jpg')]),
            makeModel: fn (array $context): Game => Game::factory()->create([
                'owner_id' => $context['owner_id'],
            ]),
        );

        expect($game->getMedia('cover'))->toHaveCount(1);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN CREATION PIPELINE
// ═══════════════════════════════════════════════════════════

describe('SessionCreationService — campaign pipeline', function () {
    it('persists the campaign, syncs the canonical pivot, and ensures the owner participant', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        $campaign = app(SessionCreationService::class)->create(
            $user,
            'campaign',
            sessionCreationDraft(['system' => $system, 'validated' => ['game_type' => 'gathering', 'game_system_id' => null, 'game_systems' => [$system->id], 'min_players' => null, 'max_players' => null]]),
            makeModel: fn (array $context): Campaign => Campaign::factory()->create([
                'owner_id' => $context['owner_id'],
                'game_type' => $context['validated']['game_type'],
                'name' => $context['translatable']['name'],
            ]),
        );

        $freshCampaign = Campaign::find($campaign->id);

        expect($campaign)->toBeInstanceOf(Campaign::class)
            ->and($freshCampaign?->gameSystems->pluck('id'))->toContain($system->id)
            ->and(CampaignParticipant::where('campaign_id', $campaign->id)->where('user_id', $user->id)->exists())->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// SERVICE CONTRACT
// ═══════════════════════════════════════════════════════════

describe('SessionCreationService — service contract', function () {
    it('scrubs bench mode for non-GM creators', function () {
        $user = User::factory()->create();
        GameSystem::factory()->create();

        $scrubbedBenchMode = null;

        app(SessionCreationService::class)->create(
            $user,
            'game',
            sessionCreationDraft(['bench_mode' => true]),
            makeModel: function (array $context) use (&$scrubbedBenchMode): Game {
                $scrubbedBenchMode = $context['bench_mode'];

                return Game::factory()->create(['owner_id' => $context['owner_id']]);
            },
        );

        expect($scrubbedBenchMode)->toBeFalse();
    });

    it('scrubs bench mode, complexity, and reliability for gatherings', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        $contextSeen = null;

        app(SessionCreationService::class)->create(
            $user,
            'game',
            sessionCreationDraft([
                'system' => $system,
                'bench_mode' => true,
                'complexity' => '4.2',
                'min_reliability_preference' => '85',
                'validated' => ['game_type' => 'gathering', 'game_system_id' => null, 'game_systems' => [$system->id], 'min_players' => null, 'max_players' => 12],
            ]),
            makeModel: function (array $context) use (&$contextSeen): Game {
                $contextSeen = $context;

                return Game::factory()->create(['owner_id' => $context['owner_id']]);
            },
        );

        expect($contextSeen['bench_mode'])->toBeFalse()
            ->and($contextSeen['complexity'])->toBeNull()
            ->and($contextSeen['min_reliability_preference'])->toBeNull()
            ->and($contextSeen['pivot_system_ids'])->toBe([$system->id]);
    });

    it('keeps bench mode for GM creators', function () {
        Role::firstOrCreate(['name' => 'Game Master', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Game Master');
        GameSystem::factory()->create();

        $contextSeen = null;

        app(SessionCreationService::class)->create(
            $user,
            'game',
            sessionCreationDraft(['bench_mode' => true]),
            makeModel: function (array $context) use (&$contextSeen): Game {
                $contextSeen = $context;

                return Game::factory()->create(['owner_id' => $context['owner_id']]);
            },
        );

        expect($contextSeen['bench_mode'])->toBeTrue();
    });

    it('rejects unknown entity types fail-closed', function () {
        $user = User::factory()->create();

        app(SessionCreationService::class)->create(
            $user,
            'tournament',
            sessionCreationDraft(),
            makeModel: fn (): Game => throw new RuntimeException('must not be reached'),
        );
    })->throws(InvalidArgumentException::class);
});
