<?php

namespace App\Http\Controllers\Auth;

use App\Http\Middleware\CaptureFirstTouch;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Rules\ValidUserName;
use App\Services\PendingInvitationMatcher;
use App\Services\PostHogAnalytics;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController
{
    /**
     * Build a locale-prefixed URL for redirects outside the locale route group.
     * OAuth routes run outside the {locale} prefix, so route() helpers
     * cannot resolve locale-dependent routes.
     */
    private function localeUrl(string $path): string
    {
        $locale = session('locale', config('app.fallback_locale'));
        $locale = is_string($locale) ? $locale : 'en';

        return '/'.$locale.'/'.ltrim($path, '/');
    }

    /**
     * Redirect the user to the OAuth provider's authentication page.
     *
     * @return RedirectResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirect(Request $request, string $provider)
    {
        if (! in_array($provider, ['google'])) {
            return redirect($this->localeUrl('login'))->withErrors(['oauth' => 'Unsupported login provider.']);
        }

        // If the user is already logged in, they're linking an account
        if ($request->user()) {
            $request->session()->put('oauth_linking', true);
        }

        // First-touch acquisition context (landing page + referer) is captured
        // for guests by the CaptureFirstTouch middleware on public-content GETs.
        // Do NOT capture here: this request's path is the auth redirect endpoint
        // and its referer is the provider's domain — meaningless as attribution.
        // The callback reads the persisted first-touch after the OAuth round-trip.

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the OAuth provider callback — login, register, or link account.
     *
     * @return RedirectResponse
     */
    public function callback(Request $request, string $provider)
    {
        if (! in_array($provider, ['google'])) {
            return redirect($this->localeUrl('login'))->withErrors(['oauth' => 'Unsupported login provider.']);
        }

        // Consume first-touch attribution ONCE at the top of the callback, before
        // any branching (existing-user login, linking, new registration, error) —
        // otherwise stale keys could be applied to a later signup. The values
        // were captured on the original landing page by CaptureFirstTouch.
        $session = $request->session();
        $firstTouchReferer = is_string($session->get(CaptureFirstTouch::REFERER_KEY)) ? $session->get(CaptureFirstTouch::REFERER_KEY) : null;
        $firstTouchPath = is_string($session->get(CaptureFirstTouch::PATH_KEY)) ? $session->get(CaptureFirstTouch::PATH_KEY) : null;
        $session->forget([CaptureFirstTouch::REFERER_KEY, CaptureFirstTouch::PATH_KEY, CaptureFirstTouch::CAPTURED_KEY]);

        try {
            /** @var \Laravel\Socialite\Two\User $socialiteUser */
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            report($e);
            Log::warning('OAuth callback failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect($this->localeUrl('login'))->withErrors(['oauth' => 'Unable to authenticate with '.ucfirst($provider).'. Please try again.']);
        }

        $providerUserId = $socialiteUser->getId();
        $isLinking = $request->session()->pull('oauth_linking', false);

        // ── Account linking flow (logged-in user connecting a provider) ──
        if ($isLinking) {
            $currentUser = $request->user();
            if (! $currentUser) {
                return redirect($this->localeUrl('login'));
            }

            return $this->linkAccount($currentUser, $provider, $socialiteUser, $providerUserId);
        }

        // ── Standard login/register flow ──

        // Look for an existing linked account first
        $linkedAccount = LinkedAccount::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($linkedAccount) {
            // Existing linked account — log the user in.
            // Use a raw DB update to overwrite encrypted tokens. After an
            // APP_KEY rotation, Eloquent's update() crashes because getDirty()
            // tries to decrypt old token values with the new key (DecryptException:
            // "The MAC is invalid"). A direct DB update bypasses model casts
            // and encrypts with the current key via encrypt().
            // Use encryptString (not encrypt) to match the Eloquent encrypted cast,
            // which does not serialize/unserialize. Using encrypt() would cause
            // double-serialization that the cast cannot correctly reverse.
            \DB::table('linked_accounts')->where('id', $linkedAccount->id)->update([
                'token' => \Crypt::encryptString($socialiteUser->token),
                'refresh_token' => $socialiteUser->refreshToken
                    ? \Crypt::encryptString($socialiteUser->refreshToken)
                    : null,
                'token_expires_at' => null,
                'provider_meta' => json_encode([
                    'nickname' => $socialiteUser->getNickname(),
                    'avatar' => $socialiteUser->getAvatar(),
                ]),
                'updated_at' => now(),
            ]);

            $linkedAccount->refresh();

            $user = $linkedAccount->user;

            if (! $user) {
                Log::error('OAuth login — linked account has no associated user', [
                    'provider' => $provider,
                    'linked_account_id' => $linkedAccount->id,
                ]);

                return redirect($this->localeUrl('login'))->withErrors(['oauth' => 'Authentication failed. Please try again.']);
            }

            if ($socialiteUser->getAvatar() && ! $user->avatar_url) {
                $user->update(['avatar_url' => $socialiteUser->getAvatar()]);
            }

            Auth::login($user);
            Log::info('OAuth login via linked account', [
                'provider' => $provider,
                'user_id' => $user->id,
                'provider_user_id' => $providerUserId,
            ]);

            return $this->redirectAfterLogin($user);
        }

        // No linked account — try matching by email
        $user = User::where('email', $socialiteUser->getEmail())->first();

        if ($user) {
            // Existing user by email — create linked account
            $this->createLinkedAccount($user, $provider, $socialiteUser, $providerUserId);

            if ($socialiteUser->getAvatar() && ! $user->avatar_url) {
                $user->update(['avatar_url' => $socialiteUser->getAvatar()]);
            }

            Auth::login($user);
            Log::info('OAuth login — email matched, account linked', [
                'provider' => $provider,
                'user_id' => $user->id,
                'provider_user_id' => $providerUserId,
            ]);

            return $this->redirectAfterLogin($user);
        }

        // New user — register and link (no password — OAuth-only until they set one)
        $rawName = $socialiteUser->getName() ?? Str::before((string) $socialiteUser->getEmail(), '@');
        $sanitizedName = ValidUserName::sanitize($rawName);

        $user = User::create([
            'name' => $sanitizedName,
            'email' => $socialiteUser->getEmail(),
            'password' => null,
            'email_verified_at' => now(),
            'avatar_url' => $socialiteUser->getAvatar(),
            'profile_complete' => false,
            'slug' => User::generateUniqueSlug($sanitizedName),
        ]);

        $this->createLinkedAccount($user, $provider, $socialiteUser, $providerUserId);

        Auth::login($user);
        Log::info('OAuth registration — new user created', [
            'provider' => $provider,
            'user_id' => $user->id,
            'provider_user_id' => $providerUserId,
        ]);

        // Match pending email invitations to the newly registered user — the
        // same logic the email-registration flow uses, so OAuth signups land on
        // their invitations and the funnel metric is comparable across methods.
        $inviteMatches = app(PendingInvitationMatcher::class)->match($user);

        // Acquisition funnel: capture the OAuth signup with provider attribution.
        $analytics = app(PostHogAnalytics::class);
        $analytics->capture(
            $user,
            'user.signed_up',
            [
                'signup_method' => 'oauth',
                'oauth_provider' => $provider,
                'invite_match_count' => $inviteMatches,
                'locale' => app()->getLocale(),
            ],
        );

        // First-touch SEO attribution: the landing page + referer captured by
        // CaptureFirstTouch on the original public-page request. identifyFirstTouch
        // reduces the referer to a domain and detects content context from the path.
        $analytics->identifyFirstTouch($user, $firstTouchReferer, $firstTouchPath);

        return $this->redirectAfterLogin($user);
    }

    /**
     * Link a provider to an already-authenticated user's account.
     */
    private function linkAccount(User $user, string $provider, \Laravel\Socialite\Two\User $socialiteUser, string $providerUserId): RedirectResponse
    {
        // Use firstOrCreate for atomicity — the unique constraint on
        // (provider, provider_user_id) prevents race-condition duplicates.
        $linkedAccount = LinkedAccount::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($linkedAccount && (string) $linkedAccount->user_id !== (string) $user->id) {
            Log::warning('OAuth link attempted — provider already linked to another user', [
                'provider' => $provider,
                'user_id' => $user->id,
                'existing_user_id' => $linkedAccount->user_id,
                'provider_user_id' => $providerUserId,
            ]);

            return redirect($this->localeUrl('profile/view'))
                ->withErrors(['oauth' => 'This '.ucfirst($provider).' account is already linked to another user.']);
        }

        // Already linked to this user
        if ($linkedAccount) {
            return redirect($this->localeUrl('profile/view'))
                ->with('status', ucfirst($provider).' account is already linked.');
        }

        // Atomically create via firstOrCreate to prevent race conditions
        try {
            $user->linkedAccounts()->create([
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken,
                'token_expires_at' => null,
                'provider_meta' => [
                    'nickname' => $socialiteUser->getNickname(),
                    'avatar' => $socialiteUser->getAvatar(),
                ],
            ]);
        } catch (QueryException $e) {
            // Unique constraint violation — another request beat us to it
            if (str_contains($e->getMessage(), 'Unique violation') || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                Log::warning('OAuth link race — provider already linked (caught by unique constraint)', [
                    'provider' => $provider,
                    'user_id' => $user->id,
                    'provider_user_id' => $providerUserId,
                ]);

                return redirect($this->localeUrl('profile/view'))
                    ->withErrors(['oauth' => 'This '.ucfirst($provider).' account is already linked.']);
            }

            throw $e;
        }

        Log::info('OAuth account linked', [
            'provider' => $provider,
            'user_id' => $user->id,
            'provider_user_id' => $providerUserId,
        ]);

        return redirect($this->localeUrl('profile/view'))
            ->with('status', ucfirst($provider).' account linked successfully.');
    }

    /**
     * Create a LinkedAccount record from OAuth data.
     */
    private function createLinkedAccount(User $user, string $provider, \Laravel\Socialite\Two\User $socialiteUser, string $providerUserId): LinkedAccount
    {
        return $user->linkedAccounts()->create([
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'token' => $socialiteUser->token,
            'refresh_token' => $socialiteUser->refreshToken,
            'token_expires_at' => null,
            'provider_meta' => [
                'nickname' => $socialiteUser->getNickname(),
                'avatar' => $socialiteUser->getAvatar(),
            ],
        ]);
    }

    /**
     * Determine where to redirect after login based on profile state.
     */
    private function redirectAfterLogin(User $user): RedirectResponse
    {
        $locale = session('locale', config('app.fallback_locale'));
        $locale = is_string($locale) ? $locale : 'en';

        if (! $user->profile_complete) {
            return redirect()->to('/'.$locale.'/onboarding');
        }

        return redirect()->intended('/'.$locale.'/dashboard');
    }
}
