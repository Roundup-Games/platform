<?php

namespace App\Filament\Resources\TicketResource\Actions;

use App\Models\Review;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Review moderation actions for Safety department review_report tickets.
 *
 * Extracted verbatim from ViewTicket's perform* methods: each action owns its
 * transaction, notification, and logging.
 */
final class ReviewModeration extends TicketAction
{
    /**
     * Header actions for Safety department review_report tickets. Returns []
     * for tickets outside the domain (the page assembles the array).
     *
     * @return array<int, Action>
     */
    public static function headerActions(Ticket $ticket): array
    {
        if (! self::isReviewReport($ticket)) {
            return [];
        }

        return [
            Action::make('dismissReport')
                ->label('Dismiss Report')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Dismiss Review Report')
                ->modalDescription('This will close the ticket and keep the review published. The review will remain visible.')
                ->modalSubmitActionLabel('Dismiss')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::dismissReport($ticket)),

            Action::make('removeReview')
                ->label('Remove Review')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove Review')
                ->modalDescription('This will close the ticket AND hide the review. The review will no longer be publicly visible.')
                ->modalSubmitActionLabel('Remove')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::removeReview($ticket)),

            Action::make('escalateReport')
                ->label('Escalate')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Escalate Review Report')
                ->modalDescription('This will reassign the ticket to a Platform Admin and increase priority to Urgent.')
                ->modalSubmitActionLabel('Escalate')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::escalateReport($ticket)),
        ];
    }

    /**
     * Dismiss the report: close ticket, keep review published.
     */
    public static function dismissReport(Ticket $ticket): void
    {
        try {
            $user = self::currentUser();
            if ($user === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            DB::transaction(function () use ($ticket, $user, $ticketService) {
                // Restore review to published status before closing
                self::restoreReviewStatus($ticket, 'published');

                // Add internal note after review update succeeds
                $ticketService->addNote($ticket, $user, 'Report dismissed by admin');

                // Close the ticket
                $ticketService->close($ticket, $user);
            });

            Log::info('review.report.dismissed', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'review_id' => $ticket->metadata['review_id'] ?? null,
                'admin_id' => $user->id,
            ]);

            Notification::make()
                ->success()
                ->title('Report dismissed')
                ->body('The ticket has been closed and the review remains published.')
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to dismiss review report', 'Dismiss failed', $e);
        }
    }

    /**
     * Remove the review: close ticket, hide review.
     */
    public static function removeReview(Ticket $ticket): void
    {
        try {
            $user = self::currentUser();
            if ($user === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            DB::transaction(function () use ($ticket, $user, $ticketService) {
                // Hide the review before closing
                self::restoreReviewStatus($ticket, 'hidden');

                // Add internal note after review update succeeds
                $ticketService->addNote($ticket, $user, 'Review removed by admin');

                // Close the ticket
                $ticketService->close($ticket, $user);
            });

            Log::info('review.report.removed', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'review_id' => $ticket->metadata['review_id'] ?? null,
                'admin_id' => $user->id,
            ]);

            Notification::make()
                ->success()
                ->title('Review removed')
                ->body('The ticket has been closed and the review has been hidden.')
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to remove review', 'Remove failed', $e);
        }
    }

    /**
     * Escalate review report: reassign to a Platform Admin, increase priority
     * to Urgent.
     */
    public static function escalateReport(Ticket $ticket): void
    {
        try {
            $user = self::currentUser();
            if ($user === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            $assignmentInfo = null;
            DB::transaction(function () use ($ticket, $user, $ticketService, &$assignmentInfo) {
                // Add internal note
                $ticketService->addNote($ticket, $user, "Escalated by {$user->name}");

                // Increase priority to Urgent
                $ticketService->changePriority($ticket, TicketPriority::Urgent, $user);

                // Find a Platform Admin to assign to
                $assignmentInfo = static::findPlatformAdminForEscalation($user);
                ['admin' => $platformAdmin] = $assignmentInfo;

                if ($platformAdmin->isNot($user)) {
                    // Ticket::assign() is UUID-safe since escalated-laravel v1.4.0
                    // (TicketAssigned.$agentId is int|string) and fires the event,
                    // activity log, and broadcast that updateQuietly suppressed.
                    // Deferred to afterCommit(): the DispatchWebhook listener does a
                    // blocking Http::timeout(10) call, which would hold the row
                    // lock for the whole transaction and risk send-on-rollback.
                    $admin = $platformAdmin;
                    DB::afterCommit(fn () => $ticket->assign($admin, $user));
                }
            });

            if ($assignmentInfo === null) {
                return;
            }
            ['admin' => $platformAdmin, 'assigned_name' => $assignedName] = $assignmentInfo;

            Log::info('review.report.escalated', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'review_id' => $ticket->metadata['review_id'] ?? null,
                'escalated_by' => $user->id,
                'assigned_to' => $platformAdmin->id,
            ]);

            Notification::make()
                ->warning()
                ->title('Report escalated')
                ->body("Priority set to Urgent and reassigned to {$assignedName}.")
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to escalate review report', 'Escalation failed', $e);
        }
    }

    /**
     * Update the review status based on the ticket metadata.
     * The ReviewObserver will handle aggregate recalculation via the 'updated' hook.
     */
    private static function restoreReviewStatus(Ticket $ticket, string $status): void
    {
        $reviewId = $ticket->metadata['review_id'] ?? null;

        if (! $reviewId) {
            throw new \RuntimeException('Review ID is missing from ticket metadata.');
        }

        $review = Review::find(self::asString($reviewId));

        if (! $review) {
            throw new \RuntimeException('Review '.self::asString($reviewId).' was not found.');
        }

        $review->update(['status' => $status]);

        Log::info('review.status.updated_from_ticket', [
            'review_id' => $review->id,
            'new_status' => $status,
            'ticket_id' => $ticket->id,
        ]);
    }

    /**
     * Check if the ticket is a review report in the Safety department.
     */
    private static function isReviewReport(Ticket $ticket): bool
    {
        return ($ticket->ticket_type ?? null) === 'review_report'
            && ($ticket->department->name ?? null) === 'Safety';
    }
}
