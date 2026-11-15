<?php

namespace Tests\Feature\Services\Discord;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Services\Discord\DiscordRsvpMenuContext;
use App\Services\Discord\DiscordRsvpMenuRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit coverage for the per-clicker RSVP menu renderer (M057 follow-up).
 *
 * The renderer is a pure transformer (MEM917) — it maps the clicker's resolved
 * roster state to an ephemeral Discord menu. These tests pin every state
 * branch: owner / each active participant status / not-joined (seats free vs
 * full) / cancelled / completed, plus the custom_ids the controller routes on.
 *
 * All copy renders from the notifications.content_rsvp_* lang keys in the context
 * locale (K004: assertions resolve the same keys — never literal pins), so
 * wording stays free to change without breaking this suite.
 */
class DiscordRsvpMenuRendererTest extends TestCase
{
    use DatabaseTransactions;

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

    // ── State matrix (structure: custom_ids, buttons, read-only) ──

    public function test_owner_gets_read_only_host_menu_with_no_action_buttons(): void
    {
        $menu = $this->renderer->render($this->game(), new DiscordRsvpMenuContext(isOwner: true, locale: 'en'));

        $this->assertSame(__('notifications.content_rsvp_hosting', [], 'en'), $menu->content);
        $this->assertSame([], $menu->components);
    }

    public function test_approved_participant_sees_their_seat_plus_leave_button(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Approved, approvedCount: 5, maxPlayers: 6, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_approved_seat', ['current' => 5, 'max' => 6], 'en'), $menu->content);
        $this->assertSame('roundup:leave:'.self::GAME_ID, $this->customId($menu->components));
        $this->assertSame(__('notifications.action_rsvp_leave', [], 'en'), $menu->components[0]['components'][0]['label']);
    }

    public function test_waitlisted_participant_sees_position_plus_leave_waitlist(): void
    {
        $menu = $this->renderer->render(
            $this->game(),
            new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Waitlisted, waitlistPosition: 2, approvedCount: 6, maxPlayers: 6, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_waitlisted_position', ['position' => 2], 'en'), $menu->content);
        $this->assertSame('roundup:leave:'.self::GAME_ID, $this->customId($menu->components));
        // Waitlist label reads the waitlist-leave key, not bare Leave
        $this->assertSame(__('notifications.action_rsvp_leave_waitlist', [], 'en'), $menu->components[0]['components'][0]['label']);
    }

    public function test_benched_participant_sees_bench_plus_leave(): void
    {
        $menu = $this->renderer->render(
            $this->game(),
            new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Benched, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_benched', [], 'en'), $menu->content);
        $this->assertSame('roundup:leave:'.self::GAME_ID, $this->customId($menu->components));
    }

    public function test_pending_participant_sees_pending_plus_leave_waitlist(): void
    {
        $menu = $this->renderer->render(
            $this->game(),
            new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Pending, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_pending', [], 'en'), $menu->content);
        $this->assertSame('roundup:leave:'.self::GAME_ID, $this->customId($menu->components));
        // Pending offers the waitlist-leave label (cancel the pending RSVP)
        $this->assertSame(__('notifications.action_rsvp_leave_waitlist', [], 'en'), $menu->components[0]['components'][0]['label']);
    }

    public function test_not_joined_with_seats_free_sees_join_button(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 4, maxPlayers: 6, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_joiner_seats_left', ['approved' => 4, 'max' => 6, 'left' => 2], 'en'), $menu->content);
        $this->assertSame('roundup:join:'.self::GAME_ID, $this->customId($menu->components));
        $this->assertSame(__('notifications.action_rsvp_join', [], 'en'), $menu->components[0]['components'][0]['label']);
    }

    public function test_not_joined_when_full_sees_join_waitlist_button(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 6, maxPlayers: 6, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_joiner_full', ['max' => 6], 'en'), $menu->content);
        $this->assertSame('roundup:join:'.self::GAME_ID, $this->customId($menu->components));
        $this->assertSame(__('notifications.action_rsvp_join_waitlist', [], 'en'), $menu->components[0]['components'][0]['label']);
    }

    public function test_not_joined_unlimited_roster_renders_open_roster_line(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 0),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 3, maxPlayers: null, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_joiner_open_roster', ['approved' => 3], 'en'), $menu->content);
        $this->assertSame('roundup:join:'.self::GAME_ID, $this->customId($menu->components));
    }

    public function test_cancelled_game_shows_no_join_button_regardless_of_roster(): void
    {
        $menu = $this->renderer->render(
            $this->game(status: GameStatus::Canceled),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 0, maxPlayers: 6, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_game_cancelled', [], 'en'), $menu->content);
        $this->assertSame([], $menu->components);
    }

    public function test_completed_game_shows_no_join_button(): void
    {
        $menu = $this->renderer->render(
            $this->game(status: GameStatus::Completed),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 6, maxPlayers: 6, locale: 'en')
        );

        $this->assertSame(__('notifications.content_rsvp_game_completed', [], 'en'), $menu->content);
        $this->assertSame([], $menu->components);
    }

    // ── Locale rendering: every state, both locales ─────

    /**
     * Full en+de sweep: each roster state renders its content from the
     * notifications.content_rsvp_* key in the clicker's locale. K004 in action — the
     * expected value is resolved from the same key the renderer uses, so
     * wording can evolve without breaking the pin.
     *
     * @return array<string, array{GameStatus, DiscordRsvpMenuContext, string, array<string, int|string>, string}>
     */
    public static function localeStateProvider(): array
    {
        $states = [
            'owner' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(isOwner: true), 'notifications.content_rsvp_hosting', []],
            'approved with seat' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Approved, approvedCount: 5, maxPlayers: 6), 'notifications.content_rsvp_approved_seat', ['current' => 5, 'max' => 6]],
            'approved open roster' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Approved, approvedCount: 3, maxPlayers: null), 'notifications.content_rsvp_approved', []],
            'waitlisted with position' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Waitlisted, waitlistPosition: 2), 'notifications.content_rsvp_waitlisted_position', ['position' => 2]],
            'waitlisted without position' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Waitlisted), 'notifications.content_rsvp_waitlisted', []],
            'benched' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Benched), 'notifications.content_rsvp_benched', []],
            'pending' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: ParticipantStatus::Pending), 'notifications.content_rsvp_pending', []],
            'joiner open roster' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 3, maxPlayers: null), 'notifications.content_rsvp_joiner_open_roster', ['approved' => 3]],
            'joiner seats free' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 4, maxPlayers: 6), 'notifications.content_rsvp_joiner_seats_left', ['approved' => 4, 'max' => 6, 'left' => 2]],
            'joiner full' => [GameStatus::Scheduled, new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 6, maxPlayers: 6), 'notifications.content_rsvp_joiner_full', ['max' => 6]],
            'cancelled game' => [GameStatus::Canceled, new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 0, maxPlayers: 6), 'notifications.content_rsvp_game_cancelled', []],
            'completed game' => [GameStatus::Completed, new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 6, maxPlayers: 6), 'notifications.content_rsvp_game_completed', []],
        ];

        $cases = [];
        foreach (['en', 'de'] as $locale) {
            foreach ($states as $label => [$status, $context, $key, $replace]) {
                $contextWithLocale = new DiscordRsvpMenuContext(
                    isOwner: $context->isOwner,
                    currentStatus: $context->currentStatus,
                    waitlistPosition: $context->waitlistPosition,
                    approvedCount: $context->approvedCount,
                    maxPlayers: $context->maxPlayers,
                    locale: $locale,
                );
                $cases["{$locale} · {$label}"] = [$status, $contextWithLocale, $key, $replace, $locale];
            }
        }

        return $cases;
    }

    #[DataProvider('localeStateProvider')]
    #[Test]
    public function every_state_renders_its_content_in_the_clickers_locale(
        GameStatus $status,
        DiscordRsvpMenuContext $context,
        string $key,
        array $replace,
        string $locale,
    ): void {
        $menu = $this->renderer->render($this->game($status, $context->maxPlayers), $context);

        $this->assertSame(__($key, $replace, $locale), $menu->content);
    }

    public function test_context_locale_null_falls_back_to_the_app_locale(): void
    {
        $appLocale = app()->getLocale();
        $menu = $this->renderer->render(
            $this->game(),
            new DiscordRsvpMenuContext(isOwner: true)
        );

        $this->assertSame(__('notifications.content_rsvp_hosting', [], $appLocale), $menu->content);
    }

    // ── Response shape ──────────────────────────────────

    public function test_to_response_shape_is_type_4_ephemeral_with_components_and_view_link(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 4, maxPlayers: 6, appUrl: 'https://roundup.example', locale: 'en')
        );

        $response = $menu->toResponse(appUrl: 'https://roundup.example', gameId: self::GAME_ID, locale: 'en');

        $this->assertSame(4, $response['type']); // CHANNEL_MESSAGE
        $this->assertSame(64, $response['data']['flags']); // EPHEMERAL flag
        // Two component rows: the Join action row + the appended View-on-roundup link row.
        $this->assertCount(2, $response['data']['components']);
        $this->assertSame('roundup:join:'.self::GAME_ID, $response['data']['components'][0]['components'][0]['custom_id']);
        $this->assertSame(__('notifications.action_rsvp_join', [], 'en'), $response['data']['components'][0]['components'][0]['label']);
        $viewRow = $response['data']['components'][1]['components'][0];
        $this->assertSame(5, $viewRow['style']); // LINK
        $this->assertSame(__('discord.content_card_button_view', [], 'en'), $viewRow['label']);
    }

    public function test_owner_menu_still_appends_view_link(): void
    {
        $menu = $this->renderer->render($this->game(), new DiscordRsvpMenuContext(isOwner: true, locale: 'en'));

        $response = $menu->toResponse(appUrl: 'https://roundup.example', gameId: self::GAME_ID, locale: 'en');

        // Owner has no action row of their own, but the View link is appended.
        $this->assertCount(1, $response['data']['components']);
        $this->assertSame(5, $response['data']['components'][0]['components'][0]['style']); // LINK
    }

    public function test_view_link_label_localizes_to_the_clickers_locale(): void
    {
        $menu = $this->renderer->render(
            $this->game(max: 6),
            new DiscordRsvpMenuContext(currentStatus: null, approvedCount: 4, maxPlayers: 6, locale: 'de')
        );

        $response = $menu->toResponse(appUrl: 'https://roundup.example', gameId: self::GAME_ID, locale: 'de');

        $viewRow = $response['data']['components'][1]['components'][0];
        $this->assertSame(__('discord.content_card_button_view', [], 'de'), $viewRow['label']);
        $this->assertSame(__('notifications.action_rsvp_join', [], 'de'), $response['data']['components'][0]['components'][0]['label']);
    }
}
