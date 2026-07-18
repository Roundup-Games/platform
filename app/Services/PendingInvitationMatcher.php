<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Matches a freshly registered/authenticated user to pending email invitations.
 *
 * Email invitations create GameParticipant / CampaignParticipant rows with a
 * null user_id and the invitee's email. When that person signs up (email or
 * OAuth), those rows are claimed by associating the new user — so the funnel
 * metric "invitations converted to signups" is measurable and invitees land
 * directly on the entity they were invited to.
 *
 * Extracted from RegisteredUserController so the OAuth registration flow can
 * share the exact same logic (previously OAuth signups reported 0 matches).
 */
class PendingInvitationMatcher
{
    /**
     * Claim pending invitations for the given user and return the match count.
     *
     * @return int Number of pending invitations matched.
     */
    public function match(User $user): int
    {
        // Canonicalize so Gmail-family addresses match across dot/"+suffix"
        // variants and @googlemail.com vs @gmail.com. Pending invites are
        // stored in canonical form (see ParticipantService::inviteByEmail), so
        // the registering user's email must be canonicalized the same way or
        // e.g. "alice.smith@gmail.com" invites would never match a Google
        // signup that returns "alicesmith@gmail.com".
        $email = EmailCanonicalizer::canonical($user->email);

        // Match game invitations
        $gameMatches = GameParticipant::where('invitee_email', $email)
            ->whereNull('user_id')
            ->where('status', 'pending')
            ->where('role', ParticipantRole::Invited->value)
            ->get();

        $gameMatchCount = 0;
        foreach ($gameMatches as $participant) {
            try {
                $participant->user()->associate($user)->save();
            } catch (QueryException $e) {
                Log::warning('registration.game_invite_match_conflict', [
                    'user_id' => $user->id,
                    'game_id' => $participant->game_id,
                    'invitee_email' => $email,
                ]);

                continue;
            }
            $gameMatchCount++;
            Log::info('registration.matched_game_invite', [
                'user_id' => $user->id,
                'game_id' => $participant->game_id,
                'invitee_email' => $email,
            ]);
        }

        // Match campaign invitations
        $campaignMatches = CampaignParticipant::where('invitee_email', $email)
            ->whereNull('user_id')
            ->where('status', 'pending')
            ->where('role', ParticipantRole::Invited->value)
            ->get();

        $campaignMatchCount = 0;
        foreach ($campaignMatches as $participant) {
            try {
                $participant->user()->associate($user)->save();
            } catch (QueryException $e) {
                Log::warning('registration.campaign_invite_match_conflict', [
                    'user_id' => $user->id,
                    'campaign_id' => $participant->campaign_id,
                    'invitee_email' => $email,
                ]);

                continue;
            }
            $campaignMatchCount++;
            Log::info('registration.matched_campaign_invite', [
                'user_id' => $user->id,
                'campaign_id' => $participant->campaign_id,
                'invitee_email' => $email,
            ]);
        }

        // Count only successfully claimed invitations — rows whose save() failed
        // (e.g. unique-constraint conflict) were logged but not claimed, so they
        // must not inflate the invite_match_count funnel metric.
        $totalMatches = $gameMatchCount + $campaignMatchCount;
        if ($totalMatches > 0) {
            Log::info('registration.invite_matches_found', [
                'user_id' => $user->id,
                'email' => $email,
                'total_matches' => $totalMatches,
                'game_matches' => $gameMatchCount,
                'campaign_matches' => $campaignMatchCount,
            ]);
        }

        return $totalMatches;
    }
}
