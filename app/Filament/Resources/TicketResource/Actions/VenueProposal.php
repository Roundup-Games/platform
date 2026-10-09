<?php

namespace App\Filament\Resources\TicketResource\Actions;

use App\Services\VenueProposalService;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Venue proposal actions for Events department venue_proposal tickets.
 *
 * Extracted verbatim from ViewTicket's perform* methods: each action owns its
 * transaction, notification, and logging.
 */
final class VenueProposal extends TicketAction
{
    /**
     * Header actions for Events department venue_proposal tickets. Returns []
     * for tickets outside the domain (the page assembles the array).
     *
     * @return array<int, Action>
     */
    public static function headerActions(Ticket $ticket): array
    {
        if (! app(VenueProposalService::class)->isVenueProposalTicket($ticket)) {
            return [];
        }

        return [
            Action::make('approveVenueProposal')
                ->label('Approve Venue')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve Venue Proposal')
                ->modalDescription(function () use ($ticket) {
                    $name = isset($ticket->metadata['venue_name']) ? self::asString($ticket->metadata['venue_name']) : ($ticket->subject ?? '');
                    $existing = $ticket->metadata['existing_location_id'] ?? null;
                    if ($existing) {
                        return 'This will update the existing location (ID: '.self::asString($existing).') with the proposed venue details and mark it as verified.';
                    }

                    return 'This will create a new verified location for "'.$name.'" and resolve the ticket.';
                })
                ->modalSubmitActionLabel('Approve')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn () => self::approve($ticket)),

            Action::make('rejectVenueProposal')
                ->label('Reject Venue')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->modalHeading('Reject Venue Proposal')
                ->modalDescription('Please provide a reason for rejecting this venue proposal.')
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Rejection reason')
                        ->placeholder('e.g. Venue does not meet community guidelines, duplicate entry, etc.')
                        ->required()
                        ->maxLength(1000),
                ])
                ->modalSubmitActionLabel('Reject')
                ->visible(fn () => $ticket->isOpen())
                ->action(fn (array $data) => self::reject($ticket, $data['rejection_reason'])),
        ];
    }

    /**
     * Approve a venue proposal: create/update Location with is_verified=true, resolve ticket.
     */
    public static function approve(Ticket $ticket): void
    {
        try {
            $admin = self::currentUser();
            if ($admin === null) {
                return;
            }
            $ticketService = app(TicketService::class);
            $proposalService = app(VenueProposalService::class);

            $location = DB::transaction(function () use ($ticket, $admin, $ticketService, $proposalService) {
                $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                if (! $lockedTicket->isOpen()) {
                    throw new \RuntimeException('This ticket is no longer open.');
                }

                // Use VenueProposalService to create/update the location
                $location = $proposalService->approveProposal($lockedTicket);

                // Add internal note linking to the created/updated location
                $ticketService->addNote($lockedTicket, $admin, "Venue proposal approved. Location: {$location->name} (ID: {$location->id})");

                // Resolve the ticket
                $ticketService->resolve($lockedTicket, $admin);

                return $location;
            });

            Log::info('venue_proposal.approved_by_admin', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'location_id' => $location->id,
                'location_name' => $location->name,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->success()
                ->title('Venue approved')
                ->body("The venue \"{$location->name}\" has been created/updated as a verified location.")
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to approve venue proposal', 'Approval failed', $e);
        }
    }

    /**
     * Reject a venue proposal: resolve ticket with rejection reason, no location changes.
     */
    public static function reject(Ticket $ticket, string $reason): void
    {
        try {
            $admin = self::currentUser();
            if ($admin === null) {
                return;
            }
            $ticketService = app(TicketService::class);

            DB::transaction(function () use ($ticket, $admin, $ticketService, $reason) {
                $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                if (! $lockedTicket->isOpen()) {
                    throw new \RuntimeException('This ticket is no longer open.');
                }

                // Add reply with rejection reason
                $ticketService->reply($lockedTicket, $admin, "Venue proposal rejected: {$reason}");

                // Add internal note
                $ticketService->addNote($lockedTicket, $admin, "Venue proposal rejected. Reason: {$reason}");

                // Resolve the ticket
                $ticketService->resolve($lockedTicket, $admin);
            });

            Log::info('venue_proposal.rejected_by_admin', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'reason' => $reason,
                'admin_id' => $admin->id,
            ]);

            Notification::make()
                ->warning()
                ->title('Venue proposal rejected')
                ->body('The ticket has been resolved with the rejection reason.')
                ->send();

        } catch (\Throwable $e) {
            self::fail($ticket, 'Failed to reject venue proposal', 'Rejection failed', $e);
        }
    }
}
