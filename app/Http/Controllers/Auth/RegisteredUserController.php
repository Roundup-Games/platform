<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ValidUserName;
use App\Services\PendingInvitationMatcher;
use App\Services\PostHogAnalytics;
use App\Support\FirstTouch;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

        // Read (do not consume) the first-touch attribution: the capture keys
        // are only forgotten once the user row exists further down, so a
        // signup that loses a race and is returned to this form with a field
        // error keeps its attribution for the successful retry.
        $firstTouch = FirstTouch::read($request);

        $attributes = [
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
        ];

        // The 'unique:users' rule above and this insert are two statements. Two
        // near-simultaneous signups with the same email both pass the rule, then
        // the second insert hits the users_email_unique constraint. Rethrow that
        // race as the field error the validator would have shown, so the second
        // person sees "email already taken" instead of a 500.
        try {
            $user = User::create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            // users carries several unique constraints (email, slug, paddle
            // id). Only an email violation means "this email is taken" — a
            // slug violation is generateUniqueSlug()'s own check-then-insert
            // race (two people with the same name signing up at once) and
            // must not be reported as an email error to someone whose email
            // is fine.
            if (in_array('email', $e->columns, true)) {
                throw self::emailTaken();
            }

            // Slug race: retry once with a randomized suffix, which cannot
            // collide with the slug the concurrent signup claimed between
            // this request's scan and its insert.
            try {
                $user = User::create([
                    ...$attributes,
                    'slug' => User::generateUniqueSlug($sanitizedName).'-'.Str::lower(Str::random(4)),
                ]);
            } catch (UniqueConstraintViolationException $retry) {
                // The competing signup may have claimed the email between
                // the first attempt and the retry — same field error, not a
                // 500.
                if (in_array('email', $retry->columns, true)) {
                    throw self::emailTaken();
                }

                throw $retry;
            }
        }

        // The row exists — only now can the first-touch capture keys be
        // forgotten without risking attribution loss on a retried signup.
        FirstTouch::forget($request);

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

    /**
     * The field error the 'unique:users' rule itself would have produced —
     * byte-identical, so a raced submitter sees the same message whether the
     * collision was caught by validation or by the constraint.
     */
    private static function emailTaken(): ValidationException
    {
        return ValidationException::withMessages([
            'email' => trans('validation.unique', ['attribute' => 'email']),
        ]);
    }
}
