<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Builds role-adapted Quick Actions for the established-mode dashboard.
 *
 * Returns 1–3 action buttons determined by the user's role (GM, team captain,
 * campaign member) and activity state (has upcoming games or not).
 *
 * Each action: label (i18n key), url, style (primary|secondary), icon (Material Symbol).
 */
class DashboardQuickActionsService
{
    /**
     * Get 1-3 quick action buttons adapted to the user's role and state.
     */
    public function getQuickActions(User $user): array
    {
        $role = $this->determineRole($user);
        $hasUpcoming = $this->hasUpcomingGames($user);

        $actions = [];

        // ── Primary action ─────────────────────────────
        $primaryAction = $this->selectPrimaryAction($role, $hasUpcoming, $user);
        $actions[] = $primaryAction;

        // ── Secondary actions (max 3 total) ─────────────
        $secondaryCandidates = $this->selectSecondaryActions($role, $hasUpcoming, $user, $primaryAction['label']);

        foreach ($secondaryCandidates as $candidate) {
            if (count($actions) >= 3) {
                break;
            }
            $actions[] = $candidate;
        }

        Log::debug('profile.dashboard_quick_actions', [
            'user_id' => $user->id,
            'role' => $role,
            'has_upcoming' => $hasUpcoming,
            'action_count' => count($actions),
        ]);

        return $actions;
    }

    // ── Role determination ─────────────────────────────

    /**
     * Determine the user's primary dashboard role.
     *
     * Priority: team_captain > gm > player.
     * Team captain is checked first because it's the most specific role
     * (requires active captain membership on at least one team).
     */
    private function determineRole(User $user): string
    {
        if ($this->isTeamCaptainOfAny($user)) {
            return 'team_captain';
        }

        if ($user->isGM()) {
            return 'gm';
        }

        return 'player';
    }

    /**
     * Check if the user is an active captain on any team.
     *
     * Queries team_members directly because TeamMember extends Model (not Pivot),
     * so the teams() relationship's wherePivot() triggers fromRawAttributes errors.
     */
    private function isTeamCaptainOfAny(User $user): bool
    {
        return TeamMember::query()
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->where('status', 'active')
            ->exists();
    }

    // ── State detection ────────────────────────────────

    /**
     * Check if user has any upcoming scheduled games.
     */
    private function hasUpcomingGames(User $user): bool
    {
        $cached = app(DashboardCacheService::class)->getWeekData($user);
        $upcomingCount = $cached['upcoming_count'] ?? 0;

        if ($upcomingCount > 0) {
            return true;
        }

        // Fallback: direct query for games beyond the current week window
        return Game::query()
            ->where('status', GameStatus::Scheduled)
            ->where('date_time', '>', now())
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('participants', fn ($pq) => $pq
                        ->where('user_id', $user->id)
                        ->where('status', ParticipantStatus::Approved));
            })
            ->exists();
    }

    // ── Action selection ───────────────────────────────

    /**
     * Select the primary (first) action based on role and upcoming state.
     */
    private function selectPrimaryAction(string $role, bool $hasUpcoming, User $user): array
    {
        return match (true) {
            $role === 'team_captain' => [
                'label' => 'profile.dashboard_quick_manage_team',
                'url' => $this->getTeamManageUrl($user),
                'style' => 'primary',
                'icon' => 'groups',
            ],
            $role === 'gm' && ! $hasUpcoming => [
                'label' => 'profile.dashboard_quick_create_game',
                'url' => route('games.create'),
                'style' => 'primary',
                'icon' => 'add_circle',
            ],
            $role === 'gm' && $hasUpcoming => [
                'label' => 'profile.dashboard_quick_gm_workspace',
                'url' => route('gm.workspace'),
                'style' => 'primary',
                'icon' => 'castle',
            ],
            $role === 'player' && ! $hasUpcoming => [
                'label' => 'profile.dashboard_quick_discover',
                'url' => route('discover'),
                'style' => 'primary',
                'icon' => 'explore',
            ],
            default => [ // player + has upcoming
                'label' => 'profile.dashboard_quick_my_games',
                'url' => route('games.index'),
                'style' => 'primary',
                'icon' => 'schedule',
            ],
        };
    }

    /**
     * Select up to 2 secondary actions based on role and state.
     *
     * Rules:
     *  - Discover Games: always included if not the primary action
     *  - Create Game: for GMs if not the primary action
     *  - My Campaigns: for campaign members
     *  - Find Campaigns: if not in any campaign
     */
    private function selectSecondaryActions(string $role, bool $hasUpcoming, User $user, string $primaryLabel): array
    {
        $candidates = [];

        // Discover Games — always useful if not primary
        $discoverLabel = 'profile.dashboard_quick_discover';
        if ($primaryLabel !== $discoverLabel) {
            $candidates[] = [
                'label' => $discoverLabel,
                'url' => route('discover'),
                'style' => 'secondary',
                'icon' => 'explore',
            ];
        }

        // Create Game — for GMs if not primary
        $createLabel = 'profile.dashboard_quick_create_game';
        if ($role === 'gm' && $primaryLabel !== $createLabel) {
            $candidates[] = [
                'label' => $createLabel,
                'url' => route('games.create'),
                'style' => 'secondary',
                'icon' => 'add_circle',
            ];
        }

        // My Campaigns — for campaign members
        if ($this->isCampaignMember($user)) {
            $candidates[] = [
                'label' => 'profile.dashboard_quick_my_campaigns',
                'url' => route('campaigns.index'),
                'style' => 'secondary',
                'icon' => 'auto_stories',
            ];
        } else {
            // Find Campaigns — if not in any campaign
            $candidates[] = [
                'label' => 'profile.dashboard_quick_find_campaigns',
                'url' => route('campaigns.index'),
                'style' => 'secondary',
                'icon' => 'campaign',
            ];
        }

        return $candidates;
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Check if user is an active member of any campaign.
     *
     * Queries campaign_participants directly because CampaignParticipant
     * extends Model (not Pivot), so wherePivot via the relationship fails.
     */
    private function isCampaignMember(User $user): bool
    {
        return CampaignParticipant::query()
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
    }

    /**
     * Get the manage-team URL for the user's first captained team.
     *
     * Queries team_members directly (see isTeamCaptainOfAny for why).
     */
    private function getTeamManageUrl(User $user): string
    {
        $teamMember = TeamMember::query()
            ->where('user_id', $user->id)
            ->where('role', 'captain')
            ->where('status', 'active')
            ->first();

        if ($teamMember) {
            $team = Team::find($teamMember->team_id);

            if ($team) {
                return route('teams.manage', ['slug' => $team->slug]);
            }
        }

        // Fallback if somehow no team found
        return route('teams.browse');
    }
}
