<?php

namespace App\Services;

use App\Dto\ActionItem;
use App\Dto\FeedItem;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GamesPage;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the prioritized "My Games" board view-model for {@see GamesPage}.
 *
 * Replaces the former flat "all owned desc / all participating desc" listing with a
 * state-aware board that surfaces what needs action NOW and tucks the archive away:
 *
 *   1. needsAttention  — game-scoped Action Center items (below-min, pending apps,
 *                        unreported attendance, invitations) the host/player must act on.
 *   2. upcomingHosting — scheduled sessions the user owns, soonest first.
 *   3. upcomingPlaying — scheduled sessions the user joined (not owned), soonest first.
 *   4. pendingInvitations — unanswered invitations (kept as rows for accept/decline).
 *   5. recentCompleted — completed sessions in the last 30 days (recap/attendance prompts).
 *   6. archive         — cancelled + older completed; collapsed in the UI.
 *
 * Preference-awareness: when the user has NO games at all, the empty state is paired
 * with a deep link into discovery so the page always offers a forward path.
 */
class MyGamesBoardService
{
    /** Completed sessions newer than this many days are "recent", older are "archive". */
    private const RECENT_COMPLETED_DAYS = 30;

    /**
     * Build the full My Games board for a user.
     *
     * Deterministic for (user, DB state). No caching — the page is noindex and
     * visited on demand; the underlying queries are index-backed (owner_id,
     * status, date_time) and bounded by the user's own roster.
     *
     * @return array{
     *     needs_attention: array<int, ActionItem>,
     *     upcoming_hosting: Collection<int, Game>,
     *     upcoming_playing: Collection<int, Game>,
     *     pending_invitations: Collection<int, GameParticipant>,
     *     recent_completed: Collection<int, Game>,
     *     archive: Collection<int, Game>,
     *     activity_feed: LengthAwarePaginator<int, FeedItem>,
     *     has_any_games: bool,
     * }
     */
    public function build(User $user): array
    {
        // ── Owned + participating, eager-loaded once ────
        $ownedGames = $this->ownedGames($user);
        $participatingGames = $this->participatingGames($user);
        $pendingInvitations = $this->pendingInvitations($user);

        $allRelevant = $ownedGames->concat($participatingGames)->unique('id')->values();

        // ── Time/status buckets ─────────────────────────
        $now = now();
        $recentCutoff = $now->copy()->subDays(self::RECENT_COMPLETED_DAYS);

        $upcomingHosting = $ownedGames->filter(
            fn (Game $g) => $g->status === GameStatus::Scheduled && $g->date_time !== null && $g->date_time > $now
        )->sortBy('date_time')->values();

        $upcomingPlaying = $participatingGames->filter(
            fn (Game $g) => $g->status === GameStatus::Scheduled && $g->date_time !== null && $g->date_time > $now
        )->sortBy('date_time')->values();

        $recentCompleted = $allRelevant->filter(
            fn (Game $g) => $g->status === GameStatus::Completed
                && $g->date_time !== null && $g->date_time >= $recentCutoff
        )->sortByDesc('date_time')->values();

        $archive = $allRelevant->filter(function (Game $g) use ($recentCutoff, $now): bool {
            // Everything not already shown above: cancelled sessions + completed
            // sessions older than the recent window. Scheduled games whose
            // date_time is past or null (overdue/undated — never made it into
            // upcoming_*) also land here so no game can fall through every section.
            if ($g->status === GameStatus::Scheduled && $g->date_time !== null && $g->date_time > $now) {
                return false; // it's in upcoming_hosting/upcoming_playing
            }
            if ($g->status === GameStatus::Completed && $g->date_time !== null && $g->date_time >= $recentCutoff) {
                return false; // it's in recentCompleted
            }

            return true;
        })->sortByDesc('date_time')->values();

        // ── Needs-attention: game-scoped Action Center items ──
        $needsAttention = $this->gameScopedActionItems($user);

        $activityFeed = app(GameActivityFeedService::class)->getFeed($user, 15);

        return [
            'needs_attention' => $needsAttention,
            'upcoming_hosting' => $upcomingHosting,
            'upcoming_playing' => $upcomingPlaying,
            'pending_invitations' => $pendingInvitations,
            'recent_completed' => $recentCompleted,
            'archive' => $archive,
            'activity_feed' => $activityFeed,
            'has_any_games' => $ownedGames->isNotEmpty() || $participatingGames->isNotEmpty() || $pendingInvitations->isNotEmpty(),
        ];
    }

    // ── Queries ────────────────────────────────────────

    /**
     * All games the user owns, eager-loaded for the card view.
     *
     * @return Collection<int, Game>
     */
    private function ownedGames(User $user): Collection
    {
        return Game::where('owner_id', $user->id)
            ->with(['gameSystems', 'participants', 'campaign'])
            ->orderBy('date_time', 'desc')
            ->get();
    }

    /**
     * Games where the user is an approved player (not the owner).
     *
     * @return Collection<int, Game>
     */
    private function participatingGames(User $user): Collection
    {
        return Game::whereHas('participants', fn ($q) => $q
            ->where('user_id', $user->id)
            ->where('role', ParticipantRole::Player->value)
            ->where('status', ParticipantStatus::Approved->value),
        )
            ->where('owner_id', '!=', $user->id)
            ->with(['gameSystems', 'participants', 'owner', 'campaign'])
            ->orderBy('date_time', 'desc')
            ->get();
    }

    /**
     * Unanswered invitations addressed to this user.
     *
     * @return Collection<int, GameParticipant>
     */
    private function pendingInvitations(User $user): Collection
    {
        return GameParticipant::where('user_id', $user->id)
            ->where('role', ParticipantRole::Invited->value)
            ->where('status', ParticipantStatus::Pending->value)
            ->with(['game.gameSystems', 'game.owner'])
            ->get();
    }

    /**
     * Game-scoped Action Center items for the viewer.
     *
     * Reuses {@see ActionCenterService::getItems()} so the "needs attention" list
     * is identical to the dashboard's, then keeps only game-scoped items (the My
     * Games page has no business surfacing follower/review/campaign items).
     *
     * @return array<int, ActionItem>
     */
    private function gameScopedActionItems(User $user): array
    {
        return app(ActionCenterService::class)->getGameItems($user);
    }
}
