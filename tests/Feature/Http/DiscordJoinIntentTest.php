<?php

namespace Tests\Feature\Http;

use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Support\DiscordJoinIntent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M059/S02 — the Discord "My seat" on-ramp for unlinked members.
 *
 * Covers the guest route ({@see DiscordJoinController}) that records the join
 * intent and hands off to Discord OAuth, and the {@see DiscordJoinIntent}
 * session store lifecycle (set/peek/consume/expiry).
 */
class DiscordJoinIntentTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function intent_store_set_peek_consume_lifecycle_works()
    {
        $intent = app(DiscordJoinIntent::class);
        $game = Game::factory()->create();

        // Use a session-backed request (the store reads $request->session()).
        $request = tap(request(), fn ($r) => $r->setLaravelSession(app('session.store')));

        $this->assertNull($intent->peek($request), 'no intent before set');

        $intent->set($request, (string) $game->id);
        $this->assertSame((string) $game->id, $intent->peek($request), 'peek reads without clearing');
        $this->assertSame((string) $game->id, $intent->peek($request), 'peek still present (not consumed)');

        $this->assertSame((string) $game->id, $intent->consume($request), 'consume returns the id');
        $this->assertNull($intent->peek($request), 'consume cleared the intent');
    }

    #[Test]
    public function guest_join_route_for_a_public_game_sets_intent_and_redirects_to_discord_oauth()
    {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public->value,
            'status' => GameStatus::Scheduled->value,
        ]);

        $response = $this->get(route('discord.join', ['game' => $game->id]));

        $response->assertRedirect();
        $this->assertStringContainsString('/auth/discord/redirect', $response->headers->get('Location'));
        $this->assertSame((string) $game->id, app(DiscordJoinIntent::class)->peek(request()));
    }

    #[Test]
    public function guest_join_route_for_a_non_public_game_falls_back_to_login_without_intent()
    {
        $private = Game::factory()->create(['visibility' => Visibility::Private->value]);

        $response = $this->get(route('discord.join', ['game' => $private->id]));

        $response->assertRedirect();
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
        $this->assertNull(app(DiscordJoinIntent::class)->peek(request()));
    }

    #[Test]
    public function guest_join_route_for_an_unknown_game_falls_back_to_login_without_intent()
    {
        $missingId = fake()->uuid();

        $response = $this->get(route('discord.join', ['game' => $missingId]));

        $response->assertRedirect();
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
        $this->assertNull(app(DiscordJoinIntent::class)->peek(request()));
    }

    #[Test]
    public function target_url_points_at_the_game_detail_page_with_the_discord_join_flag()
    {
        $gameId = fake()->uuid();
        $url = app(DiscordJoinIntent::class)->targetUrl($gameId);

        $this->assertStringContainsString('/dashboard/games/'.$gameId, $url);
        $this->assertStringContainsString('discord_join=1', $url);
    }
}
