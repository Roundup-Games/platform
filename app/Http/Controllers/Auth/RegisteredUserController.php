<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use App\Rules\ValidUserName;
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

        $sanitizedName = ValidUserName::sanitize($request->name);

        $user = User::create([
            'name' => $sanitizedName,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'password_set_at' => now(),
            'profile_complete' => false,
            'slug' => User::generateUniqueSlug($sanitizedName),
            'privacy_policy_accepted_at' => now(),
            'terms_accepted_at' => now(),
        ]);

        event(new Registered($user));

        // Match pending email invitations to the newly registered user
        $this->matchPendingInvitations($user);

        Auth::login($user);

        return redirect()->route('onboarding.index');
    }

    /**
     * Match pending email invitations to the newly registered user.
     * Queries game_participants and campaign_participants where
     * invitee_email matches the user's email, user_id is null,
     * and status is 'pending'. Populates user_id on match.
     */
    private function matchPendingInvitations(User $user): void
    {
        $email = strtolower($user->email);

        // Match game invitations
        $gameMatches = \App\Models\GameParticipant::where('invitee_email', $email)
            ->whereNull('user_id')
            ->where('status', 'pending')
            ->where('role', 'invited')
            ->get();

        foreach ($gameMatches as $participant) {
            try {
                $participant->update(['user_id' => $user->id]);
            } catch (QueryException $e) {
                Log::warning('registration.game_invite_match_conflict', [
                    'user_id' => $user->id,
                    'game_id' => $participant->game_id,
                    'invitee_email' => $email,
                ]);
                continue;
            }
            Log::info('registration.matched_game_invite', [
                'user_id' => $user->id,
                'game_id' => $participant->game_id,
                'invitee_email' => $email,
            ]);
        }

        // Match campaign invitations
        $campaignMatches = \App\Models\CampaignParticipant::where('invitee_email', $email)
            ->whereNull('user_id')
            ->where('status', 'pending')
            ->where('role', 'invited')
            ->get();

        foreach ($campaignMatches as $participant) {
            try {
                $participant->update(['user_id' => $user->id]);
            } catch (QueryException $e) {
                Log::warning('registration.campaign_invite_match_conflict', [
                    'user_id' => $user->id,
                    'campaign_id' => $participant->campaign_id,
                    'invitee_email' => $email,
                ]);
                continue;
            }
            Log::info('registration.matched_campaign_invite', [
                'user_id' => $user->id,
                'campaign_id' => $participant->campaign_id,
                'invitee_email' => $email,
            ]);
        }

        $totalMatches = $gameMatches->count() + $campaignMatches->count();
        if ($totalMatches > 0) {
            Log::info('registration.invite_matches_found', [
                'user_id' => $user->id,
                'email' => $email,
                'total_matches' => $totalMatches,
                'game_matches' => $gameMatches->count(),
                'campaign_matches' => $campaignMatches->count(),
            ]);
        }
    }
}
