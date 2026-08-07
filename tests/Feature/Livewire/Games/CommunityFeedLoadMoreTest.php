<?php

namespace Tests\Feature\Livewire\Games;

use App\Enums\GameStatus;
use App\Enums\RelationshipType;
use App\Enums\Visibility;
use App\Livewire\Games\GamesPage;
use App\Models\Game;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the load-more contract for the Community activity feed on the
 * My Games board — the platform-standard "load more" pattern (not numbered
 * pagination) introduced to replace the former `$activityFeed->links()`.
 *
 * Verifies that loadMoreActivity() grows the visible window and that the
 * shared _load-more partial hides itself once every item is shown.
 */
class CommunityFeedLoadMoreTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function load_more_grows_the_feed_window_and_then_hides_itself()
    {
        $viewer = $this->viewer();
        $hosts = User::factory()->count(20)->create();

        // Viewer follows the hosts so their games appear in the social feed.
        foreach ($hosts as $host) {
            UserRelationship::create([
                'user_id' => $viewer->id,
                'related_user_id' => $host->id,
                'type' => RelationshipType::Follow,
            ]);
            Game::factory()->for($host, 'owner')->create([
                'visibility' => Visibility::Public,
                'status' => GameStatus::Scheduled,
                'date_time' => now()->addDays(5),
            ]);
        }

        $component = Livewire::actingAs($viewer)->test(GamesPage::class);

        // The default window is 15; with 20 feed items there must be more.
        $component
            ->assertSet('activityFeedLimit', 15)
            ->assertSee(__('discovery.action_load_more'));

        // Load more — the window grows to 30, covering all 20 items.
        $component
            ->call('loadMoreActivity')
            ->assertSet('activityFeedLimit', 30);

        // Once every item is shown, the load-more control is gone.
        $component->assertDontSee(__('discovery.action_load_more'));
    }

    #[Test]
    public function load_more_button_is_absent_when_the_feed_fits_one_window()
    {
        $viewer = $this->viewer();
        $host = User::factory()->create();

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $host->id,
            'type' => RelationshipType::Follow,
        ]);

        Game::factory()->for($host, 'owner')->create([
            'visibility' => Visibility::Public,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($viewer)
            ->test(GamesPage::class)
            ->assertDontSee(__('discovery.action_load_more'), false);
    }

    private function viewer(): User
    {
        return User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }
}
