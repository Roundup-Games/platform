<?php

namespace App\Mcp\Concerns;

use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Models\User;
use Laravel\Mcp\Response;

/**
 * Shared payload mapping and denial shapes for the GM/organizer host
 * tools (M079/S03): read-only host-workspace surfaces over the same
 * services the web workspace uses.
 *
 * Denials follow the ResolvesWritableGame shape — a stable snake_case
 * code prefix (`code: message`) an agent can branch on, plus the
 * isError flag the transport pins. The not-authorized denial is the
 * 403-equivalent of the web workspace's authorize() call: the tool
 * resolves the SAME policy the web surface uses (GamePolicy::update /
 * CampaignPolicy::update / CampaignPolicy::manageCharter), so the two
 * surfaces can never disagree about who a host is.
 */
trait BuildsHostPayloads
{
    /**
     * A structured tool denial: stable machine-readable code, then prose.
     */
    protected function hostDenial(string $code, string $message): Response
    {
        return Response::error($code.': '.$message);
    }

    /**
     * One player's attendance-history summary — the same public-profile
     * reliability shape (tier/score/games) the web renders from
     * User::$reliability_score, with the same newcomer defaults. This is
     * resolved-attendance history only: no report bodies, no weights,
     * no per-game detail (games.detail of a completed game covers that).
     *
     * @return array{tier: string, score: float, games: int}
     */
    protected function reliabilitySummary(?User $user): array
    {
        $score = $user?->reliability_score;

        $tier = is_array($score) && is_string($score['tier'] ?? null) ? $score['tier'] : 'newcomer';
        $scoreValue = is_array($score) && is_numeric($score['score'] ?? null) ? (float) $score['score'] : 0.0;
        $games = is_array($score) && is_numeric($score['game_count'] ?? null) ? (int) $score['game_count'] : 0;

        return [
            'tier' => $tier,
            'score' => $scoreValue,
            'games' => $games,
        ];
    }

    /**
     * One roster row for a game or campaign participant: identity, role,
     * roster status, join date, and the attendance-history summary.
     * Compact by contract — no emails, no profile detail.
     *
     * @return array<string, mixed>
     */
    protected function rosterRow(GameParticipant|CampaignParticipant $participant): array
    {
        return [
            'user_id' => $participant->user_id,
            'name' => $participant->user?->name,
            'role' => $participant->role?->value,
            'status' => $participant->status?->value,
            'joined_at' => $participant->created_at?->toIso8601String(),
            'reliability' => $this->reliabilitySummary($participant->user),
        ];
    }
}
