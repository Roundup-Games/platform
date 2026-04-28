<?php

namespace App\Services;

use App\Dto\PwaEligibilityResult;
use App\Enums\RelationshipType;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserAppVisit;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Log;

class PwaEligibilityService
{
    /**
     * Evaluate whether the user is eligible for the PWA install prompt.
     *
     * Evaluation order:
     *  1. Baseline (profile_complete + location_id) — must pass
     *  2. Trypass events (upcoming game, recent join/create, recent follow received)
     *  3. Score gate (2 of 3: visit_days, game_participation, social_investment)
     *
     * Result is cached in session for 1 hour to avoid per-request recomputation.
     */
    public function isEligible(User $user): PwaEligibilityResult
    {
        $cached = $this->getCached($user);

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->evaluate($user);

        $this->setCached($user, $result);

        Log::channel('daily')->info('pwa.eligibility.evaluated', [
            'user_id' => $user->id,
            'eligible' => $result->eligible,
            'reason' => $result->reason,
            'source' => $result->source,
        ]);

        return $result;
    }

    /**
     * Force re-evaluation, bypassing session cache.
     */
    public function reevaluate(User $user): PwaEligibilityResult
    {
        $result = $this->evaluate($user);

        $this->setCached($user, $result);

        Log::channel('daily')->info('pwa.eligibility.reevaluated', [
            'user_id' => $user->id,
            'eligible' => $result->eligible,
            'reason' => $result->reason,
            'source' => $result->source,
        ]);

        return $result;
    }

    // ── Internal ───────────────────────────────────────

    private function evaluate(User $user): PwaEligibilityResult
    {
        // 1. Baseline check
        if (! $this->passesBaseline($user)) {
            return PwaEligibilityResult::notEligible('baseline_missing');
        }

        // 2. Trypass events (override score gate)
        $trypass = $this->detectTrypass($user);
        if ($trypass !== null) {
            Log::channel('daily')->info('pwa.eligibility.trypass', [
                'user_id' => $user->id,
                'reason' => $trypass->reason,
            ]);

            return $trypass;
        }

        // 3. Score gate (need 2 of 3)
        if ($this->passesScoreGate($user)) {
            return PwaEligibilityResult::eligibleViaScore();
        }

        return PwaEligibilityResult::notEligible('score_too_low');
    }

    private function passesBaseline(User $user): bool
    {
        return $user->profile_complete === true
            && $user->location_id !== null;
    }

    private function detectTrypass(User $user): ?PwaEligibilityResult
    {
        // 2a. Upcoming game within 7 days where user is owner or approved participant
        $hasUpcomingGame = Game::where(function ($q) use ($user) {
            $q->where('owner_id', $user->id)
                ->orWhereHas('participants', fn ($pq) => $pq
                    ->where('user_id', $user->id)
                    ->where('status', 'approved')
                );
        })
            ->where('date_time', '>=', now())
            ->where('date_time', '<=', now()->addDays(7))
            ->exists();

        if ($hasUpcomingGame) {
            return PwaEligibilityResult::eligibleViaTrypass('trypass_game_upcoming');
        }

        // 2b. First game creation (as owner) within last 5 minutes
        $recentlyCreatedGame = Game::where('owner_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentlyCreatedGame) {
            // Only trigger for the user's first-ever game creation
            $totalOwnedGames = Game::where('owner_id', $user->id)->count();

            if ($totalOwnedGames <= 1) {
                return PwaEligibilityResult::eligibleViaTrypass('trypass_first_game_created');
            }
        }

        // 2c. Most recent GameParticipant approval within last 5 minutes
        //     (GameParticipant has no timestamps, so check via the parent Game.created_at)
        $recentlyJoinedGame = Game::whereHas('participants', fn ($q) => $q
            ->where('user_id', $user->id)
            ->where('status', 'approved')
        )
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentlyJoinedGame) {
            return PwaEligibilityResult::eligibleViaTrypass('trypass_game_joined');
        }

        // 2d. Recently received first game/campaign invitation (GameParticipant with status 'pending')
        //     Only triggers for the user's first-ever invitation to preserve the "special moment" intent.
        //     GameParticipant has no timestamps, so we check via the parent Game.created_at
        $recentlyInvited = Game::whereHas('participants', fn ($q) => $q
            ->where('user_id', $user->id)
            ->where('status', 'pending')
        )
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentlyInvited) {
            $totalInvitations = GameParticipant::where('user_id', $user->id)
                ->where('status', 'pending')
                ->count();

            if ($totalInvitations <= 1) {
                return PwaEligibilityResult::eligibleViaTrypass('trypass_invitation_received');
            }
        }

        // 2e. First campaign creation within last 5 minutes
        $recentlyCreatedCampaign = \App\Models\Campaign::where('owner_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentlyCreatedCampaign) {
            $totalOwnedCampaigns = \App\Models\Campaign::where('owner_id', $user->id)->count();

            if ($totalOwnedCampaigns <= 1) {
                return PwaEligibilityResult::eligibleViaTrypass('trypass_first_campaign_created');
            }
        }

        return null;
    }

    private function passesScoreGate(User $user): bool
    {
        $score = 0;

        // Signal 1: Visit days ≥ 2 distinct days
        $visitDays = UserAppVisit::where('user_id', $user->id)
            ->distinct('visit_date')
            ->count('visit_date');

        if ($visitDays >= 2) {
            $score++;
        }

        // Signal 2: Has approved game participation
        $hasGameParticipation = GameParticipant::where('user_id', $user->id)
            ->where('status', 'approved')
            ->exists();

        if ($hasGameParticipation) {
            $score++;
        }

        // Signal 3: Has at least one follow relationship
        $hasSocialInvestment = UserRelationship::where('user_id', $user->id)
            ->where('type', RelationshipType::Follow)
            ->exists();

        if ($hasSocialInvestment) {
            $score++;
        }

        return $score >= 2;
    }

    // ── Session Caching ────────────────────────────────

    private function getCached(User $user): ?PwaEligibilityResult
    {
        $key = $this->cacheKey($user);
        $cached = session($key);

        if ($cached === null) {
            return null;
        }

        // Cache structure: ['result' => [...], 'expires' => timestamp]
        if (! isset($cached['expires']) || now()->timestamp > $cached['expires']) {
            session()->forget($key);

            return null;
        }

        Log::channel('daily')->info('pwa.eligibility.cache_hit', [
            'user_id' => $user->id,
            'eligible' => $cached['eligible'],
            'reason' => $cached['reason'],
            'source' => $cached['source'],
        ]);

        return new PwaEligibilityResult(
            eligible: $cached['eligible'],
            reason: $cached['reason'],
            source: $cached['source'],
        );
    }

    private function setCached(User $user, PwaEligibilityResult $result): void
    {
        session([
            $this->cacheKey($user) => [
                'eligible' => $result->eligible,
                'reason' => $result->reason,
                'source' => $result->source,
                'expires' => now()->addHour()->timestamp,
            ],
        ]);
    }

    private function cacheKey(User $user): string
    {
        return "pwa_eligibility_{$user->id}";
    }
}
