<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Rules\ValidUserName;
use App\Services\PendingInvitationMatcher;
use App\Services\PostHogAnalytics;
use App\Support\FirstTouch;
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
        if (! OAuthProvider::tryFrom($provider)) {
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

        // Explicitly assert scopes from config so the application owns the
        // scope set rather than relying on provider defaults (which could
        // drift if socialiteproviders/* changes its out-of-the-box scope
        // list). The config block is the documented source of truth per
        // M056/D-1 — widening must be a conscious decision there.
        $scopes = config("services.{$provider}.scope", []);
        $scopes = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];

        $driver = Socialite::driver($provider);
        if ($scopes !== []) {
            // Socialite's contracts\Provider interface does not declare
            // scopes(); the concrete Two\AbstractProvider does. Both
            // Google and Discord go through the OAuth2 two-step provider,
            // which exposes the fluent scopes() method.
            $driver = $driver->scopes($scopes); // @phpstan-ignore method.notFound
        }

        return $driver->redirect();
    }

    /**
     * Handle the OAuth provider callback — login, register, or link account.
     *
     * @return RedirectResponse
     */
    public function callback(Request $request, string $provider)
    {
        if (! OAuthProvider::tryFrom($provider)) {
            return redirect($this->localeUrl('login'))->withErrors(['oauth' => 'Unsupported login provider.']);
        }

        // Consume first-touch attribution ONCE at the top of the callback,
        // before any branching. The values were captured on the original
        // landing page by CaptureFirstTouch.
        $firstTouch = FirstTouch::consume($request);

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

        // No linked account — try matching by email.
        //
        // SECURITY: only honour the email-match login path when the IdP
        // has explicitly verified the email claim. Without this gate, an
        // attacker controlling an IdP account whose email matches a
        // roundup user's would be logged in as that user with no password
        // challenge (account-takeover via email reuse — relevant now that
        // Discord widens the IdP surface). Google surfaces the flag as
        // `email_verified` and Discord as `verified` on their raw userinfo
        // payload; isEmailVerified() honours either key. An absent claim
        // defaults to verified to preserve backward compatibility with
        // legacy mocks and any provider that does not surface the flag.
        $user = User::where('email', $socialiteUser->getEmail())->first();

        if ($user) {
            if (! $this->isEmailVerified($socialiteUser)) {
                Log::warning('OAuth email-match login rejected — IdP reports email unverified', [
                    'provider' => $provider,
                    'user_id' => $user->id,
                    'provider_user_id' => $providerUserId,
                ]);

                return redirect($this->localeUrl('login'))->withErrors(['oauth' => ucfirst($provider).' reports this email as unverified. Please verify it with '.ucfirst($provider).' or sign in another way.']);
            }

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
            'email_verified_at' => $this->isEmailVerified($socialiteUser) ? now() : null,
            'avatar_url' => $socialiteUser->getAvatar(),
            'profile_complete' => false,
            'slug' => User::generateUniqueSlug($sanitizedName),
            'signup_oauth_provider' => $provider,
            'first_touch_referer_domain' => FirstTouch::reduceDomain($firstTouch['referer']),
            'first_touch_path' => $firstTouch['path'],
            'signup_content_type' => $firstTouch['content_type'],
            'signup_content_slug' => $firstTouch['content_slug'],
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
        $analytics->identifyFirstTouch($user, $firstTouch['referer'], $firstTouch['path']);

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

        // S06: prefill the user's avatar from the IdP if they don't have one.
        // Linking Discord (or Google) is the natural moment to surface the
        // IdP avatar — the user has just confirmed they own that identity,
        // so the avatar is genuinely theirs. Only fills when no avatar is
        // set (never overwrites an explicit upload).
        if ($socialiteUser->getAvatar() && ! $user->avatar_url) {
            $user->update(['avatar_url' => $socialiteUser->getAvatar()]);
            Log::info('OAuth link prefilled avatar', [
                'provider' => $provider,
                'user_id' => $user->id,
            ]);
        }

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

    /**
     * Did the OAuth IdP explicitly verify the user's email claim?
     *
     * IdPs surface this flag under different keys on their raw userinfo
     * payload (accessible via `$socialiteUser->user`):
     *   - Google: `email_verified` (per OpenID Connect spec)
     *   - Discord: `verified` (per Discord USER resource)
     *
     * We honour either key. We treat an absent claim as verified so legacy
     * mocks and any provider that does not surface the flag continue to
     * work — but when the IdP explicitly reports `false` under either key,
     * the email-match login path is rejected and the new-user path skips
     * auto-verifying the roundup-side email.
     *
     * This is the load-bearing gate that prevents account-takeover via
     * email reuse: an attacker controlling an IdP account whose email
     * matches a roundup user's gets rejected unless the IdP has confirmed
     * control of that email.
     */
    private function isEmailVerified(\Laravel\Socialite\Two\User $socialiteUser): bool
    {
        // The Socialite AbstractUser declares `@var array` on $user but
        // legacy mocks and edge-case providers may leave it null. Read it
        // as mixed so the runtime is_array() guard is honoured.
        /** @var mixed $payload */
        $payload = $socialiteUser->user;

        if (! is_array($payload)) {
            return true;
        }

        // Google: email_verified. Discord: verified. Honour whichever the
        // IdP surfaces. An absent key defaults to true (legacy-compat).
        foreach (['email_verified', 'verified'] as $key) {
            if (array_key_exists($key, $payload)) {
                return (bool) $payload[$key];
            }
        }

        return true;
    }
}
