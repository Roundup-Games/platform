<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Models\GameSystem;
use App\Models\Review;
use App\Services\BggClient;
use App\Services\BggXmlParser;
use App\Services\GameSystemRequestService;
use Escalated\Filament\Resources\TicketResource\Pages\ViewTicket as BaseViewTicket;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

/**
 * Custom ViewTicket page extending the Escalated vendor ViewTicket.
 *
 * Adds game-system-specific actions for BGG sync on tickets in the
 * Game Systems department with ticket_type=game_system_request:
 *
 * - "Sync from BGG" — syncs GameSystem using bgg_url from ticket metadata
 * - "Search BGG" — searches BGG, previews data, and optionally syncs
 *
 * Adds review moderation actions for Safety department review_report tickets:
 *
 * - "Dismiss Report" — closes ticket, keeps review published
 * - "Remove Review" — closes ticket, hides review
 * - "Escalate" — reassigns to Platform Admin, increases priority to Urgent
 */
class ViewTicket extends BaseViewTicket
{
    protected static string $resource = TicketResource::class;

    /**
     * BGG search results stored in component state.
     * @var array<int, array{bgg_id: int, name: string, year_released: int|null, bgg_type: string}>
     */
    public array $bggSearchResults = [];

    /**
     * The selected BGG ID from search results.
     */
    public ?int $selectedBggId = null;

    /**
     * The selected BGG item name (for display).
     */
    public ?string $selectedBggName = null;

    /**
     * Full BGG thing data for the selected game, fetched for preview.
     * @var array|null
     */
    public ?array $bggPreviewData = null;

    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();

        // Insert review moderation actions for Safety department review_report tickets
        $reviewActions = $this->getReviewModerationActions();
        if (! empty($reviewActions)) {
            array_splice($actions, 0, 0, $reviewActions);
        }

        // Insert game-system-specific actions before the generic resolve/close actions
        $gameSystemActions = $this->getGameSystemActions();

        if (! empty($gameSystemActions)) {
            // Prepend game system actions at the beginning
            array_splice($actions, 0, 0, $gameSystemActions);
        }

        return $actions;
    }

    /**
     * Get game-system-specific actions. Only visible on game system request tickets.
     */
    protected function getGameSystemActions(): array
    {
        $ticket = $this->getRecord();

        if (! $ticket || ! $this->isGameSystemRequest($ticket)) {
            return [];
        }

        return [
            Action::make('syncFromBgg')
                ->label('Sync from BGG')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sync Game System from BGG')
                ->modalDescription(function () use ($ticket) {
                    $bggUrl = $ticket->metadata['bgg_url'] ?? null;

                    return $bggUrl
                        ? "This will sync game data from BGG using the URL: {$bggUrl}"
                        : 'No BGG URL found in ticket metadata.';
                })
                ->modalSubmitActionLabel('Sync Now')
                ->action(function () use ($ticket) {
                    $this->performBggSync($ticket);
                })
                ->visible(function () use ($ticket) {
                    return ! empty($ticket->metadata['bgg_url'] ?? null);
                }),

            Action::make('searchBgg')
                ->label('Search BGG')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->modalHeading('Search BoardGameGeek')
                ->modalDescription('Search for the requested game on BGG to link BGG data.')
                ->modalSubmitActionLabel('Search')
                ->modalWidth('4xl')
                ->schema([
                    TextInput::make('bgg_search_query')
                        ->label('Search Query')
                        ->placeholder('e.g. Ticket to Ride, Catan, Gloomhaven')
                        ->required()
                        ->maxLength(255)
                        ->default(function () use ($ticket) {
                            $service = app(GameSystemRequestService::class);

                            return $service->extractName($ticket);
                        }),
                    Placeholder::make('bgg_search_results_display')
                        ->label('Search Results')
                        ->hidden(fn () => empty($this->bggSearchResults))
                        ->content(fn () => $this->renderSearchResultsTable()),
                    Placeholder::make('bgg_selected_display')
                        ->label('Selected BGG Game')
                        ->hidden(fn () => $this->selectedBggId === null)
                        ->content(fn () => new HtmlString(
                            '<div class="fi-section rounded-xl bg-primary-50 p-3 dark:bg-primary-900/20">'
                            . '<span class="font-medium text-primary-700 dark:text-primary-300">' . e($this->selectedBggName) . '</span>'
                            . ' <span class="text-gray-500">(BGG ID: ' . $this->selectedBggId . ')</span>'
                            . '</div>'
                        )),
                    Placeholder::make('bgg_no_results_display')
                        ->label('No results found')
                        ->hidden(fn () => ! empty($this->bggSearchResults) || $this->selectedBggId !== null)
                        ->content(new HtmlString('<p class="text-gray-500">Enter a query and click Search.</p>')),
                ])
                ->action(function (array $data) {
                    $query = $data['bgg_search_query'] ?? '';

                    if (empty(trim($query))) {
                        return;
                    }

                    $this->performBggSearch($query);
                })
                ->modalFooterActions(fn (Action $action) => [
                    $action->getModalSubmitAction(),
                    Action::make('syncSelectedBgg')
                        ->label('Sync Selected')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('success')
                        ->visible(fn () => $this->selectedBggId !== null)
                        ->action(function () {
                            $this->performBggSyncById($this->selectedBggId);
                        }),
                    Action::make('clearBggSelection')
                        ->label('Clear Selection')
                        ->color('gray')
                        ->visible(fn () => $this->selectedBggId !== null)
                        ->action(function () {
                            $this->selectedBggId = null;
                            $this->selectedBggName = null;
                            $this->bggPreviewData = null;
                        }),
                    $action->getModalCancelAction(),
                ]),

            Action::make('createManual')
                ->label('Create Manually')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Create Game System Manually')
                ->modalDescription('Create a GameSystem from the request data without BGG sync.')
                ->modalSubmitActionLabel('Create')
                ->action(function () use ($ticket) {
                    $this->performManualCreate($ticket);
                })
                ->visible(function () use ($ticket) {
                    return empty($ticket->metadata['game_system_id'] ?? null);
                }),
        ];
    }

    /**
     * Select a BGG search result by index.
     * Called from the rendered results table via Livewire.dispatch.
     */
    #[On('selectBggResult')]
    public function selectBggResult(int $index): void
    {
        if (! isset($this->bggSearchResults[$index])) {
            return;
        }

        $result = $this->bggSearchResults[$index];
        $this->selectedBggId = $result['bgg_id'];
        $this->selectedBggName = $result['name'];

        // Fetch full thing data for preview
        $this->fetchBggPreview($result['bgg_id']);

        Notification::make()
            ->success()
            ->title('BGG game selected')
            ->body("Selected: {$this->selectedBggName} (ID: {$this->selectedBggId})")
            ->send();
    }

    /**
     * Get review moderation actions. Only visible on Safety department review_report tickets
     * that are still open (not closed/resolved).
     */
    protected function getReviewModerationActions(): array
    {
        $ticket = $this->getRecord();

        if (! $ticket || ! $this->isReviewReport($ticket)) {
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
                ->action(function () use ($ticket) {
                    $this->performDismissReport($ticket);
                }),

            Action::make('removeReview')
                ->label('Remove Review')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove Review')
                ->modalDescription('This will close the ticket AND hide the review. The review will no longer be publicly visible.')
                ->modalSubmitActionLabel('Remove')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket) {
                    $this->performRemoveReview($ticket);
                }),

            Action::make('escalateReport')
                ->label('Escalate')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Escalate Review Report')
                ->modalDescription('This will reassign the ticket to a Platform Admin and increase priority to Urgent.')
                ->modalSubmitActionLabel('Escalate')
                ->visible(fn () => $ticket->isOpen())
                ->action(function () use ($ticket) {
                    $this->performEscalateReport($ticket);
                }),
        ];
    }

    /**
     * Check if the ticket is a review report in the Safety department.
     */
    protected function isReviewReport(Ticket $ticket): bool
    {
        return ($ticket->ticket_type ?? null) === 'review_report'
            && ($ticket->department?->name ?? null) === 'Safety';
    }

    /**
     * Dismiss the report: close ticket, keep review published.
     */
    protected function performDismissReport(Ticket $ticket): void
    {
        try {
            $user = auth()->user();
            $ticketService = app(TicketService::class);

            // Add internal note before closing
            $ticketService->addNote($ticket, $user, 'Report dismissed by admin');

            // Close the ticket
            $ticketService->close($ticket, $user);

            // Restore review to published status if it's currently 'reported'
            $this->restoreReviewStatus($ticket, 'published');

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
            Log::error('Failed to dismiss review report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Dismiss failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Remove the review: close ticket, hide review.
     */
    protected function performRemoveReview(Ticket $ticket): void
    {
        try {
            $user = auth()->user();
            $ticketService = app(TicketService::class);

            // Add internal note before closing
            $ticketService->addNote($ticket, $user, 'Review removed by admin');

            // Close the ticket
            $ticketService->close($ticket, $user);

            // Hide the review
            $this->restoreReviewStatus($ticket, 'hidden');

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
            Log::error('Failed to remove review', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Remove failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Escalate the report: reassign to Platform Admin role, increase priority to Urgent.
     */
    protected function performEscalateReport(Ticket $ticket): void
    {
        try {
            $user = auth()->user();
            $ticketService = app(TicketService::class);

            // Add internal note
            $ticketService->addNote($ticket, $user, "Escalated by {$user->name}");

            // Increase priority to Urgent
            $ticketService->changePriority($ticket, TicketPriority::Urgent, $user);

            // Find a Platform Admin to assign to
            $platformAdmin = \App\Models\User::role('Platform Admin')
                ->where('id', '!=', $user->id)
                ->first();

            if ($platformAdmin) {
                // Update assigned_to directly to avoid TicketAssigned event type mismatch
                // (vendor event expects int agentId but our User model uses UUID)
                $ticket->updateQuietly(['assigned_to' => $platformAdmin->id]);
                $ticket->logActivity(
                    \Escalated\Laravel\Enums\ActivityType::Assigned,
                    $user,
                    ['agent_id' => $platformAdmin->id]
                );
                $assignedName = $platformAdmin->name;
            } else {
                // If no other Platform Admin, keep assigned to current user
                $assignedName = $user->name;
            }

            Log::info('review.report.escalated', [
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'review_id' => $ticket->metadata['review_id'] ?? null,
                'escalated_by' => $user->id,
                'assigned_to' => $platformAdmin?->id ?? $user->id,
            ]);

            Notification::make()
                ->warning()
                ->title('Report escalated')
                ->body("Priority set to Urgent and reassigned to {$assignedName}.")
                ->send();

        } catch (\Throwable $e) {
            Log::error('Failed to escalate review report', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Escalation failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Update the review status based on the ticket metadata.
     * The ReviewObserver will handle aggregate recalculation via the 'updated' hook.
     */
    protected function restoreReviewStatus(Ticket $ticket, string $status): void
    {
        $reviewId = $ticket->metadata['review_id'] ?? null;

        if (! $reviewId) {
            Log::warning('review.report.no_review_id_in_ticket', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        $review = Review::find($reviewId);

        if (! $review) {
            Log::warning('review.report.review_not_found', [
                'ticket_id' => $ticket->id,
                'review_id' => $reviewId,
            ]);

            return;
        }

        $review->update(['status' => $status]);

        Log::info('review.status.updated_from_ticket', [
            'review_id' => $review->id,
            'new_status' => $status,
            'ticket_id' => $ticket->id,
        ]);
    }

    /**
     * Check if the ticket is a game system request.
     */
    protected function isGameSystemRequest(Ticket $ticket): bool
    {
        return app(GameSystemRequestService::class)->isGameSystemRequestTicket($ticket);
    }

    /**
     * Perform BGG sync using the bgg_url from ticket metadata.
     */
    protected function performBggSync(Ticket $ticket): void
    {
        try {
            $service = app(GameSystemRequestService::class);
            $gameSystem = $service->syncBggFromTicket($ticket);

            Notification::make()
                ->success()
                ->title('BGG sync complete')
                ->body("GameSystem \"{$gameSystem->name}\" has been synced from BGG.")
                ->send();

            Log::info('BGG sync completed from ticket ViewTicket page', [
                'ticket_id' => $ticket->id,
                'game_system_id' => $gameSystem->id,
                'game_system_name' => $gameSystem->name,
            ]);

        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->warning()
                ->title('Cannot sync')
                ->body($e->getMessage())
                ->send();
        } catch (\Throwable $e) {
            Log::error('BGG sync failed from ticket ViewTicket page', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('BGG sync failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Perform BGG sync using a specific BGG ID (from search).
     */
    protected function performBggSyncById(int $bggId): void
    {
        $ticket = $this->getRecord();

        try {
            $result = app(\App\Services\BggSyncService::class)->syncGameSystems([$bggId]);

            if ($result['failed'] > 0 && $result['synced'] === 0) {
                throw new \RuntimeException(
                    'BGG sync failed: ' . implode('; ', $result['errors'])
                );
            }

            $gameSystem = GameSystem::where('bgg_id', $bggId)->first();

            if (! $gameSystem) {
                throw new \RuntimeException("BGG sync completed but GameSystem not found for bgg_id={$bggId}.");
            }

            // Update ticket metadata with bgg_url and game_system_id
            $metadata = $ticket->metadata ?? [];
            $metadata['bgg_url'] = "https://boardgamegeek.com/boardgame/{$bggId}";
            $metadata['game_system_id'] = $gameSystem->id;
            $ticket->updateQuietly(['metadata' => $metadata]);

            Notification::make()
                ->success()
                ->title('BGG sync complete')
                ->body("GameSystem \"{$gameSystem->name}\" has been synced from BGG.")
                ->send();

            Log::info('BGG sync completed from ticket search', [
                'ticket_id' => $ticket->id,
                'bgg_id' => $bggId,
                'game_system_id' => $gameSystem->id,
                'game_system_name' => $gameSystem->name,
            ]);

        } catch (\Throwable $e) {
            Log::error('BGG sync from search failed', [
                'ticket_id' => $ticket->id,
                'bgg_id' => $bggId,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('BGG sync failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Perform manual GameSystem creation from ticket metadata.
     */
    protected function performManualCreate(Ticket $ticket): void
    {
        try {
            $service = app(GameSystemRequestService::class);
            $gameSystem = $service->createManualFromTicket($ticket);

            Notification::make()
                ->success()
                ->title('GameSystem created')
                ->body("GameSystem \"{$gameSystem->name}\" has been created manually.")
                ->send();

        } catch (\Throwable $e) {
            Log::error('Manual GameSystem creation failed from ticket page', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Creation failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Perform a BGG search and store results in component state.
     */
    protected function performBggSearch(string $query): void
    {
        try {
            $xml = app(BggClient::class)->search($query);
            $results = app(BggXmlParser::class)->parseSearchResults($xml->asXML());

            $this->bggSearchResults = $results;

            if (empty($results)) {
                Notification::make()
                    ->info()
                    ->title('No results')
                    ->body("No BGG results found for \"{$query}\".")
                    ->send();
            } else {
                Notification::make()
                    ->success()
                    ->title('Search complete')
                    ->body(count($results) . ' result(s) found.')
                    ->send();
            }
        } catch (\Throwable $e) {
            $this->bggSearchResults = [];

            Notification::make()
                ->danger()
                ->title('BGG Search Failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Fetch full BGG thing data and store it for preview display.
     */
    protected function fetchBggPreview(int $bggId): void
    {
        try {
            $xml = app(BggClient::class)->fetchThing([$bggId]);
            $items = app(BggXmlParser::class)->parseItems($xml->asXML());

            $this->bggPreviewData = $items[0] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch BGG preview data', [
                'bgg_id' => $bggId,
                'error' => $e->getMessage(),
            ]);
            $this->bggPreviewData = null;

            Notification::make()
                ->warning()
                ->title('Preview unavailable')
                ->body('Could not fetch full BGG data for preview. You can still sync the game.')
                ->send();
        }
    }

    /**
     * Render the BGG search results as an HTML table with Select buttons.
     */
    protected function renderSearchResultsTable(): HtmlString
    {
        if (empty($this->bggSearchResults)) {
            return new HtmlString('');
        }

        $rows = '';
        foreach ($this->bggSearchResults as $index => $result) {
            $isSelected = $this->selectedBggId === $result['bgg_id'];
            $selectedBadge = $isSelected
                ? ' <span class="inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-400">Selected</span>'
                : '';

            $typeLabel = match ($result['bgg_type'] ?? '') {
                'boardgame' => 'Board Game',
                'boardgameexpansion' => 'Expansion',
                'boardgameaccessory' => 'Accessory',
                default => $result['bgg_type'] ?? 'Unknown',
            };

            $selectButton = $isSelected
                ? '<span class="text-primary-600 dark:text-primary-400 text-xs font-medium">✓ Selected</span>'
                : '<button type="button" onclick="Livewire.dispatch(\'selectBggResult\', { index: ' . $index . ' })" class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">Select</button>';

            $rows .= '<tr class="border-b border-gray-100 dark:border-gray-700">'
                . '<td class="px-3 py-2 text-sm">' . e($result['name']) . $selectedBadge . '</td>'
                . '<td class="px-3 py-2 text-sm text-center">' . ($result['year_released'] ?? '—') . '</td>'
                . '<td class="px-3 py-2 text-sm">' . e($typeLabel) . '</td>'
                . '<td class="px-3 py-2 text-sm text-center font-mono">' . $result['bgg_id'] . '</td>'
                . '<td class="px-3 py-2 text-sm text-center">' . $selectButton . '</td>'
                . '</tr>';
        }

        return new HtmlString(
            '<div class="overflow-x-auto">'
            . '<table class="w-full text-left text-sm">'
            . '<thead class="border-b border-gray-200 bg-gray-50 dark:bg-gray-800">'
            . '<tr>'
            . '<th class="px-3 py-2 font-medium">Name</th>'
            . '<th class="px-3 py-2 font-medium text-center">Year</th>'
            . '<th class="px-3 py-2 font-medium">Type</th>'
            . '<th class="px-3 py-2 font-medium text-center">BGG ID</th>'
            . '<th class="px-3 py-2 font-medium text-center">Action</th>'
            . '</tr>'
            . '</thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>'
            . '</div>'
        );
    }
}
