<?php

namespace App\Http\Controllers\Auth;

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController
{
    /**
     * Redirect the user to the OAuth provider's authentication page.
     */
    public function redirect(Request $request, string $provider)
    {
        if (! in_array($provider, ['google'])) {
            return redirect()->route('login')->withErrors(['oauth' => 'Unsupported login provider.']);
        }

        // If the user is already logged in, they're linking an account
        if ($request->user()) {
            $request->session()->put('oauth_linking', true);
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the OAuth provider callback — login, register, or link account.
     */
    public function callback(Request $request, string $provider)
    {
        if (! in_array($provider, ['google'])) {
            return redirect()->route('login')->withErrors(['oauth' => 'Unsupported login provider.']);
        }

        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            report($e);
            Log::warning('OAuth callback failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('login')->withErrors(['oauth' => 'Unable to authenticate with ' . ucfirst($provider) . '. Please try again.']);
        }

        $providerUserId = $socialiteUser->getId();
        $isLinking = $request->session()->pull('oauth_linking', false);

        // ── Account linking flow (logged-in user connecting a provider) ──
        if ($isLinking && $request->user()) {
            return $this->linkAccount($request->user(), $provider, $socialiteUser, $providerUserId);
        }

        // ── Standard login/register flow ──

        // Look for an existing linked account first
        $linkedAccount = LinkedAccount::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($linkedAccount) {
            // Existing linked account — log the user in
            $linkedAccount->update([
                'token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken,
                'token_expires_at' => null,
                'provider_meta' => [
                    'nickname' => $socialiteUser->getNickname(),
                    'avatar' => $socialiteUser->getAvatar(),
                ],
            ]);

            $user = $linkedAccount->user;

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

        // New user — register and link
        $user = User::create([
            'name' => $socialiteUser->getName() ?? Str::before($socialiteUser->getEmail(), '@'),
            'email' => $socialiteUser->getEmail(),
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
            'avatar_url' => $socialiteUser->getAvatar(),
            'profile_complete' => false,
        ]);

        $this->createLinkedAccount($user, $provider, $socialiteUser, $providerUserId);

        Auth::login($user);
        Log::info('OAuth registration — new user created', [
            'provider' => $provider,
            'user_id' => $user->id,
            'provider_user_id' => $providerUserId,
        ]);

        return $this->redirectAfterLogin($user);
    }

    /**
     * Link a provider to an already-authenticated user's account.
     */
    private function linkAccount(User $user, string $provider, $socialiteUser, string $providerUserId): \Illuminate\Http\RedirectResponse
    {
        // Use firstOrCreate for atomicity — the unique constraint on
        // (provider, provider_user_id) prevents race-condition duplicates.
        $linkedAccount = LinkedAccount::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($linkedAccount && $linkedAccount->user_id !== $user->id) {
            Log::warning('OAuth link attempted — provider already linked to another user', [
                'provider' => $provider,
                'user_id' => $user->id,
                'existing_user_id' => $linkedAccount->user_id,
                'provider_user_id' => $providerUserId,
            ]);

            return redirect()->route('profile.edit')
                ->withErrors(['oauth' => 'This ' . ucfirst($provider) . ' account is already linked to another user.']);
        }

        // Already linked to this user
        if ($linkedAccount) {
            return redirect()->route('profile.edit')
                ->with('status', ucfirst($provider) . ' account is already linked.');
        }

        // Atomically create via firstOrCreate to prevent race conditions
        try {
            LinkedAccount::create([
                'user_id' => $user->id,
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
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint violation — another request beat us to it
            if (str_contains($e->getMessage(), 'Unique violation') || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                Log::warning('OAuth link race — provider already linked (caught by unique constraint)', [
                    'provider' => $provider,
                    'user_id' => $user->id,
                    'provider_user_id' => $providerUserId,
                ]);

                return redirect()->route('profile.edit')
                    ->withErrors(['oauth' => 'This ' . ucfirst($provider) . ' account is already linked.']);
            }

            throw $e;
        }

        Log::info('OAuth account linked', [
            'provider' => $provider,
            'user_id' => $user->id,
            'provider_user_id' => $providerUserId,
        ]);

        return redirect()->route('profile.edit')
            ->with('status', ucfirst($provider) . ' account linked successfully.');
    }

    /**
     * Create a LinkedAccount record from OAuth data.
     */
    private function createLinkedAccount(User $user, string $provider, $socialiteUser, string $providerUserId): LinkedAccount
    {
        return LinkedAccount::create([
            'user_id' => $user->id,
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
    private function redirectAfterLogin(User $user): \Illuminate\Http\RedirectResponse
    {
        if (! $user->profile_complete) {
            return redirect()->route('onboarding.index');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
