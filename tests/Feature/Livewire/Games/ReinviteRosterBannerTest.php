<?php

use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\RosterReinviteInvitation;
use App\Services\OwnerParticipantService;
use App\Services\ParticipantLifecycle;
use App\Services\ParticipantService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

//
// Guards the "Invite the previous roster" banner on the game detail page
// (M084/S06-T03, D320 + D321). The banner is offered only to the host of a
// game that replaces a cancelled one, and only while there is someone left to
// invite. Tests pin lang keys, never wording (K004).
//

// ── Helpers ──────────────────────────────────────────────

function rosterBannerHost(): User
{
    return User::factory()->create(['profile_complete' => true]);
}

/**
 * A cancelled one-shot with three approved players, and the Scheduled game that
 * replaces it. Approvals run through the real lifecycle so they carry approved_at.
 *
 * @param  array<string, mixed>  $replacement
 * @return array{host: User, predecessor: Game, replacement: Game, players: Collection<int, User>}
 */
function rosterBannerFixture(array $replacement = [], bool $cancel = true): array
{
    $host = rosterBannerHost();
    $system = GameSystem::factory()->create();

    $old = Game::factory()->withGameSystems([$system->id])->create([
        'owner_id' => $host->id,
        'name' => ['en' => 'Cancelled session'],
        'max_players' => 10,
    ]);

    $new = Game::factory()->withGameSystems([$system->id])->create(array_merge([
        'owner_id' => $host->id,
        'name' => ['en' => 'Replacement session'],
        'max_players' => 10,
        'recreates_game_id' => $old->id,
    ], $replacement));

    app(OwnerParticipantService::class)->ensureOwnerParticipant($new);

    $players = User::factory()->count(3)->create();

    foreach ($players as $player) {
        $pending = GameParticipant::factory()->create([
            'game_id' => $old->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
            'join_source' => JoinSource::FriendInvite,
        ]);

        app(ParticipantLifecycle::class)->acceptInvitation($pending, $old, $player);
    }

    if ($cancel) {
        $old->update(['status' => GameStatus::Canceled->value]);
    }

    return ['host' => $host, 'predecessor' => $old, 'replacement' => $new, 'players' => $players];
}

function rosterBannerMounted(?User $as, Game $game)
{
    // Livewire::actingAs(null) is not a guest — only a plain test() is.
    return $as === null ? Livewire::test(GameDetail::class, ['id' => $game->id])
        : Livewire::actingAs($as)->test(GameDetail::class, ['id' => $game->id]);
}

// ═══════════════════════════════════════════════════════════
// WHO SEES THE BANNER
// ═══════════════════════════════════════════════════════════

describe('roster re-invite banner', function () {
    it('offers the previous roster to the host of a linked replacement', function () {
        ['host' => $host, 'replacement' => $new] = rosterBannerFixture();

        rosterBannerMounted($host, $new)
            ->assertOk()
            ->assertSee(__('games.action_roster_reinvite'))
            ->assertSee(trans_choice('games.content_roster_reinvite', 3, ['count' => 3, 'replaced' => 'Cancelled session']))
            ->assertSet('canReinviteRoster', true)
            ->assertSet('reinvitableRosterCount', 3);
    });

    it('never offers the previous roster to anyone but the host', function (string $viewer) {
        ['host' => $host, 'replacement' => $new, 'players' => $players] = rosterBannerFixture();

        $as = match ($viewer) {
            'a former player' => $players->first(),
            'a stranger' => User::factory()->create(),
            default => null,
        };

        unset($host);

        rosterBannerMounted($as, $new)
            ->assertOk()
            ->assertDontSee(__('games.action_roster_reinvite'));
    })->with(['guest', 'a former player', 'a stranger']);

    it('hides the banner once every former player has been invited', function () {
        Notification::fake();
        ['host' => $host, 'replacement' => $new] = rosterBannerFixture();

        app(ParticipantService::class)->inviteFormerRoster($new, $host);

        rosterBannerMounted($host, $new)
            ->assertOk()
            ->assertDontSee(__('games.action_roster_reinvite'));
    });

    it('hides the banner when the game has no free seat', function () {
        ['host' => $host, 'replacement' => $new] = rosterBannerFixture(['max_players' => 1]);

        rosterBannerMounted($host, $new)
            ->assertOk()
            ->assertDontSee(__('games.action_roster_reinvite'));
    });

    it('hides the banner while the replaced game is still running', function () {
        ['host' => $host, 'replacement' => $new] = rosterBannerFixture(cancel: false);

        rosterBannerMounted($host, $new)
            ->assertOk()
            ->assertDontSee(__('games.action_roster_reinvite'));
    });
});

// ═══════════════════════════════════════════════════════════
// THE ACTION
// ═══════════════════════════════════════════════════════════

describe('reinviteRoster', function () {
    it('invites the previous roster and reports the outcome', function () {
        Notification::fake();
        ['host' => $host, 'replacement' => $new, 'players' => $players] = rosterBannerFixture();

        rosterBannerMounted($host, $new)
            ->call('reinviteRoster')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertDontSee(__('games.action_roster_reinvite'))
            // The result flash is what the host reads — asserted through the lang key, never the wording.
            ->assertSee(__('games.flash_roster_reinvite', ['invited' => 3, 'skipped' => 0]));

        foreach ($players as $player) {
            Notification::assertSentTo($player, RosterReinviteInvitation::class);
        }

        Notification::assertNotSentTo($host, RosterReinviteInvitation::class);
    });

    it('says why players were left out when the game is full', function () {
        Notification::fake();
        // The host holds one of three seats, so two are free.
        ['host' => $host, 'replacement' => $new] = rosterBannerFixture(['max_players' => 3]);

        rosterBannerMounted($host, $new)
            ->call('reinviteRoster')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(__('games.flash_roster_reinvite', ['invited' => 2, 'skipped' => 1]).' '.__('games.flash_roster_reinvite_reason_capacity'));
    });

    it('refuses the action from anyone but the host', function () {
        ['replacement' => $new] = rosterBannerFixture();

        rosterBannerMounted(User::factory()->create(), $new)
            ->call('reinviteRoster')
            ->assertForbidden();
    });

    it('reports an unavailable roster instead of failing when the game replaces nothing', function () {
        $host = rosterBannerHost();
        $unlinked = Game::factory()->create(['owner_id' => $host->id]);

        rosterBannerMounted($host, $unlinked)
            ->call('reinviteRoster')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(__('games.error_roster_reinvite_unavailable'));
    });

    it('lets the host dismiss the banner', function () {
        ['host' => $host, 'replacement' => $new] = rosterBannerFixture();

        rosterBannerMounted($host, $new)
            ->assertSee(__('games.action_roster_reinvite'))
            ->call('dismissRosterReinviteBanner')
            ->assertOk()
            ->assertSet('rosterReinviteBannerDismissed', true)
            ->assertDontSee(__('games.action_roster_reinvite'));
    });
});
