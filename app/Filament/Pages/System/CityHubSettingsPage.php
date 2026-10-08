<?php

namespace App\Filament\Pages\System;

use App\Services\CityDirectoryService;
use App\Services\CityHubSettings;
use App\Services\SeoCacheService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * The admin surface for the DB-backed city hub qualification thresholds
 * (62-04-T05) — the "adjust activity threshold without a deploy" knob.
 *
 * The storage layer is CityHubSettings (city_hub_settings rows read
 * through the 'city-hubs:settings' cache entry, DB-over-config
 * precedence); this page is its Filament form. mount() fills from the
 * cached accessors, so the form shows exactly what the guard enforces
 * today: the config/cityhubs.php defaults until rows exist, then the
 * stored values.
 *
 * Saving re-rates EVERY city, not just borderline clusters: a threshold
 * change moves the whole qualifying set, so save() drops every cached
 * resolution via CityDirectoryService::forgetAll() (positive AND
 * negative entries alike), then folds the cities sub-sitemap and the
 * sitemap index to the new set. The next hub request or sitemap crawl
 * recomputes against the new thresholds.
 *
 * @property-read Schema $form
 */
class CityHubSettingsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'City Hub Settings';

    protected static ?string $navigationLabel = 'City Hub Settings';

    protected static ?string $slug = 'city-hub-settings';

    protected string $view = 'filament.pages.system.city-hub-settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $settings = app(CityHubSettings::class);

        $this->form->fill([
            'min_upcoming_sessions' => $settings->minUpcomingSessions(),
            'min_verified_venues' => $settings->minVerifiedVenues(),
        ]);
    }

    /**
     * The page body: the thresholds form with its submit footer. The v5
     * shape Filament's own auth pages use — a Form schema component
     * wrapping an EmbeddedSchema, with the livewire submit handler bound
     * to save().
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->key('form-actions'),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('min_upcoming_sessions')
                    ->label('Min upcoming sessions')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText('A city hub renders when its cluster has at least this many upcoming public sessions (games, campaign sessions, or events) within the activity window — or the venue threshold below. Values saved here override the config/cityhubs.php defaults, which remain the fallback until a value is stored.'),
                TextInput::make('min_verified_venues')
                    ->label('Min verified venues')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText('The alternative qualification path: at least this many verified venues in the cluster. Saved values override the config/cityhubs.php defaults, which remain the fallback until a value is stored.'),
            ])
            ->columns(2)
            ->statePath('data');
    }

    /**
     * @return array<Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save thresholds')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $minUpcomingSessions = $data['min_upcoming_sessions'] ?? null;
        $minVerifiedVenues = $data['min_verified_venues'] ?? null;

        // Validation (numeric + required) guarantees numeric state; the
        // fallback keeps the service's resolved config defaults rather
        // than silently zeroing a threshold if that ever changes.
        $settings = app(CityHubSettings::class);
        $settings->set(
            is_numeric($minUpcomingSessions) ? (int) $minUpcomingSessions : $settings->minUpcomingSessions(),
            is_numeric($minVerifiedVenues) ? (int) $minVerifiedVenues : $settings->minVerifiedVenues(),
        );

        // A threshold change re-rates every city: drop all cached
        // resolutions (positive and negative) so hubs and the sitemap
        // fold to the new qualifying set on the next request.
        app(CityDirectoryService::class)->forgetAll();
        app(SeoCacheService::class)->forgetSitemap('cities');
        app(SeoCacheService::class)->forgetIndex();

        Notification::make()
            ->title('City hub thresholds saved')
            ->success()
            ->send();
    }
}
