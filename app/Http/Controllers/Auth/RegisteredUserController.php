<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ValidUserName;
use App\Services\PendingInvitationMatcher;
use App\Services\PostHogAnalytics;
use App\Support\FirstTouch;
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

        // Consume first-touch attribution in one call — the helper reads
        // the CaptureFirstTouch session keys, derives content context, and
        // forgets the keys so they cannot leak into a later signup.
        $firstTouch = FirstTouch::consume($request);

        $user = User::create([
            'name' => $sanitizedName,
            'email' => $request->email,
            'password' => Hash::make($password),
            'password_set_at' => now(),
            'profile_complete' => false,
            'slug' => User::generateUniqueSlug($sanitizedName),
            'privacy_policy_accepted_at' => now(),
            'terms_accepted_at' => now(),
            'signup_oauth_provider' => 'email',
            'first_touch_referer_domain' => FirstTouch::reduceDomain($firstTouch['referer']),
            'first_touch_path' => $firstTouch['path'],
            'signup_content_type' => $firstTouch['content_type'],
            'signup_content_slug' => $firstTouch['content_slug'],
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

        // First-touch SEO attribution: re-derive the same signals for the
        // analytics-tier PostHog identify. The persisted columns above already
        // hold the write-once record; this call fires the PostHog person-property
        // $set_once (consent-gated, best-effort). Both consumers use the same
        // FirstTouch helpers so the two signals cannot drift.
        $analytics->identifyFirstTouch($user, $firstTouch['referer'], $firstTouch['path']);

        return redirect()->route('onboarding.index');
    }
}
