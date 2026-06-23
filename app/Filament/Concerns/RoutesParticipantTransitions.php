<?php

namespace App\Filament\Concerns;

use App\Contracts\Participant;
use App\Dto\ParticipantResult;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\ParticipantLifecycle;
use App\Services\WaitlistService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Transition actions for participants relation managers.
 *
 * Replaces the prior EditAction / DeleteAction, which wrote status and role
 * changes directly to the model and silently skipped the participant
 * lifecycle's audit trail (removed_by / removed_at), notifications,
 * capacity checks, application-record cleanup, and roster cascades. The
 * GameParticipantObserver only invalidates dashboard cache on Eloquent
 * writes — it does not re-run those side-effects — so a direct admin write
 * produced an audit-incomplete, notification-silent, roster-inconsistent
 * row that matched a lifecycle-routed one only under close inspection.
 *
 * Each action here routes through ParticipantLifecycle (or WaitlistService
 * for the queue-specific operations) so an admin-initiated transition is
 * functionally identical to a host- or user-initiated one: same audit
 * stamps, same notifications, same cascades. The admin panel no longer sits
 * outside the single lifecycle seam the deepening established.
 *
 * Consumed by GameResource and CampaignResource ParticipantsRelationManagers.
 *
 * @phpstan-ignore trait.unused
 */
trait RoutesParticipantTransitions
{
    /**
     * Build the transition action group for a relation manager table row.
     *
     * Each action carries its own visibility guard based on the participant's
     * current status and role, so only valid transitions render for a given
     * row. The prior free-form EditAction let an admin set any status from
     * any status (e.g. Approved → Pending) — an invalid state-machine move
     * the lifecycle services would reject. The action surface is always
     * correct by construction.
     *
     * @return array<int, Action>
     */
    protected function participantTransitionActions(Game|Campaign $owner): array
    {
        return [
            $this->approveApplicationAction($owner),
            $this->rejectApplicationAction($owner),
            $this->cancelInviteAction(),
            $this->promoteFromBenchAction(),
            $this->promoteFromWaitlistAction(),
            $this->removeFromWaitlistAction(),
            $this->removeParticipantAction($owner),
        ];
    }

    /**
     * Approve a pending applicant (Pending:Applicant → Approved).
     */
    protected function approveApplicationAction(Game|Campaign $owner): Action
    {
        return Action::make('approveApplication')
            ->label('Approve')
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Application')
            ->modalDescription('The applicant will be notified and moved to Approved. This is the same path as a host approving from the manage-participants page.')
            ->visible(fn ($record): bool => $this->isApplicant($record))
            ->action(fn ($record) => $this->dispatchResult(
                $record,
                fn (Participant $p, User $admin) => app(ParticipantLifecycle::class)
                    ->approveApplication($p, $owner, $admin),
            ));
    }

    /**
     * Reject a pending applicant (Pending:Applicant → deleted).
     */
    protected function rejectApplicationAction(Game|Campaign $owner): Action
    {
        return Action::make('rejectApplication')
            ->label('Reject')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject Application')
            ->modalDescription('The applicant will be notified and the application record removed. They may re-apply later.')
            ->visible(fn ($record): bool => $this->isApplicant($record))
            ->action(fn ($record) => $this->dispatchResult(
                $record,
                fn (Participant $p, User $admin) => app(ParticipantLifecycle::class)
                    ->rejectApplication($p, $owner, $admin),
            ));
    }

    /**
     * Cancel a pending invitation (Pending:Invited → deleted).
     */
    protected function cancelInviteAction(): Action
    {
        return Action::make('cancelInvite')
            ->label('Cancel Invite')
            ->icon('heroicon-o-envelope-open')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Cancel Invitation')
            ->modalDescription('The pending invitation will be withdrawn. No notification is sent to the invitee.')
            ->visible(fn ($record): bool => $this->isPendingInvite($record))
            ->action(fn ($record) => $this->dispatchResult(
                $record,
                fn (Participant $p, User $admin) => app(ParticipantLifecycle::class)
                    ->cancelInvite($p, $admin),
            ));
    }

    /**
     * Promote a benched participant to Approved (Benched → Approved).
     */
    protected function promoteFromBenchAction(): Action
    {
        return Action::make('promoteFromBench')
            ->label('Promote from Bench')
            ->icon('heroicon-o-arrow-up')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Promote from Bench')
            ->modalDescription('The benched participant moves to Approved if the entity has capacity. Capacity is checked atomically inside a lock.')
            ->visible(fn ($record): bool => $this->isStatus($record, ParticipantStatus::Benched))
            ->action(fn ($record) => $this->dispatchVoid(
                $record,
                fn (Participant $p, User $admin) => app(ParticipantLifecycle::class)
                    ->promoteFromBench($p, $admin),
                'Participant promoted from the bench.',
            ));
    }

    /**
     * Skip the FIFO queue and approve a waitlisted participant (Waitlisted → Approved).
     *
     * Deliberately may exceed max_players — the host (or admin here) decides
     * to over-seat. Delegates to WaitlistService, which owns queue semantics.
     */
    protected function promoteFromWaitlistAction(): Action
    {
        return Action::make('promoteFromWaitlist')
            ->label('Promote from Waitlist')
            ->icon('heroicon-o-arrow-up-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Promote from Waitlist (Skip Queue)')
            ->modalDescription('Skips FIFO ordering and approves this participant directly, even if others are ahead in the queue. May exceed the entity capacity.')
            ->visible(fn ($record): bool => $this->isStatus($record, ParticipantStatus::Waitlisted))
            ->action(fn ($record) => $this->dispatchVoid(
                $record,
                fn (Participant $p, User $admin) => app(WaitlistService::class)
                    ->manuallyPromote($p),
                'Participant promoted from the waitlist.',
            ));
    }

    /**
     * Remove a waitlisted participant (Waitlisted → Rejected, audited via depart()).
     */
    protected function removeFromWaitlistAction(): Action
    {
        return Action::make('removeFromWaitlist')
            ->label('Remove from Waitlist')
            ->icon('heroicon-o-arrow-down-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Remove from Waitlist')
            ->modalDescription('The participant is removed from the queue and marked Rejected. The removal is audit-stamped with your admin identity.')
            ->visible(fn ($record): bool => $this->isStatus($record, ParticipantStatus::Waitlisted))
            ->action(fn ($record) => $this->dispatchVoid(
                $record,
                fn (Participant $p, User $admin) => app(WaitlistService::class)
                    ->removeFromWaitlist($p, $admin),
                'Participant removed from the waitlist.',
            ));
    }

    /**
     * Remove a participant (any non-owner → Removed).
     *
     * Uses the Removed status (not Rejected) so the row persists for peak-roster
     * counting. The unique constraint on (entity_id, user_id) blocks re-entry.
     */
    protected function removeParticipantAction(Game|Campaign $owner): Action
    {
        return Action::make('removeParticipant')
            ->label('Remove')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Remove Participant')
            ->modalDescription('The participant is marked Removed (not hard-deleted) so roster history is preserved. They will be notified and cannot re-apply.')
            ->visible(fn ($record): bool => $this->isRemovable($record))
            ->action(fn ($record) => $this->dispatchResult(
                $record,
                fn (Participant $p, User $admin) => app(ParticipantLifecycle::class)
                    ->removeParticipant($p, $owner, $admin),
            ));
    }

    // ── Guards ──────────────────────────────────────────────

    private function isApplicant(mixed $record): bool
    {
        return $record instanceof Participant
            && $record->getStatus() === ParticipantStatus::Pending
            && $record->getRole() === ParticipantRole::Applicant;
    }

    private function isPendingInvite(mixed $record): bool
    {
        return $record instanceof Participant
            && $record->getStatus() === ParticipantStatus::Pending
            && $record->getRole() === ParticipantRole::Invited;
    }

    private function isStatus(mixed $record, ParticipantStatus $status): bool
    {
        return $record instanceof Participant
            && $record->getStatus() === $status;
    }

    private function isRemovable(mixed $record): bool
    {
        // Owner cannot be removed; Pending applicants use Reject; Pending
        // invitees use Cancel Invite. Removal applies to everyone else
        // (Approved / Benched / Waitlisted / Removed rows are re-removable).
        return $record instanceof Participant
            && $record->getRole() !== ParticipantRole::Owner
            && ! $this->isApplicant($record)
            && ! $this->isPendingInvite($record);
    }

    // ── Execution helpers ───────────────────────────────────

    /**
     * Run a ParticipantResult-returning transition and notify on the outcome.
     *
     * @param  callable(Participant, User): ParticipantResult  $transition
     */
    private function dispatchResult(mixed $record, callable $transition): void
    {
        $admin = $this->adminUser();
        if (! $record instanceof Participant || ! $admin instanceof User) {
            return;
        }

        $result = $transition($record, $admin);
        $this->notifyResult($result);
    }

    /**
     * Run a void transition (bench/waitlist ops) and notify generically.
     *
     * @param  callable(Participant, User): void  $transition
     */
    private function dispatchVoid(mixed $record, callable $transition, string $successMessage): void
    {
        $admin = $this->adminUser();
        if (! $record instanceof Participant || ! $admin instanceof User) {
            return;
        }

        try {
            $transition($record, $admin);
            Notification::make()->title($successMessage)->success()->send();
        } catch (\LogicException $e) {
            // Precondition failures (capacity, wrong status, queue state) are
            // surfaced verbatim — they carry actionable guidance for the admin.
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('The action could not be completed.')->danger()->send();
        }
    }

    private function notifyResult(ParticipantResult $result): void
    {
        $notification = Notification::make();
        if ($result->success) {
            $notification->title(__($result->messageKey, $result->messageParams))->success();
        } else {
            $notification
                ->title(__($result->errorKey ?? 'common.error_generic', $result->errorParams))
                ->danger();
        }
        $notification->send();
    }

    private function adminUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
