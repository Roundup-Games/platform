<?php

namespace App\Filament\Resources\GameSystemRequestResource\Pages;

use App\Exceptions\BggApiException;
use App\Filament\Resources\GameSystemRequestResource;
use App\Models\GameSystem;
use App\Services\BggClient;
use App\Services\BggSyncService;
use App\Services\BggXmlParser;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class EditGameSystemRequest extends EditRecord
{
    protected static string $resource = GameSystemRequestResource::class;

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('searchBgg')
                ->label('Search BGG')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
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
                        ->default(fn () => $this->record?->name),
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
                        ->hidden(fn () => empty($this->bggSearchResults) || $this->selectedBggId !== null)
                        ->content(new HtmlString('<p class="text-gray-500">No results yet. Enter a query and click Search.</p>')),
                ])
                ->action(function (array $data) {
                    $query = $data['bgg_search_query'] ?? '';

                    if (empty(trim($query))) {
                        return;
                    }

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
                    } catch (BggApiException $e) {
                        $this->bggSearchResults = [];

                        Notification::make()
                            ->danger()
                            ->title('BGG Search Failed')
                            ->body($e->getMessage())
                            ->send();
                    }
                })
                ->modalFooterActions(fn (Action $action) => [
                    $action->getModalSubmitAction(),
                    Action::make('clearBggSelection')
                        ->label('Clear Selection')
                        ->color('gray')
                        ->visible(fn () => $this->selectedBggId !== null)
                        ->action(function () {
                            $this->selectedBggId = null;
                            $this->selectedBggName = null;
                        }),
                    $action->getModalCancelAction(),
                ]),

            // ── Approve Action ─────────────────────────────

            Action::make('approve')
                ->label('Approve')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve Game System Request')
                ->modalDescription(fn () => $this->selectedBggId
                    ? "This will sync BGG ID {$this->selectedBggId} (\"{$this->selectedBggName}\") and create a GameSystem in the catalog."
                    : 'This will create a GameSystem from the request data (manual entry).')
                ->schema(fn () => $this->getApproveSchema())
                ->modalSubmitActionLabel('Approve')
                ->action(function (array $data) {
                    $this->performApproval($data);
                })
                ->visible(fn () => $this->record?->status !== 'approved'),

            // ── Sync Base Game Action (for expansions missing base) ──

            Action::make('syncBaseGame')
                ->label('Sync Base Game')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->visible(fn () => $this->shouldShowSyncBaseGame())
                ->requiresConfirmation()
                ->modalHeading('Sync Missing Base Game')
                ->modalDescription(fn () => $this->record?->gameSystem
                    ? "This expansion's base game (BGG ID: {$this->record->gameSystem->bgg_id}) is not in the catalog. Sync it now?"
                    : 'Sync the base game for this expansion.')
                ->modalSubmitActionLabel('Sync Base Game')
                ->action(function () {
                    $this->performBaseGameSync();
                }),

            // ── Reject Action ─────────────────────────────

            Action::make('reject')
                ->label('Reject')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Reject Game System Request')
                ->modalDescription('Provide a reason for rejecting this request. The requester will see this reason.')
                ->modalSubmitActionLabel('Reject Request')
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Rejection Reason')
                        ->placeholder('e.g. Already exists in the catalog, insufficient information, not a game system…')
                        ->required()
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    $this->performRejection($data);
                })
                ->visible(fn () => in_array($this->record?->status, ['pending'])),

            // ── Mark Duplicate Action ─────────────────────

            Action::make('markDuplicate')
                ->label('Mark Duplicate')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Mark as Duplicate')
                ->modalDescription('Link this request to an existing game system in the catalog.')
                ->modalSubmitActionLabel('Mark Duplicate')
                ->schema([
                    Select::make('duplicate_game_system_id')
                        ->label('Existing Game System')
                        ->placeholder('Search for an existing game system…')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => GameSystem::where('name', 'ilike', "%{$search}%")
                            ->limit(20)
                            ->pluck('name', 'id')
                            ->toArray())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => GameSystem::find($value)?->name),
                ])
                ->action(function (array $data) {
                    $this->performMarkDuplicate($data);
                })
                ->visible(fn () => in_array($this->record?->status, ['pending'])),
        ];
    }

    /**
     * Select a BGG search result by index.
     * Called from the rendered results table via wire:click.
     */
    public function selectBggResult(int $index): void
    {
        if (! isset($this->bggSearchResults[$index])) {
            return;
        }

        $result = $this->bggSearchResults[$index];
        $this->selectedBggId = $result['bgg_id'];
        $this->selectedBggName = $result['name'];

        Notification::make()
            ->success()
            ->title('BGG game selected')
            ->body("Selected: {$this->selectedBggName} (ID: {$this->selectedBggId})")
            ->send();
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

    // ── Approve Helpers ─────────────────────────────────

    /**
     * Build the form schema for the approve modal.
     * For BGG-synced approvals, shows the selected game info and an optional
     * "sync base game too" checkbox for expansions missing their base game.
     */
    protected function getApproveSchema(): array
    {
        $schema = [];

        if ($this->selectedBggId) {
            $schema[] = Placeholder::make('approve_bgg_summary')
                ->label('BGG Sync Target')
                ->content(new HtmlString(
                    '<div class="text-sm">'
                    . '<strong>' . e($this->selectedBggName) . '</strong>'
                    . ' <span class="text-gray-500">(BGG ID: ' . $this->selectedBggId . ')</span>'
                    . '</div>'
                ));
        } else {
            $schema[] = Placeholder::make('approve_manual_summary')
                ->label('Manual Entry')
                ->content(new HtmlString(
                    '<div class="text-sm text-gray-600 dark:text-gray-400">'
                    . 'No BGG game selected. A GameSystem will be created from the request data: '
                    . '<strong>' . e($this->record?->name) . '</strong>'
                    . '</div>'
                ));
        }

        return $schema;
    }

    /**
     * Execute the approval: sync from BGG or create manually, then update request.
     */
    protected function performApproval(array $data): void
    {
        $request = $this->record;
        $gameSystem = null;

        try {
            if ($this->selectedBggId) {
                // BGG-synced approval
                $gameSystem = $this->syncFromBgg($this->selectedBggId);
            } else {
                // Manual approval — create GameSystem from request data
                $gameSystem = $this->createManualGameSystem($request);
            }

            // Update the request
            $request->update([
                'status' => 'approved',
                'game_system_id' => $gameSystem->id,
                'reviewed_by' => auth()->id(),
            ]);

            Log::info('GameSystemRequest approved', [
                'request_id' => $request->id,
                'request_name' => $request->name,
                'game_system_id' => $gameSystem->id,
                'bgg_id' => $this->selectedBggId,
                'reviewed_by' => auth()->id(),
            ]);

            Notification::make()
                ->success()
                ->title('Request approved')
                ->body("GameSystem \"{$gameSystem->name}\" has been created in the catalog.")
                ->send();

            $this->refreshForm();

        } catch (\Throwable $e) {
            Log::error('GameSystemRequest approval failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Approval failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Sync a GameSystem from BGG using BggSyncService.
     */
    protected function syncFromBgg(int $bggId): GameSystem
    {
        $result = app(BggSyncService::class)->syncGameSystems([$bggId]);

        if ($result['failed'] > 0 && $result['synced'] === 0) {
            throw new \RuntimeException(
                'BGG sync failed: ' . implode('; ', $result['errors'])
            );
        }

        $gameSystem = GameSystem::where('bgg_id', $bggId)->first();

        if (! $gameSystem) {
            throw new \RuntimeException("BGG sync completed but GameSystem not found for bgg_id={$bggId}.");
        }

        return $gameSystem;
    }

    /**
     * Create a GameSystem manually from the request data (non-BGG).
     */
    protected function createManualGameSystem(GameSystemRequest $request): GameSystem
    {
        return GameSystem::create([
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'description' => $request->notes ?? '',
            'type' => $request->type ?? 'boardgame',
            'year_released' => null,
            'source' => 'manual',
        ]);
    }

    /**
     * Whether to show the "Sync Base Game" action.
     * Shown when the request is approved, has a linked GameSystem that is
     * an expansion (bgg_type=boardgameexpansion) with no base_game_id.
     */
    protected function shouldShowSyncBaseGame(): bool
    {
        $gs = $this->record?->gameSystem;

        if (! $gs) {
            return false;
        }

        return $gs->bgg_type === 'boardgameexpansion'
            && $gs->base_game_id === null;
    }

    /**
     * Sync the base game for an expansion whose base_game_id is null.
     */
    protected function performBaseGameSync(): void
    {
        $gameSystem = $this->record?->gameSystem;

        if (! $gameSystem || ! $gameSystem->bgg_id) {
            Notification::make()
                ->danger()
                ->title('Cannot sync base game')
                ->body('No BGG ID found for the linked game system.')
                ->send();

            return;
        }

        // Re-sync the expansion — BggSyncService will auto-fetch the base game
        try {
            $result = app(BggSyncService::class)->syncGameSystems([$gameSystem->bgg_id]);

            // Refresh the model to pick up the base_game_id
            $gameSystem->refresh();

            if ($gameSystem->base_game_id) {
                $baseGame = $gameSystem->baseGame;
                Log::info('Base game synced for expansion', [
                    'expansion_id' => $gameSystem->id,
                    'expansion_name' => $gameSystem->name,
                    'base_game_id' => $baseGame->id,
                    'base_game_name' => $baseGame->name,
                ]);

                Notification::make()
                    ->success()
                    ->title('Base game synced')
                    ->body("Base game \"{$baseGame->name}\" has been added to the catalog.")
                    ->send();

                $this->refreshForm();
            } else {
                Notification::make()
                    ->warning()
                    ->title('Base game not found')
                    ->body('The expansion was re-synced but the base game could not be resolved automatically.')
                    ->send();
            }
        } catch (\Throwable $e) {
            Log::error('Base game sync failed', [
                'expansion_bgg_id' => $gameSystem->bgg_id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Base game sync failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Execute the rejection: update status, set reason and reviewer.
     */
    protected function performRejection(array $data): void
    {
        $request = $this->record;

        try {
            $request->update([
                'status' => 'rejected',
                'reviewed_by' => auth()->id(),
                'rejection_reason' => $data['rejection_reason'],
            ]);

            Log::info('GameSystemRequest rejected', [
                'request_id' => $request->id,
                'request_name' => $request->name,
                'reviewed_by' => auth()->id(),
                'rejection_reason' => $data['rejection_reason'],
            ]);

            Notification::make()
                ->success()
                ->title('Request rejected')
                ->body("Request for \"{$request->name}\" has been rejected.")
                ->send();

            $this->refreshForm();

        } catch (\Throwable $e) {
            Log::error('GameSystemRequest rejection failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Rejection failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Mark the request as a duplicate of an existing GameSystem.
     */
    protected function performMarkDuplicate(array $data): void
    {
        $request = $this->record;
        $existingSystem = GameSystem::find($data['duplicate_game_system_id']);

        if (! $existingSystem) {
            Notification::make()
                ->danger()
                ->title('Game system not found')
                ->body('The selected game system could not be found.')
                ->send();

            return;
        }

        try {
            $request->update([
                'status' => 'duplicate',
                'game_system_id' => $existingSystem->id,
                'reviewed_by' => auth()->id(),
            ]);

            Log::info('GameSystemRequest marked duplicate', [
                'request_id' => $request->id,
                'request_name' => $request->name,
                'game_system_id' => $existingSystem->id,
                'game_system_name' => $existingSystem->name,
                'reviewed_by' => auth()->id(),
            ]);

            Notification::make()
                ->success()
                ->title('Marked as duplicate')
                ->body("Request for \"{$request->name}\" linked to \"{$existingSystem->name}\".")
                ->send();

            $this->refreshForm();

        } catch (\Throwable $e) {
            Log::error('GameSystemRequest mark-duplicate failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('Mark duplicate failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Refresh the form to reflect updated record state after approval.
     */
    protected function refreshForm(): void
    {
        $this->fillForm();
    }
}
