<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Support\DiscordJoinIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Entry point for the Discord "My seat" on-ramp for unlinked members (M059/S02).
 *
 * The ephemeral "Link Discord to grab your seat" button rendered by
 * {@see DiscordInteractionController::unlinkedDeepLink()} deep-links here. This
 * controller records the targeted game as a session intent, then redirects to
 * the Discord OAuth redirect — so the game id survives the OAuth round-trip
 * (register / login) and the (minimal) onboarding that follows, and the member
 * lands on the game primed to join.
 *
 * Guest-accessible: the whole point is to onboard an UNLINKED member, so no auth
 * middleware. A non-existent or non-public game clears any intent and falls back
 * to a safe landing so a stale/dead button can never strand the member.
 */
class DiscordJoinController extends Controller
{
    public function __construct(
        private readonly DiscordJoinIntent $intent,
    ) {}

    /**
     * Record the join intent and redirect to Discord OAuth.
     *
     * @param  string  $game  The roundup Game id (UUID) from the URL.
     */
    public function __invoke(Request $request, string $game): RedirectResponse
    {
        // Only carry intent for a real, public game — a dead/stale/private id
        // should never strand the member at the end of the flow.
        $valid = Game::where('id', $game)->public()->exists();

        if (! $valid) {
            Log::info('discord_join_intent.invalid_game', [
                'game_id' => $game,
            ]);
            $this->intent->consume($request);

            // Fall back to the login page (a guest with nowhere better to go).
            $locale = $this->resolveLocale($request);

            return redirect('/'.$locale.'/login');
        }

        $this->intent->set($request, $game);

        Log::info('discord_join_intent.set', [
            'game_id' => $game,
        ]);

        // Hand off to the Discord OAuth redirect. Socialite handles the
        // provider round-trip; the callback consumes the intent.
        return redirect()->route('oauth.redirect', ['provider' => 'discord']);
    }

    private function resolveLocale(Request $request): string
    {
        $locale = $request->session()->get('locale', config('app.fallback_locale'));

        return is_string($locale) && Str::length($locale) >= 2 ? $locale : 'en';
    }
}
