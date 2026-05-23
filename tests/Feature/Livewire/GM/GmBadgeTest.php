<?php

namespace Tests\Feature\Livewire\GM;

use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Livewire\Profile\PublicProfile;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GmBadgeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    public function test_active_gm_shows_badge_across_pages(): void
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $owner->assignRole('Game Master');
        GMProfile::factory()->create(['user_id' => $owner->id, 'is_active' => true]);

        $game = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public']);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public']);
        $viewer = User::factory()->create();

        // Public profile shows badge
        Livewire::test(PublicProfile::class, ['user' => $owner])
            ->assertSee('Game Master');

        // Game detail shows badge
        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee('Game Master');

        // Campaign detail shows badge
        Livewire::actingAs($viewer)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Game Master');
    }

    public function test_badge_not_shown_when_inactive_or_no_profile(): void
    {
        $noProfile = User::factory()->create(['profile_complete' => true]);
        $inactive = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $inactive->id, 'is_active' => false]);
        $nonGmOwner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $nonGmOwner->id, 'visibility' => 'public']);
        $viewer = User::factory()->create();

        // No GM profile → no badge on public profile
        Livewire::test(PublicProfile::class, ['user' => $noProfile])
            ->assertDontSee('Game Master');

        // Inactive GM → no badge on public profile and no profile section
        Livewire::test(PublicProfile::class, ['user' => $inactive])
            ->assertDontSee('Game Master')
            ->assertDontSee('Game Master Profile');

        // Non-GM game owner → no badge on game detail
        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee('Game Master');
    }

    public function test_profile_section_shows_bio_and_rating(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create([
            'user_id' => $user->id,
            'bio' => 'Veteran DM with 10 years of experience running epic campaigns.',
            'average_rating' => 4.50,
            'review_count' => 12,
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Game Master Profile')
            ->assertSee('Veteran DM with 10 years of experience')
            ->assertSee('12 reviews');
    }
}
