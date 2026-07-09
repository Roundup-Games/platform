<?php

namespace App\Services;

use App\Dto\PwaEligibilityResult;
use App\Enums\RelationshipType;
use App\Models\Campaign;
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
            $q->whereBelongsTo($user, 'owner')
                ->orWhereHas('participants', fn ($pq) => $pq
                    ->whereBelongsTo($user)
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
        $recentlyCreatedGame = Game::whereBelongsTo($user, 'owner')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentlyCreatedGame) {
            // Only trigger for the user's first-ever game creation
            $totalOwnedGames = Game::whereBelongsTo($user, 'owner')->count();

            if ($totalOwnedGames <= 1) {
                return PwaEligibilityResult::eligibleViaTrypass('trypass_first_game_created');
            }
        }

        // 2c. Recently approved as participant (within last 5 minutes)
        $recentlyJoinedGame = GameParticipant::whereBelongsTo($user)
            ->where('status', 'approved')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentlyJoinedGame) {
            return PwaEligibilityResult::eligibleViaTrypass('trypass_game_joined');
        }

        // 2d. Recently received first game/campaign invitation (pending status, within 5 minutes)
        //     Only triggers for the user's first-ever invitation to preserve the "special moment" intent.
        $recentlyInvited = GameParticipant::whereBelongsTo($user)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentlyInvited) {
            $totalInvitations = GameParticipant::whereBelongsTo($user)
                ->where('status', 'pending')
                ->count();

            if ($totalInvitations <= 1) {
                return PwaEligibilityResult::eligibleViaTrypass('trypass_invitation_received');
            }
        }

        // 2e. First campaign creation within last 5 minutes
        $recentlyCreatedCampaign = Campaign::whereBelongsTo($user, 'owner')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentlyCreatedCampaign) {
            $totalOwnedCampaigns = Campaign::whereBelongsTo($user, 'owner')->count();

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
        $visitDays = UserAppVisit::whereBelongsTo($user)
            ->distinct('visit_date')
            ->count('visit_date');

        if ($visitDays >= 2) {
            $score++;
        }

        // Signal 2: Has approved game participation
        $hasGameParticipation = GameParticipant::whereBelongsTo($user)
            ->where('status', 'approved')
            ->exists();

        if ($hasGameParticipation) {
            $score++;
        }

        // Signal 3: Has at least one follow relationship
        $hasSocialInvestment = UserRelationship::whereBelongsTo($user)
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
        if (! is_array($cached) || ! isset($cached['expires']) || now()->timestamp > $cached['expires']) {
            session()->forget($key);

            return null;
        }

        Log::channel('daily')->debug('pwa.eligibility.cache_hit', [
            'user_id' => $user->id,
            'eligible' => $cached['eligible'] ?? null,
            'reason' => $cached['reason'] ?? null,
            'source' => $cached['source'] ?? null,
        ]);

        return new PwaEligibilityResult(
            eligible: is_bool($cached['eligible'] ?? null) ? $cached['eligible'] : false,
            reason: is_string($cached['reason'] ?? null) ? $cached['reason'] : '',
            source: is_string($cached['source'] ?? null) ? $cached['source'] : 'none',
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
