<?php

namespace App\Filament\Resources\TicketResource\Actions;

use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\GameSystem;
use App\Services\BggSyncService;
use App\Services\GameSystemRequestService;
use Escalated\Laravel\Models\Ticket;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/**
 * Game-system-request actions (Game Systems department tickets with
 * ticket_type=game_system_request).
 *
 * Extracted verbatim from ViewTicket's perform* methods: each action owns its
 * notification and logging. The BGG search flow mutates the Livewire
 * component's public search state, so it receives the component explicitly.
 */
final class GameSystemRequest extends TicketAction
{
    /**
     * Header actions for game system request tickets. Returns [] for tickets
     * outside the domain (the page assembles the array).
     *
     * @return array<int, Action>
     */
    public static function headerActions(ViewTicket $component, Ticket $ticket): array
    {
        if (! self::isGameSystemRequest($ticket)) {
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
                        ? 'This will sync game data from BGG using the URL: '.self::asString($bggUrl)
                        : 'No BGG URL found in ticket metadata.';
                })
                ->modalSubmitActionLabel('Sync Now')
                ->action(fn () => self::bggSync($ticket))
                ->visible(fn () => ! empty($ticket->metadata['bgg_url'] ?? null)),

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
                        ->live()
                        ->afterStateUpdated(fn ($state) => $component->bggSearchQuery = $state)
                        ->default(function () use ($component, $ticket) {
                            $service = app(GameSystemRequestService::class);
                            $name = $service->extractName($ticket);
                            // Seed the public property so the first Search click
                            // works without the user re-typing the pre-filled value.
                            $component->bggSearchQuery = $name;

                            return $name;
                        }),
                    Placeholder::make('bgg_search_results_display')
                        ->label('Search Results')
                        ->hidden(fn () => empty($component->bggSearchResults))
                        ->content(fn () => $component->renderSearchResultsTable()),
                    Placeholder::make('bgg_selected_display')
                        ->label('Selected BGG Game')
                        ->hidden(fn () => $component->selectedBggId === null)
                        ->content(fn () => new HtmlString(
                            '<div class="fi-section rounded-xl bg-primary-50 p-3 dark:bg-primary-900/20">'
                            .'<span class="font-medium text-primary-700 dark:text-primary-300">'.e($component->selectedBggName).'</span>'
                            .' <span class="text-gray-500">(BGG ID: '.$component->selectedBggId.')</span>'
                            .'</div>'
                        )),
                    Placeholder::make('bgg_no_results_display')
                        ->label('No results found')
                        ->hidden(fn () => ! empty($component->bggSearchResults) || $component->selectedBggId !== null)
                        ->content(new HtmlString('<p class="text-gray-500">Enter a query and click Search.</p>')),
                ])
                ->modalSubmitActionLabel('Search')
                ->modalFooterActions(fn (Action $action) => [
                    Action::make('bggSearch')
                        ->label('Search')
                        ->icon(Heroicon::OutlinedMagnifyingGlass)
                        // Read the query from the public $bggSearchQuery property,
                        // synced live from the TextInput via afterStateUpdated.
                        // Cannot use Get $get or form state here because modal
                        // footer actions are standalone Action objects with no
                        // schema-component binding.
                        ->action(function () use ($component) {
                            $query = self::asString($component->bggSearchQuery ?? '');
                            if (! empty(trim($query))) {
                                self::searchBgg($component, $query);
                            }
                        })
                        ->close(false),
                    Action::make('syncSelectedBgg')
                        ->label('Sync Selected')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('success')
                        ->visible(fn () => $component->selectedBggId !== null)
                        ->action(function () use ($component) {
                            if ($component->selectedBggId === null) {
                                return;
                            }

                            /** @var Ticket $ticket */
                            $ticket = $component->getRecord();
                            self::bggSyncById($ticket, $component->selectedBggId);
                        }),
                    Action::make('clearBggSelection')
                        ->label('Clear Selection')
                        ->color('gray')
                        ->visible(fn () => $component->selectedBggId !== null)
                        ->close(false)
                        ->action(function () use ($component) {
                            $component->selectedBggId = null;
                            $component->selectedBggName = null;
                            $component->bggPreviewData = null;
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
                ->action(fn () => self::manualCreate($ticket))
                ->visible(fn () => empty($ticket->metadata['game_system_id'] ?? null)),
        ];
    }

    /**
     * Perform BGG sync using the bgg_url from ticket metadata.
     */
    public static function bggSync(Ticket $ticket): void
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
            self::fail($ticket, 'BGG sync failed from ticket ViewTicket page', 'BGG sync failed', $e);
        }
    }

    /**
     * Perform BGG sync using a specific BGG ID (from search).
     */
    public static function bggSyncById(Ticket $ticket, int $bggId): void
    {
        try {
            $result = app(BggSyncService::class)->syncGameSystems([$bggId]);

            if ($result->failed > 0 && $result->synced === 0) {
                throw new \RuntimeException(
                    'BGG sync failed: '.implode('; ', $result->errors)
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
            self::fail($ticket, 'BGG sync from search failed', 'BGG sync failed', $e, ['bgg_id' => $bggId]);
        }
    }

    /**
     * Perform manual GameSystem creation from ticket metadata.
     */
    public static function manualCreate(Ticket $ticket): void
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
            self::fail($ticket, 'Manual GameSystem creation failed from ticket page', 'Creation failed', $e);
        }
    }

    /**
     * Run a BGG search and store results in component state.
     *
     * Component-state choreography (selection reset before + result reset on
     * failure); the query itself and its toasts live in search().
     */
    public static function searchBgg(ViewTicket $component, string $query): void
    {
        // Clear previous selection before running new search
        $component->selectedBggId = null;
        $component->selectedBggName = null;
        $component->bggPreviewData = null;

        try {
            $component->bggSearchResults = self::search($query);
        } catch (\Throwable) {
            $component->bggSearchResults = [];
            $component->selectedBggId = null;
            $component->selectedBggName = null;
            $component->bggPreviewData = null;
        }
    }

    /**
     * Run a BGG query, toast the outcome, and return the results.
     * Sends the failure toast and rethrows so the caller can reset state.
     *
     * @return array<int, array{bgg_id: int, name: string, year_released: int|null, bgg_type: string}>
     */
    public static function search(string $query): array
    {
        try {
            $results = app(BggSyncService::class)->search($query);
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('BGG Search Failed')
                ->body($e->getMessage())
                ->send();

            throw $e;
        }

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
                ->body(count($results).' result(s) found.')
                ->send();
        }

        return $results;
    }

    /**
     * Check if the ticket is a game system request.
     */
    private static function isGameSystemRequest(Ticket $ticket): bool
    {
        return app(GameSystemRequestService::class)->isGameSystemRequestTicket($ticket);
    }
}
