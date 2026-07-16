<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ValidUserName;
use App\Services\PendingInvitationMatcher;
use App\Services\PostHogAnalytics;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', new ValidUserName],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $name = is_string($request->name) ? $request->name : '';
        $password = is_string($request->password) ? $request->password : '';
        $sanitizedName = ValidUserName::sanitize($name);

        $user = User::create([
            'name' => $sanitizedName,
            'email' => $request->email,
            'password' => Hash::make($password),
            'password_set_at' => now(),
            'profile_complete' => false,
            'slug' => User::generateUniqueSlug($sanitizedName),
            'privacy_policy_accepted_at' => now(),
            'terms_accepted_at' => now(),
        ]);

        event(new Registered($user));

        // Match pending email invitations to the newly registered user
        $inviteMatches = app(PendingInvitationMatcher::class)->match($user);

        Auth::login($user);

        // Acquisition funnel: capture the signup with attribution. Consent-gated
        // via PostHogAnalytics — non-consenting signups still appear in the users
        // table but are not server-side tracked. OAuth signups carry their provider.
        $analytics = app(PostHogAnalytics::class);
        $analytics->capture(
            $user,
            'user.signed_up',
            [
                'signup_method' => 'email',
                'oauth_provider' => null,
                'invite_match_count' => $inviteMatches,
                'locale' => app()->getLocale(),
            ],
        );

        // First-touch SEO attribution: record the entry path + referrer domain as
        // permanent person properties for organic/search investment analysis.
        $analytics->identifyFirstTouch($user, $request->header('referer'), $request->path());

        return redirect()->route('onboarding.index');
    }
}
