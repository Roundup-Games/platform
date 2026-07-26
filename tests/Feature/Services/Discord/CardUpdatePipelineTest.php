<?php

namespace Tests\Feature\Services\Discord;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Jobs\RefreshDiscordCard;
use App\Livewire\Games\GamesPage;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration closure for M057/S04: proves the silent-vs-material card-update
 * split composes end-to-end against the real publisher + an Http::fake Discord
 * REST surface.
 *
 *   SILENT  — roster churn (a join) → GameParticipantObserver → debounced
 *             RefreshDiscordCard → DiscordPublisher::publish edit-in-place
 *             PATCH, with ZERO attendee notifications.
 *   MATERIAL — a Game edit via GamesPage::saveGameEdit → GameObserver::saved
 *             → PublishGameToDiscord → card PATCH AND EntityUpdated to the
 *             approved attendee.
 *   CANCEL  — GamesPage::cancelGame → red card (Canceled color) PATCH AND
 *             EntityCancelled to the approved attendee.
 *
 * The material/cancel halves are existing machinery (S01's GameObserver →
 * PublishGameToDiscord + the notification layer's EntityUpdated/EntityCancelled
 * dispatch via NotificationService). This test is the proof-level integration
 * closure: it proves BOTH halves compose AND that the new debounced
 * roster-churn path (T01's RefreshDiscordCard job + T02's observer hook)
 * produces a card edit WITHOUT spurious attendee pings — the slice's defining
 * contract.
 *
 * All Discord REST traffic is intercepted by Http::fake; the webhook client +
 * publisher auto-resolve from the container reading the test api_base_url. The
 * queue runs sync (phpunit.xml), so both RefreshDiscordCard and
 * PublishGameToDiscord execute inline when dispatched.
 */
class CardUpdatePipelineTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const CARD_MESSAGE_ID = '999888777666555444';

    /**
     * The Canceled embed color the renderer emits (DiscordCardRenderer::COLOR_CANCELED).
     * Asserted as a decimal int in the PATCH body's embed.
     */
    private const COLOR_CANCELED = 0xE74C3C;

    protected function setUp(): void
    {
        parent::setUp();

        // publishing defaults OFF (MEM918) so model creation in fixtures does
        // not fire any Discord dispatch; each test flips it on for the action
        // under test, isolating the path it asserts.
        config([
            'services.discord.api_base_url' => self::BASE_URL,
            'services.discord.bot_token' => 'test-bot-token',
            'services.discord.publishing_enabled' => false,
        ]);
    }

    // ════════════════════════════════════════════════════
    //  SILENT: roster churn → debounced card refresh, no attendee ping
    // ════════════════════════════════════════════════════

    #[Test]
    public function roster_churn_silently_edits_the_card_in_place_and_pings_no_attendees(): void
    {
        [$game, $guild, $owner] = $this->gameWithPostedCard();

        // An existing approved attendee — the person a MATERIAL change would
        // ping. Roster churn must leave their inbox silent.
        $attendee = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $attendee->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Arm the real pipeline for the action under test.
        $this->enablePublishing();
        Notification::fake();
        $sent = [];
        $this->recordingFake($sent);

        // Roster churn: a NEW participant joins. GameParticipantObserver::created
        // dispatches the debounced RefreshDiscordCard; under the sync queue the
        // job runs inline → DiscordPublisher::publish edit-in-place PATCH.
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // 1) The card was refreshed via an edit-in-place PATCH on the existing
        //    message — never a duplicate POST.
        $patch = collect($sent)->first(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/channels/'.$guild->games_channel_id.'/messages/'.self::CARD_MESSAGE_ID));
        $this->assertNotNull($patch, 'Roster churn must refresh the card via an edit-in-place PATCH.');

        // 2) The roster refresh produced ZERO material-change attendee
        //    notifications — the defining silent-vs-material split.
        Notification::assertNotSentTo($attendee, EntityUpdated::class);
        Notification::assertNotSentTo($attendee, EntityCancelled::class);
    }

    // ════════════════════════════════════════════════════
    //  MATERIAL: a Game edit → card PATCH AND EntityUpdated
    // ════════════════════════════════════════════════════

    #[Test]
    public function a_material_game_edit_updates_the_card_and_notifies_the_approved_attendee(): void
    {
        [$game, $guild, $owner] = $this->gameWithPostedCard();

        $attendee = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $attendee->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->enablePublishing();
        Notification::fake();
        $sent = [];
        $this->recordingFake($sent);

        // Material change through the real Livewire machinery (the existing
        // notification path): GamesPage::saveGameEdit saves the Game →
        // GameObserver::saved dispatches PublishGameToDiscord (card PATCH) AND
        // dispatches EntityUpdated to the approved attendee via NotificationService.
        $newName = 'Material-Change-'.uniqid();
        Livewire::actingAs($owner)
            ->test(GamesPage::class)
            ->call('editGame', (string) $game->id)
            ->set('edit_name', $newName)
            ->call('saveGameEdit');

        // Diagnostic + contract: the material edit persisted.
        $this->assertSame($newName, $game->fresh()->name, 'The material name edit must persist.');

        // 1) Card updated in place.
        $patch = collect($sent)->first(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/channels/'.$guild->games_channel_id.'/messages/'.self::CARD_MESSAGE_ID));
        $this->assertNotNull($patch, 'A material edit must update the card via an edit-in-place PATCH.');

        // 2) The approved attendee WAS actively notified of the material change
        //    (the mirror image of the silent roster-churn case above).
        Notification::assertSentTo(
            $attendee,
            EntityUpdated::class,
            fn (EntityUpdated $n) => in_array(__('games.field_name'), $n->changedFields, true)
        );
    }

    // ════════════════════════════════════════════════════
    //  CANCEL: cancel → red card AND EntityCancelled
    // ════════════════════════════════════════════════════

    #[Test]
    public function cancelling_a_game_turns_the_card_red_and_notifies_the_approved_attendee(): void
    {
        [$game, $guild, $owner] = $this->gameWithPostedCard();

        $attendee = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $attendee->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->enablePublishing();
        Notification::fake();
        $sent = [];
        $this->recordingFake($sent);

        // Cancel via the real Livewire flow: GamesPage::cancelGame saves the
        // Game with status=Canceled → GameObserver::saved → PublishGameToDiscord
        // re-renders the card red (DiscordCardRenderer maps Canceled →
        // COLOR_CANCELED) AND dispatches EntityCancelled to approved attendees.
        Livewire::actingAs($owner)
            ->test(GamesPage::class)
            ->call('cancelGame', (string) $game->id);

        // 1) The card was re-published with the Canceled (red) embed color.
        $patch = collect($sent)->first(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/channels/'.$guild->games_channel_id.'/messages/'.self::CARD_MESSAGE_ID));
        $this->assertNotNull($patch, 'A cancel must update the card via a PATCH.');

        $body = json_decode($patch->body(), true);
        $color = $body['embeds'][0]['color'] ?? null;
        $this->assertSame(
            self::COLOR_CANCELED,
            $color,
            'A cancelled game must re-render the card with the Canceled (red) embed color.'
        );

        // 2) The approved attendee was notified of the cancellation.
        Notification::assertSentTo($attendee, EntityCancelled::class);

        // 3) The game is recorded as canceled (status is cast to the enum).
        $this->assertSame(GameStatus::Canceled, $game->fresh()->status);
    }

    // ════════════════════════════════════════════════════
    //  COMPOSITION: silent refresh and material publish share one chokepoint
    // ════════════════════════════════════════════════════

    #[Test]
    public function a_silent_refresh_and_a_material_publish_both_route_through_the_same_edit_in_place_chokepoint(): void
    {
        // The slice contract: RefreshDiscordCard (roster churn) and
        // PublishGameToDiscord (material change) both call the SAME
        // DiscordPublisher::publish() edit-in-place chokepoint, so they compose
        // idempotently. Prove it by running both against the same game + card
        // and asserting each lands an edit-in-place PATCH (status: edited),
        // neither producing a duplicate POST.
        [$game, $guild, $owner] = $this->gameWithPostedCard();

        $this->enablePublishing();
        $sent = [];
        $this->recordingFake($sent);

        // (a) Material change path: PublishGameToDiscord via GameObserver::saved.
        $game->update(['description' => 'Refreshed by material publish']);

        // (b) Roster-churn path: RefreshDiscordCard dispatched directly (the job
        //     the observer hook fires), running inline under sync.
        RefreshDiscordCard::dispatch((string) $game->id);

        // Both paths PATCHed the same existing card in place.
        $patches = collect($sent)->filter(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/channels/'.$guild->games_channel_id.'/messages/'.self::CARD_MESSAGE_ID));
        $this->assertGreaterThanOrEqual(2, $patches->count(), 'Both the material publish and the roster refresh must edit the card in place.');

        // Neither path produced a duplicate POST (the edit-in-place contract):
        // only the seeded card exists.
        $this->assertSame(
            1,
            DiscordCardMessage::where('game_id', $game->id)->count(),
            'No duplicate card was created — both paths edited in place.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function enablePublishing(): void
    {
        config(['services.discord.publishing_enabled' => true]);
    }

    /**
     * Http::fake stub: 200 everywhere, recording every sent request into $sent.
     *
     * @param  array<int, Request>  $sent
     */
    private function recordingFake(array &$sent): void
    {
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = $request;

            return Http::response(['id' => self::CARD_MESSAGE_ID], 200);
        });
    }

    /**
     * Build a public Game owned by an opted-in organizer in a configured guild,
     * with a card already tracked (so publish() takes the edit-in-place PATCH
     * branch). The guild's games_channel_id matches the card's channel by
     * construction, so the publisher never takes the reconfigure DELETE+POST
     * branch (the edit-in-place contract under test).
     *
     * @return array{0: Game, 1: DiscordGuild, 2: User}
     */
    private function gameWithPostedCard(): array
    {
        // Grant can_create_public_entries so the GamesPage::saveGameEdit
        // visibility gate does not silently downgrade public→protected (which
        // would route the publisher to an unpublish DELETE rather than the
        // edit-in-place PATCH this test asserts). The material-change path under
        // test keeps the game public so the card is refreshed in place.
        $owner = User::factory()->create(['can_create_public_entries' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $guild = DiscordGuild::factory()->configured()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);
        DiscordGuildOrganizer::factory()
            ->optedIn()
            ->create(['guild_id' => $guild->id, 'user_id' => $owner->id]);

        DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => $guild->games_channel_id,
            'message_id' => self::CARD_MESSAGE_ID,
        ]);

        return [$game, $guild, $owner];
    }
}
