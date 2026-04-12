<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirect(string $provider)
    {
        if (! in_array($provider, ['google'])) {
            return redirect()->route('login')->withErrors(['oauth' => 'Unsupported login provider.']);
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Obtain the user information from the provider and handle login/registration.
     */
    public function callback(string $provider)
    {
        if (! in_array($provider, ['google'])) {
            return redirect()->route('login')->withErrors(['oauth' => 'Unsupported login provider.']);
        }

        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['oauth' => 'Unable to authenticate with ' . ucfirst($provider) . '. Please try again.']);
        }

        // Try to find existing user with this social account
        $user = User::where('email', $socialiteUser->getEmail())->first();

        if ($user) {
            // Update avatar if provided
            if ($socialiteUser->getAvatar() && ! $user->avatar_url) {
                $user->update(['avatar_url' => $socialiteUser->getAvatar()]);
            }

            Auth::login($user);

            return $this->redirectAfterLogin($user);
        }

        // Create new user from OAuth data
        $user = User::create([
            'name' => $socialiteUser->getName() ?? Str::before($socialiteUser->getEmail(), '@'),
            'email' => $socialiteUser->getEmail(),
            'password' => Hash::make(Str::random(32)), // Random password — they'll use OAuth
            'email_verified_at' => now(), // Google emails are verified
            'avatar_url' => $socialiteUser->getAvatar(),
            'profile_complete' => false,
        ]);

        Auth::login($user);

        return $this->redirectAfterLogin($user);
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
