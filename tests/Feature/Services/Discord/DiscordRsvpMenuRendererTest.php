<?php

namespace Tests\Feature\Services\Discord;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Services\Discord\DiscordRsvpMenuContext;
use App\Services\Discord\DiscordRsvpMenuRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit coverage for the per-clicker RSVP menu renderer (M057 follow-up).
 *
 * The renderer is a pure transformer (MEM917) — it maps the clicker's resolved
 * roster state to an ephemeral Discord menu. These tests pin every state
 * branch: owner / each active participant status / not-joined (seats free vs
 * full) / canceled / completed, plus the custom_ids the controller routes on.
 */
class DiscordRsvpMenuRendererTest extends TestCase
{
    use RefreshDatabase;

    private const GAME_ID = 'game-menu-001';

    private DiscordRsvpMenuRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new DiscordRsvpMenuRenderer;
    }

    private function game(GameStatus $status = GameStatus::Scheduled, ?int $max = 6): Game
    {
        $g = new Game;
        $g->forceFill([
            'id' => self::GAME_ID,
            'name' => 'Catan Night',
            'status' => $status,
            'max_players' => $max,
            'date_time' => Carbon::parse('2026-07-30 19:00:00'),
        ])->exists = true;

        return $g;
    }

    private function customId(array $components): ?string
    {
        foreach ($components as $row) {
            foreach ($row['components'] ?? [] as $button) {
                if (isset($button['custom_id'])) {
                    return $button['custom_id'];
                }
            }
        }

        return null;
    }

    public function test_owner_gets_read_only_host_menu_with_no_action_buttons(): void
    {
        $menu = $this->renderer->render($this->game(), new DiscordRsvpMenuContext(isOwner: true));

        $this->assertStringContainsString("You're hosting", $menu->content);
        $this->assertSame([], $menu->components);
    }

    public function test_approved_participant_sees_their_seat_plus_leave_button(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Approved, approvedCount: 5, maxPlayers: 6)
        );

        $this->assertStringContainsString("You're in", $menu->content);
        $this->assertStringContainsString('seat 5 of 6', $menu->content);
        $this->assertSame('roundup:leave:'.self::GAME_ID, $this->customId($menu->components));
    }

    public function test_waitlisted_participant_sees_position_plus_leave_waitlist(): void
    {
        $menu = $this->renderer->render(
            $this->game(),
            new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Waitlisted, waitlistPosition: 2, approvedCount: 6, maxPlayers: 6)
        );

        $this->assertStringContainsString('#2 on the waitlist', $menu->content);
        $this->assertSame('roundup:leave:'.self::GAME_ID, $this->customId($menu->components));
        // Waitlist label reads "Leave waitlist", not bare "Leave"
        $this->assertStringContainsString('Leave waitlist', $menu->components[0]['components'][0]['label']);
    }

    public function test_benched_participant_sees_bench_plus_leave(): void
    {
        $menu = $this->renderer->render(
            $this->game(),
            new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Benched)
        );

        $this->assertStringContainsString('bench', $menu->content);
        $this->assertSame('roundup:leave:'.self::GAME_ID, $this->customId($menu->components));
    }

    public function test_pending_participant_sees_pending_plus_leave_waitlist(): void
    {
        $menu = $this->renderer->render(
            $this->game(),
            new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Pending)
        );

        $this->assertStringContainsString('pending', $menu->content);
        $this->assertSame('roundup:leave:'.self::GAME_ID, $this->customId($menu->components));
    }

    public function test_not_joined_with_seats_free_sees_join_button(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 4, maxPlayers: 6)
        );

        $this->assertStringContainsString('4/6 seats', $menu->content);
        $this->assertStringContainsString('2 left', $menu->content);
        $this->assertSame('roundup:join:'.self::GAME_ID, $this->customId($menu->components));
        $this->assertSame('✅ Join', $menu->components[0]['components'][0]['label']);
    }

    public function test_not_joined_when_full_sees_join_waitlist_button(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 6, maxPlayers: 6)
        );

        $this->assertStringContainsString('6/6 seats', $menu->content);
        $this->assertStringContainsString('waitlist', $menu->content);
        $this->assertSame('roundup:join:'.self::GAME_ID, $this->customId($menu->components));
        $this->assertSame('Join waitlist', $menu->components[0]['components'][0]['label']);
    }

    public function test_not_joined_unlimited_roster_renders_open_roster_line(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 0),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 3, maxPlayers: null)
        );

        $this->assertStringContainsString('open roster', $menu->content);
        $this->assertSame('roundup:join:'.self::GAME_ID, $this->customId($menu->components));
    }

    public function test_canceled_game_shows_no_join_button_regardless_of_roster(): void
    {
        $menu = $this->renderer->render(
            $this->game(status: GameStatus::Canceled),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 0, maxPlayers: 6)
        );

        $this->assertStringContainsString('canceled', $menu->content);
        $this->assertSame([], $menu->components);
    }

    public function test_completed_game_shows_no_join_button(): void
    {
        $menu = $this->renderer->render(
            $this->game(status: GameStatus::Completed),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 6, maxPlayers: 6)
        );

        $this->assertStringContainsString('completed', $menu->content);
        $this->assertSame([], $menu->components);
    }

    public function test_to_response_shape_is_type_4_ephemeral_with_components_and_view_link(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 4, maxPlayers: 6, appUrl: 'https://roundup.example')
        );

        $response = $menu->toResponse(appUrl: 'https://roundup.example', gameId: self::GAME_ID);

        $this->assertSame(4, $response['type']); // CHANNEL_MESSAGE
        $this->assertSame(64, $response['data']['flags']); // EPHEMERAL flag
        // Two component rows: the Join action row + the appended View-on-roundup link row.
        $this->assertCount(2, $response['data']['components']);
        $this->assertSame('roundup:join:'.self::GAME_ID, $response['data']['components'][0]['components'][0]['custom_id']);
        $this->assertSame(5, $response['data']['components'][1]['components'][0]['style']); // LINK
    }

    public function test_owner_menu_still_appends_view_link(): void
    {
        $menu = $this->renderer->render($this->game(), new DiscordRsvpMenuContext(isOwner: true));

        $response = $menu->toResponse(appUrl: 'https://roundup.example', gameId: self::GAME_ID);

        // Owner has no action row of their own, but the View link is appended.
        $this->assertCount(1, $response['data']['components']);
        $this->assertSame(5, $response['data']['components'][0]['components'][0]['style']); // LINK
    }
}
