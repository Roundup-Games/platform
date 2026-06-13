<?php

namespace App\Livewire\Teams;

use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Notifications\TeamMemberRemoved;
use App\Services\NotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ManageRoster extends Component
{
    public Team $team;

    #[Validate('required|email|max:255')]
    public string $inviteEmail = '';

    #[Validate('nullable|string|in:captain,coach,player,substitute')]
    public string $inviteRole = 'player';

    public ?string $editingMemberId = null;

    #[Validate('nullable|string|max:3')]
    public string $editJerseyNumber = '';

    #[Validate('nullable|string|max:50')]
    public string $editPosition = '';

    public function mount(string $slug): void
    {
        $team = Team::where('slug', $slug)->firstOrFail();

        // Allow access if user can manageMembers (captain) OR is an active member
        $user = authenticatedUser();
        if ($user->can('manageMembers', $team) || $team->hasMember($user)) {
            $this->team = $team;
        } else {
            $this->authorize('manageMembers', $team);
        }
    }

    // ── Invite ─────────────────────────────────────────

    public function inviteMember(): void
    {
        $this->authorize('invite', $this->team);
        $this->validateOnly('inviteEmail');
        $this->validateOnly('inviteRole');

        $targetUser = User::where('email', $this->inviteEmail)
            ->whereNull('anonymized_at')
            ->first();

        if (! $targetUser) {
            $this->addError('inviteEmail', __('emails.error_no_user_found_with_that_email_address'));

            return;
        }

        // Cannot invite yourself
        if ($targetUser->id === Auth::id()) {
            $this->addError('inviteEmail', __('common.error_you_cannot_invite_yourself'));

            return;
        }

        $teamId = $this->team->id;
        $targetUserId = $targetUser->id;
        $inviteRole = $this->inviteRole;
        $invitedBy = Auth::id();

        try {
            DB::transaction(function () use ($teamId, $targetUserId, $inviteRole, $invitedBy) {
                // Pessimistic lock on the user's active team_members rows to serialize one-active checks
                $activeLock = TeamMember::lockForUpdate()
                    ->where('user_id', $targetUserId)
                    ->where('status', 'active')
                    ->exists();

                if ($activeLock) {
                    throw new \RuntimeException(__('teams.error_this_user_already_has_an_active_team_membership'));
                }

                // Check for existing pending invite to this team
                $existingPending = TeamMember::where('team_id', $teamId)
                    ->where('user_id', $targetUserId)
                    ->where('status', 'pending')
                    ->exists();

                if ($existingPending) {
                    throw new \RuntimeException(__('teams.error_this_user_already_has_a'));
                }

                // If they were previously removed/inactive, reactivate as pending
                $existingMembership = TeamMember::where('team_id', $teamId)
                    ->where('user_id', $targetUserId)
                    ->first();

                if ($existingMembership) {
                    $existingMembership->update([
                        'role' => $inviteRole,
                        'status' => ParticipantStatus::Pending->value,
                        'invited_by' => $invitedBy,
                        'joined_at' => now(),
                        'left_at' => null,
                    ]);
                } else {
                    TeamMember::create([
                        'team_id' => $teamId,
                        'user_id' => $targetUserId,
                        'role' => $inviteRole,
                        'status' => ParticipantStatus::Pending->value,
                        'invited_by' => $invitedBy,
                        'joined_at' => now(),
                    ]);
                }
            });
        } catch (\RuntimeException $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        Log::info('Team invite sent', [
            'team_id' => $teamId,
            'invited_user_id' => $targetUserId,
            'invited_by' => $invitedBy,
            'role' => $inviteRole,
        ]);

        // Dispatch TeamInvitation notification
        try {
            app(NotificationService::class)->send(
                $targetUser,
                new TeamInvitation($this->team, authenticatedUser()),
                NotificationCategory::TeamInvitation,
            );
        } catch (\Throwable $e) {
            Log::error('notification.team_invite_dispatch_failed', [
                'team_id' => $teamId,
                'target_user_id' => $targetUserId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->reset('inviteEmail', 'inviteRole');
        session()->flash('success', __('emails.content_invite_sent_to_email', ['email' => $targetUser->email]));
    }

    // ── Role Management ────────────────────────────────

    public function promoteMember(string $memberId): void
    {
        $this->authorize('manageMembers', $this->team);

        $member = $this->findActiveMember($memberId);

        $roleOrder = ['substitute', 'player', 'coach', 'captain'];
        $currentIdx = array_search($member->role, $roleOrder);

        if ($currentIdx === false || $currentIdx >= count($roleOrder) - 1) {
            return;
        }

        $newRole = $roleOrder[$currentIdx + 1];
        $member->update(['role' => $newRole]);

        Log::info('Team member promoted', [
            'team_id' => $this->team->id,
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'old_role' => $member->role,
            'new_role' => $newRole,
            'action_by' => Auth::id(),
        ]);
    }

    public function demoteMember(string $memberId): void
    {
        $this->authorize('manageMembers', $this->team);

        $member = $this->findActiveMember($memberId);

        // Cannot demote the last captain
        if ($member->role === 'captain') {
            $captainCount = $this->team->activeMembers()
                ->where('role', 'captain')
                ->count();

            if ($captainCount <= 1) {
                session()->flash('error', __('teams.error_cannot_demote_the_last_captain'));

                return;
            }
        }

        $roleOrder = ['substitute', 'player', 'coach', 'captain'];
        $currentIdx = array_search($member->role, $roleOrder);

        if ($currentIdx === false || $currentIdx <= 0) {
            return;
        }

        $newRole = $roleOrder[$currentIdx - 1];
        $member->update(['role' => $newRole]);

        Log::info('Team member demoted', [
            'team_id' => $this->team->id,
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'old_role' => $member->role,
            'new_role' => $newRole,
            'action_by' => Auth::id(),
        ]);
    }

    public function setRole(string $memberId, string $role): void
    {
        $this->authorize('manageMembers', $this->team);

        $validRoles = ['captain', 'coach', 'player', 'substitute'];
        if (! in_array($role, $validRoles)) {
            return;
        }

        $member = $this->findActiveMember($memberId);

        // Cannot demote the last captain
        if ($member->role === 'captain' && $role !== 'captain') {
            $captainCount = $this->team->activeMembers()
                ->where('role', 'captain')
                ->count();

            if ($captainCount <= 1) {
                session()->flash('error', __('teams.error_cannot_remove_the_last_captain_role'));

                return;
            }
        }

        $oldRole = $member->role;
        $member->update(['role' => $role]);

        Log::info('Team member role changed', [
            'team_id' => $this->team->id,
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'old_role' => $oldRole,
            'new_role' => $role,
            'action_by' => Auth::id(),
        ]);
    }

    // ── Roster Details ─────────────────────────────────

    public function startEditing(string $memberId): void
    {
        $member = $this->findActiveMember($memberId);
        $this->editingMemberId = $member->id;
        $this->editJerseyNumber = $member->jersey_number ?? '';
        $this->editPosition = $member->position ?? '';
    }

    public function saveMemberDetails(): void
    {
        $this->authorize('manageMembers', $this->team);
        $this->validateOnly('editJerseyNumber');
        $this->validateOnly('editPosition');

        if (! $this->editingMemberId) {
            return;
        }

        $member = $this->findActiveMember($this->editingMemberId);

        $member->update([
            'jersey_number' => $this->editJerseyNumber ?: null,
            'position' => $this->editPosition ?: null,
        ]);

        Log::info('Team member details updated', [
            'team_id' => $this->team->id,
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'jersey_number' => $this->editJerseyNumber ?: null,
            'position' => $this->editPosition ?: null,
            'action_by' => Auth::id(),
        ]);

        $this->cancelEditing();
        session()->flash('success', __('teams.flash_member_details_updated'));
    }

    public function cancelEditing(): void
    {
        $this->reset('editingMemberId', 'editJerseyNumber', 'editPosition');
    }

    // ── Remove / Leave ─────────────────────────────────

    public function removeMember(string $memberId): void
    {
        $this->authorize('manageMembers', $this->team);

        $member = $this->findActiveMember($memberId);

        // Cannot remove the last captain
        if ($member->role === 'captain') {
            $captainCount = $this->team->activeMembers()
                ->where('role', 'captain')
                ->count();

            if ($captainCount <= 1) {
                session()->flash('error', __('teams.error_cannot_remove_the_last_captain'));

                return;
            }
        }

        $member->update([
            'status' => ParticipantStatus::Removed->value,
            'left_at' => now(),
        ]);

        Log::info('Team member removed', [
            'team_id' => $this->team->id,
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'removed_by' => Auth::id(),
        ]);

        // Notify the removed user
        try {
            $removedUser = User::find($member->user_id);
            if ($removedUser) {
                app(NotificationService::class)->send(
                    $removedUser,
                    new TeamMemberRemoved($this->team, authenticatedUser()),
                    NotificationCategory::TeamMemberRemoved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.team_member_removed_dispatch_failed', [
                'team_id' => $this->team->id,
                'removed_user_id' => $member->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        session()->flash('success', __('teams.content_member_removed_from_team'));
    }

    public function cancelInvite(string $memberId): void
    {
        $this->authorize('invite', $this->team);

        $member = TeamMember::where('id', $memberId)
            ->where('team_id', $this->team->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $member->update([
            'status' => ParticipantStatus::Removed->value,
            'left_at' => now(),
        ]);

        Log::info('Team invite cancelled', [
            'team_id' => $this->team->id,
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'cancelled_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_invite_cancelled'));
    }

    public function leaveTeam(): void
    {
        $member = TeamMember::where('team_id', $this->team->id)
            ->where('user_id', Auth::id())
            ->where('status', 'active')
            ->firstOrFail();

        // Cannot leave if last captain
        if ($member->role === 'captain') {
            $captainCount = $this->team->activeMembers()
                ->where('role', 'captain')
                ->count();

            if ($captainCount <= 1) {
                session()->flash('error', __('teams.error_you_cannot_leave_the_team'));

                return;
            }
        }

        $member->update([
            'status' => 'inactive',
            'left_at' => now(),
        ]);

        Log::info('Team member left', [
            'team_id' => $this->team->id,
            'user_id' => Auth::id(),
        ]);

        session()->flash('success', __('teams.content_you_have_left_the_team'));

        $this->redirect(route('teams.browse'), navigate: true);
    }

    // ── Helpers ────────────────────────────────────────

    private function findActiveMember(string $memberId): TeamMember
    {
        return TeamMember::where('id', $memberId)
            ->where('team_id', $this->team->id)
            ->where('status', 'active')
            ->firstOrFail();
    }

    public function render(): View
    {
        $roleOrder = [
            'captain' => 1,
            'coach' => 2,
            'player' => 3,
            'substitute' => 4,
        ];

        $activeMembers = $this->team->activeMembers()
            ->with('user')
            ->get()
            ->sortBy(fn ($m) => $roleOrder[$m->role ?? ''] ?? 99);

        $pendingInvites = $this->team->members()
            ->where('status', 'pending')
            ->with(['user', 'invitedBy'])
            ->get();

        $isCaptain = $this->team->isCaptain(authenticatedUser());

        return view('livewire.teams.manage-roster', [
            'team' => $this->team,
            'activeMembers' => $activeMembers,
            'pendingInvites' => $pendingInvites,
            'isCaptain' => $isCaptain,
        ]);
    }
}
