<?php

namespace App\Filament\Resources\TicketResource\Actions;

use App\Services\VenueClaimService;
use Escalated\Laravel\Models\Ticket;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;

/**
 * Venue claim actions for Events department venue_claim tickets.
 *
 * Extracted verbatim from ViewTicket's perform* methods: each action owns its
 * notification and logging (the Location mutation itself lives in
 * VenueClaimService).
 */
final class VenueClaim extends TicketAction
{
    /**
     * Header actions for Events department venue_claim tickets. Returns []
     * for tickets outside the domain (the page assembles the array).
     *
     * @return array<int, Action>
     */
    public static function headerActions(Ticket $ticket): array
    {
        if (! app(VenueClaimService::class)->isVenueClaimTicket($ticket)) {
            return [];
        }

        return [
            Action::make('approveVenueClaim')
                ->label('Approve Claim')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve Venue Claim')
                ->modalDescription(function () use ($ticket) {
                    $name = isset($ticket->metadata['location_name'])
                        ? self::asString($ticket->metadata['location_name'])
                        : ($ticket->subject ?? '');
                    $actor = $ticket->metadata['actor'] ?? null;
                    $claimant = is_array($actor) && isset($actor['name'])
                        ? self::asString($actor['name'])
                        : 'the claimant';

                    return 'This will assign management of "'.$name.'" to '.$claimant.' and resolve the ticket. The location address is not changed.';
                })
                ->modalSubmitActionLabel('Approve')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::approve($ticket)),

            Action::make('rejectVenueClaim')
                ->label('Reject Claim')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->modalHeading('Reject Venue Claim')
                ->modalDescription('Please provide a reason for rejecting this venue claim.')
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Rejection reason')
                        ->placeholder('e.g. Cannot verify the claimant\'s association with this venue, duplicate claim, etc.')
                        ->required()
                        ->maxLength(1000),
                ])
                ->modalSubmitActionLabel('Reject')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn (array $data) => self::reject($ticket, $data['rejection_reason'])),
        ];
    }

    /**
     * Approve a venue claim: assign management of the existing Location to the
     * claimant and resolve the ticket.
     *
     * VenueClaimService::approveClaim owns the pessimistic-lock transaction,
     * the open-ticket guard, the managed_by mutation, and the TicketService
     * note + resolve (see T02). This action stays thin on purpose: re-doing
     * the lock, note, or resolve here would double-execute (e.g. resolving an
     * already-resolved ticket).
     */
    public static function approve(Ticket $ticket): void
    {
        try {
            $admin = self::currentUser();
            if ($admin === null) {
                return;
            }

            $claimService = app(VenueClaimService::class);

            // approveClaim owns lockForUpdate + isOpen guard + managed_by + note + resolve.
            $location = $claimService->approveClaim($ticket);

            Log::info('venue_claim.approved_by_admin', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'location_id' => $location->id,
                'location_name' => $location->name,
                'claimant_id' => $location->managed_by,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title('Venue claim approved')
                ->body("The venue \"{$location->name}\" is now managed by the claimant.")
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to approve venue claim', 'Approval failed', $e);
        }
    }

    /**
     * Reject a venue claim: resolve ticket with rejection reason, no Location changes.
     *
     * VenueClaimService::rejectClaim owns the reply + note + resolve (T02).
     */
    public static function reject(Ticket $ticket, string $reason): void
    {
        try {
            $admin = self::currentUser();
            if ($admin === null) {
                return;
            }

            $claimService = app(VenueClaimService::class);

            // rejectClaim owns reply + note + resolve. No Location mutation.
            $claimService->rejectClaim($ticket, $reason);

            Log::info('venue_claim.rejected_by_admin', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'reason' => $reason,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->warning()
                ->title('Venue claim rejected')
                ->body('The ticket has been resolved with the rejection reason.')
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to reject venue claim', 'Rejection failed', $e);
        }
    }
}
