<?php

namespace Tests\Feature\Livewire\Campaigns;

use App\Livewire\Campaigns\AddSessionToCampaign;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Copy-on-write semantics for AddSessionToCampaign.
 *
 * Design intent: campaign_game_system is the recurring DEFAULT offering (the
 * template); game_game_system is each spawned session's OWN offering. When a
 * session is spawned, the campaign's default set is copied into the session's
 * pivot and frozen. Editing the campaign's default later does NOT change
 * already-scheduled sessions (RSVP stability), and a host can override a single
 * session without touching the recurring template.
 */
class AddSessionToCampaignCopyOnWriteTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function spawned_session_inherits_campaign_default_pivot_set(): void
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $systems = GameSystem::factory()->count(2)->create();
        $systemIds = $systems->modelKeys();

        $campaign = Campaign::factory()
            ->withCampaignGameSystems($systemIds)
            ->create([
                'owner_id' => $owner->id,
                'game_type' => 'gathering',
                'recurrence' => 'weekly',
                'time_of_day' => '19:00',
            ]);

        Livewire::actingAs($owner)
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Night One')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors();

        $game = Game::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($game);

        // Copy-on-write: the session's own game_game_system pivot carries the
        // full campaign default set {A, B} at spawn time.
        $sessionSystemIds = $game->gameSystems()->pluck('game_systems.id')->all();
        $this->assertSame($this->sorted($systemIds), $this->sorted($sessionSystemIds));

        // The campaign template is unchanged.
        $campaignSystemIds = $campaign->fresh()->gameSystems()->pluck('game_systems.id')->all();
        $this->assertSame($this->sorted($systemIds), $this->sorted($campaignSystemIds));
    }

    #[Test]
    public function per_session_override_does_not_touch_campaign_default(): void
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $systems = GameSystem::factory()->count(3)->create();
        $defaultIds = [$systems[0]->id, $systems[1]->id]; // {A, B}
        $overrideId = $systems[2]->id; // C

        $campaign = Campaign::factory()
            ->withCampaignGameSystems($defaultIds)
            ->create([
                'owner_id' => $owner->id,
                'game_type' => 'gathering',
                'recurrence' => 'weekly',
                'time_of_day' => '19:00',
            ]);

        Livewire::actingAs($owner)
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Special Session')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors();

        $game = Game::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($game);

        // After spawn the session owns {A, B}.
        $this->assertSame(
            $this->sorted($defaultIds),
            $this->sorted($game->gameSystems()->pluck('game_systems.id')->all()),
        );

        // Override: add an experimental system to THIS session only.
        $game->gameSystems()->sync(array_merge($defaultIds, [$overrideId]));

        // Session now has {A, B, C} while the campaign default stays {A, B}.
        $this->assertSame(
            $this->sorted(array_merge($defaultIds, [$overrideId])),
            $this->sorted($game->fresh()->gameSystems()->pluck('game_systems.id')->all()),
        );
        $this->assertSame(
            $this->sorted($defaultIds),
            $this->sorted($campaign->fresh()->gameSystems()->pluck('game_systems.id')->all()),
        );
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function sorted(array $ids): array
    {
        $ids = array_values(array_map('strval', $ids));
        sort($ids);

        return $ids;
    }
}
