<?php

namespace App\Http\Controllers;

use App\Services\Discord\DiscordBotInstallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * HTTP controller for the landlord bot-install round-trip (T06).
 *
 * Two routes — both top-level (outside the locale prefix, like the login OAuth
 * routes, because Discord redirects back to a fixed callback URL):
 *   - GET /discord/install        → redirects the landlord to Discord's OAuth2
 *                                   "Add to Server" flow
 *   - GET /discord/install/callback → exchanges the code, creates the
 *                                     discord_guild row, redirects to the
 *                                     locale-prefixed GuildSettings page
 *
 * Thin by design: all Discord I/O lives in {@see DiscordBotInstallService}.
 * The controller owns only the HTTP concerns (auth gate, session carry,
 * locale-aware redirect, failure surfacing).
 */
class DiscordBotInstallController
{
    /**
     * Redirect the authenticated landlord to Discord's bot-install OAuth2 URL.
     */
    public function redirect(Request $request, DiscordBotInstallService $installService): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        // Carry the locale so the post-install redirect lands in the right
        // locale-prefixed GuildSettings route.
        $locale = session('locale', config('app.fallback_locale'));
        $request->session()->put('discord_install_locale', is_string($locale) ? $locale : 'en');

        // Anti-CSRF state + PKCE verifier: round-tripped via the callback and
        // verified there with hash_equals(). Binds the callback to the landlord
        // who initiated the install and the authorization code to this session
        // (defeats code interception / cross-site forged installs).
        $state = DiscordBotInstallService::generateState();
        $verifier = DiscordBotInstallService::generatePkceVerifier();
        $request->session()->put('discord_install_state', $state);
        $request->session()->put('discord_install_pkce_verifier', $verifier);

        $url = $installService->installUrl(
            state: $state,
            codeChallenge: DiscordBotInstallService::codeChallenge($verifier),
        );

        return redirect()->away($url);
    }

    /**
     * Handle the Discord bot-install OAuth2 callback.
     *
     * Exchanges the authorization code for the guild install, creates the
     * discord_guild row, then redirects to the GuildSettings page where the
     * landlord picks channels. On failure, redirects to the dashboard with
     * an error flash — install failures are surfaced, never swallowed.
     */
    public function callback(Request $request, DiscordBotInstallService $installService): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        // Verify the anti-CSRF state: a missing or mismatched state means the
        // callback was not initiated by this landlord's session (cross-site
        // forgery, or the session expired). Fail closed — no install.
        $expectedState = $request->session()->pull('discord_install_state', '');
        $actualState = $request->string('state')->toString();
        $expectedState = is_string($expectedState) ? $expectedState : '';

        if ($expectedState === '' || ! hash_equals($expectedState, $actualState)) {
            Log::warning('discord_bot_install.state_mismatch', [
                'user_id' => $user->id,
            ]);

            return $this->localeRedirect('/dashboard')
                ->with('error', 'Discord bot install could not be verified. Please try again.');
        }

        $verifier = $request->session()->pull('discord_install_pkce_verifier', '');
        $verifier = is_string($verifier) ? $verifier : '';

        // Discord redirects with ?error=access_denied if the landlord cancels.
        if ($request->has('error')) {
            Log::info('discord_bot_install.cancelled', [
                'user_id' => $user->id,
                'error' => $request->string('error'),
            ]);

            return $this->localeRedirect('/dashboard')
                ->with('error', 'Discord bot install was cancelled.');
        }

        $code = $request->string('code')->toString();
        $guildId = $request->string('guild_id')->toString();

        try {
            $guild = $installService->completeInstall($user, $code, $guildId, $verifier);
        } catch (\Throwable $e) {
            Log::error('discord_bot_install.failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            // Show a generic message rather than the raw exception text
            // (which can embed Discord response bodies / internals).
            return $this->localeRedirect('/dashboard')
                ->with('error', 'Could not install the roundup bot. Please try again.');
        }

        return redirect()->to(DiscordBotInstallService::settingsPath($guild))
            ->with('status', 'roundup bot installed! Pick your channels to start publishing events.');
    }

    /**
     * Build a locale-prefixed redirect path (the install callback runs
     * outside the locale route group, so route() can't resolve locale routes).
     */
    private function localeRedirect(string $path): RedirectResponse
    {
        $locale = session('discord_install_locale', session('locale', config('app.fallback_locale')));
        $locale = is_string($locale) ? $locale : 'en';

        return redirect()->to('/'.$locale.'/'.ltrim($path, '/'));
    }
}
