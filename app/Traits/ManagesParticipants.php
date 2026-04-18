<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Validate;

/**
 * Shared participant management logic for Games and Campaigns.
 *
 * Requires the consuming Livewire component to implement:
 *   - getEntity():        The Game|Campaign model instance
 *   - getEntityIdColumn(): 'game_id' or 'campaign_id'
 *   - getParticipantModel(): GameParticipant or CampaignParticipant class
 *   - getEntityName():    'Game' or 'Campaign' (for log messages)
 *   - getEntityVar():     'game' or 'campaign' (for Blade variable name)
 *   - getBackRoute():     route name for the back link
 */
trait ManagesParticipants
{
    #[Validate('required|email|max:255')]
    public string $inviteEmail = '';

    // ── Abstract contracts ─────────────────────────────

    abstract public function getEntity();

    abstract public function getEntityIdColumn(): string;

    abstract public function getParticipantModel(): string;

    abstract public function getEntityName(): string;

    abstract public function getEntityVar(): string;

    abstract public function getBackRoute(): string;

    // ── Invite ─────────────────────────────────────────

    public function inviteParticipant(): void
    {
        $this->validateOnly('inviteEmail');

        $targetUser = \App\Models\User::where('email', $this->inviteEmail)->first();

        if (! $targetUser) {
            $this->addError('inviteEmail', __('emails.error_no_user_found_with_that_email_address'));

            return;
        }

        if ($targetUser->id === Auth::id()) {
            $this->addError('inviteEmail', __('common.error_you_cannot_invite_yourself'));

            return;
        }

        $entity = $this->getEntity();

        if ($entity->participants()->where('user_id', $targetUser->id)->exists()) {
            $this->addError('inviteEmail', __('common.error_this_user_is_already_a'));

            return;
        }

        $participantModel = $this->getParticipantModel();

        $participantModel::create([
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $targetUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Log::info($this->getEntityName() . ' participant invited', [
            $this->getEntityIdColumn() => $entity->id,
            'invited_user_id' => $targetUser->id,
            'invited_by' => Auth::id(),
        ]);

        $this->reset('inviteEmail');
        session()->flash('success', __('emails.content_invite_sent_to_email', ['email' => $targetUser->email]));
    }

    // ── Approve Application ────────────────────────────

    public function approveApplication(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();

        if ($participant->role !== 'applicant') {
            return;
        }

        $participant->update([
            'role' => 'player',
            'status' => 'approved',
        ]);

        $entity->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => 'approved']);

        Log::info($this->getEntityName() . ' application approved', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $participant->user_id,
            'approved_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_application_approved'));
    }

    public function rejectApplication(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();

        if ($participant->role !== 'applicant') {
            return;
        }

        $participant->update(['status' => 'rejected']);

        $entity->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' application rejected', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $participant->user_id,
            'rejected_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_application_rejected'));
    }

    // ── Remove Participant ─────────────────────────────

    public function removeParticipant(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();

        if ($participant->role === 'owner') {
            session()->flash('error', __('common.error_cannot_remove_the_entity_owner', ['entity' => strtolower($this->getEntityName())]));

            return;
        }

        $participant->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' participant removed', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $participant->user_id,
            'removed_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_participant_removed'));
    }

    // ── Cancel Invite ──────────────────────────────────

    public function cancelInvite(string $participantId): void
    {
        $participantModel = $this->getParticipantModel();
        $entity = $this->getEntity();

        $participant = $participantModel::where('id', $participantId)
            ->where($this->getEntityIdColumn(), $entity->id)
            ->where('role', 'invited')
            ->where('status', 'pending')
            ->firstOrFail();

        $participant->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' invite cancelled', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $participant->user_id,
            'cancelled_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_invite_cancelled'));
    }

    // ── Helpers ────────────────────────────────────────

    private function findParticipant(string $participantId)
    {
        $participantModel = $this->getParticipantModel();
        $entity = $this->getEntity();

        return $participantModel::where('id', $participantId)
            ->where($this->getEntityIdColumn(), $entity->id)
            ->firstOrFail();
    }
}
