<?php

namespace Tests\Feature\Livewire\Games;

use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use App\Support\DiscordJoinIntent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M059/S02 — the Discord "My seat" on-ramp landing on the game detail page.
 *
 * Covers {@see GameDetail::canJoinViaDiscord()}, the {@see joinViaDiscord()}
 * action, and the mount-time auto-join driven by ?discord_join=1. Mirrors the
 * share-link join contract but attributes {@see JoinSource::Discord}.
 */
class DiscordJoinGameDetailTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Build an authed viewer + a public scheduled open game they can join.
     *
     * @return array{0: User, 1: Game}
     */
    private function viewerAndJoinableGame(): array
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
            'status' => GameStatus::Scheduled->value,
            'max_players' => 6,
            'date_time' => now()->addDays(3),
        ]);
        // profile_complete = true satisfies the profile.complete route middleware
        // guarding games.show (the games.show route lives behind it).
        $viewer = User::factory()->create(['profile_complete' => true]);

        return [$viewer, $game];
    }

    #[Test]
    public function discord_join_flag_auto_joins_an_open_game_with_discord_attribution()
    {
        [$viewer, $game] = $this->viewerAndJoinableGame();

        $this->actingAs($viewer)
            ->withSession([])
            ->get(route('games.show', ['locale' => 'en', 'id' => $game->id]).'?discord_join=1')
            ->assertOk();

        // The member is on the roster as Approved, attributed to Discord.
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $viewer->id)
            ->first();
        $this->assertNotNull($participant);
        $this->assertSame(ParticipantStatus::Approved->value, $participant->status->value);
        $this->assertSame(JoinSource::Discord->value, $participant->join_source->value);
        $this->assertNotNull($participant->approved_at);
    }

    #[Test]
    public function join_via_discord_action_attributed_as_discord_source()
    {
        [$viewer, $game] = $this->viewerAndJoinableGame();

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('joinViaDiscord')
            ->assertHasNoErrors();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $viewer->id)
            ->first();
        $this->assertNotNull($participant);
        $this->assertSame(JoinSource::Discord->value, $participant->join_source->value);
    }

    #[Test]
    public function can_join_via_discord_is_false_for_the_owner()
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire::actingAs($owner)->test(GameDetail::class, ['id' => $game->id]);

        $this->assertFalse($component->instance()->canJoinViaDiscord());
    }

    #[Test]
    public function can_join_via_discord_is_false_for_an_existing_participant()
    {
        [$viewer, $game] = $this->viewerAndJoinableGame();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $viewer->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $component = Livewire::actingAs($viewer)->test(GameDetail::class, ['id' => $game->id]);

        $this->assertFalse($component->instance()->canJoinViaDiscord());
    }

    #[Test]
    public function discord_join_on_a_full_game_routes_to_waitlist_with_discord_attribution()
    {
        [$viewer, $game] = $this->viewerAndJoinableGame();
        $game->update(['max_players' => 1]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now()->subDay(),
        ]);

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('joinViaDiscord')
            ->assertHasNoErrors();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $viewer->id)
            ->first();
        $this->assertNotNull($participant);
        $this->assertSame(ParticipantStatus::Waitlisted->value, $participant->status->value);
        $this->assertSame(JoinSource::Discord->value, $participant->join_source->value);
    }

    #[Test]
    public function discord_join_does_not_fire_without_the_query_flag()
    {
        // A plain visit (no ?discord_join=1) must NOT auto-join — the member
        // sees the normal game page.
        [$viewer, $game] = $this->viewerAndJoinableGame();

        $this->actingAs($viewer)
            ->withSession([])
            ->get(route('games.show', ['locale' => 'en', 'id' => $game->id]))
            ->assertOk();

        $this->assertSame(
            0,
            GameParticipant::where('game_id', $game->id)->where('user_id', $viewer->id)->count(),
            'no auto-join without the discord_join flag'
        );
    }

    // ════════════════════════════════════════════════════
    //  SCREENING GATE: protected games are screened on the web Apply path
    //  (participant 'pending', owner approval required — see
    //  HandlesApplicationSubmission). The Discord on-ramp must NOT bypass
    //  that screening via the forgeable ?discord_join=1 flag.
    // ════════════════════════════════════════════════════

    /**
     * Build a protected game owned by $owner whose viewer is a friend of the
     * owner (so GamePolicy::view admits them — the only way a non-participant
     * can load a protected game and reach the join path).
     *
     * @return array{0: User, 1: Game, 2: User}
     */
    private function friendViewerAndProtectedGame(): array
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Protected->value,
            'status' => GameStatus::Scheduled->value,
            'max_players' => 6,
            'date_time' => now()->addDays(3),
        ]);
        $viewer = User::factory()->create(['profile_complete' => true]);
        UserRelationship::follow($viewer, $owner);
        UserRelationship::follow($owner, $viewer);

        return [$owner, $game, $viewer];
    }

    #[Test]
    public function can_join_via_discord_is_false_for_a_protected_game_even_for_an_eligible_viewer()
    {
        [$owner, $game, $viewer] = $this->friendViewerAndProtectedGame();

        $component = Livewire::actingAs($viewer)->test(GameDetail::class, ['id' => $game->id]);

        $this->assertFalse(
            $component->instance()->canJoinViaDiscord(),
            'protected games must screen via the Apply path, not bypass via the Discord on-ramp'
        );
    }

    #[Test]
    public function discord_join_flag_does_not_auto_join_a_protected_game()
    {
        // End-to-end proof of the screening gate: a friend who can LOAD a
        // protected game appends the forgeable ?discord_join=1 flag and must
        // NOT be written onto the roster. The web Apply path (Pending,
        // owner approval) is the only way onto a protected game.
        [$owner, $game, $viewer] = $this->friendViewerAndProtectedGame();

        $this->actingAs($viewer)
            ->withSession([])
            ->get(route('games.show', ['locale' => 'en', 'id' => $game->id]).'?discord_join=1')
            ->assertOk();

        $this->assertSame(
            0,
            GameParticipant::where('game_id', $game->id)->where('user_id', $viewer->id)->count(),
            'protected-game screening must not be bypassed by the forgeable discord_join flag'
        );
    }

    #[Test]
    public function discord_join_intent_is_consumed_on_arrival_even_when_the_auto_join_is_skipped()
    {
        // The intent is fulfilled the moment the member LANDS on the game, so a
        // skip (here: an existing participant) must still clear it — nothing
        // else reads it after this point, but a stale intent is dead weight.
        [$viewer, $game] = $this->viewerAndJoinableGame();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $viewer->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->actingAs($viewer)
            ->withSession([DiscordJoinIntent::KEY => ['game_id' => (string) $game->id, 'set_at' => now()->getTimestamp()]])
            ->get(route('games.show', ['locale' => 'en', 'id' => $game->id]).'?discord_join=1')
            ->assertOk();

        $this->assertNull(
            app(DiscordJoinIntent::class)->peek(request()),
            'the intent is consumed on arrival even when the auto-join is skipped'
        );
    }
}
