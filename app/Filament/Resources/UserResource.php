<?php

namespace App\Filament\Resources;

use App\Enums\ContentLanguage;
use App\Enums\LocalSubscriptionSource;
use App\Filament\Components\SeoFields;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\BadgeEventsRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\BadgesRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\LinkedAccountsRelationManager;
use App\Models\User;
use App\Rules\ValidUserName;
use App\Services\CompMembershipService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?int $navigationSort = 1;

    /**
     * Override route binding to resolve by UUID 'id' column.
     *
     * The User model uses 'slug' as its route key for public URLs (/u/{slug}),
     * but Filament URLs are built using getRouteKey() which returns the slug.
     * We resolve by UUID 'id' — URLs will contain slugs but we look up by id.
     * This requires overriding resolveRecordRouteBinding to handle slug-to-id
     * resolution, since we can't change the model's getRouteKey() globally.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        $query = static::getRecordRouteBindingEloquentQuery();

        if ($modifyQuery) {
            $query = $modifyQuery($query) ?? $query;
        }

        // If the key looks like a UUID, resolve by id directly
        if (Str::isUuid($key)) {
            return $query->where('id', $key)->first();
        }

        // Otherwise it's a slug — resolve by slug then return the model
        return $query->where('slug', $key)->first();
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedUsers;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules([new ValidUserName])
                                    ->dehydrateStateUsing(fn (string $state): string => ValidUserName::sanitize($state)),
                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->minLength(3)
                                    ->rules(['regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'])
                                    ->unique(ignoreRecord: true)
                                    ->suffixAction(
                                        Action::make('view_profile')
                                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                                            ->url(fn (?User $record): ?string => $record ? route('profile.public', $record) : null)
                                            ->openUrlInNewTab()
                                    )
                                    ->helperText('Used in profile URL /u/{slug}. Letters, numbers, dots, hyphens, underscores.'),
                                TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),
                                TextInput::make('phone')
                                    ->tel()
                                    ->maxLength(255),
                                Select::make('gender')
                                    ->options([
                                        'male' => 'Male',
                                        'female' => 'Female',
                                        'non-binary' => 'Non-binary',
                                        'prefer_not_to_say' => 'Prefer not to say',
                                        'other' => 'Other',
                                    ]),
                                TextInput::make('pronouns')
                                    ->maxLength(50),
                                Textarea::make('bio')
                                    ->maxLength(500)
                                    ->rows(3)
                                    ->columnSpanFull(),
                                Select::make('preferred_language')
                                    ->label('Preferred Language')
                                    ->options(
                                        collect(ContentLanguage::cases())->mapWithKeys(
                                            fn (ContentLanguage $lang) => [$lang->value => $lang->label()]
                                        )
                                    ),
                                TextInput::make('avatar_url')
                                    ->label('Avatar URL')
                                    ->url()
                                    ->maxLength(500)
                                    ->disabled()
                                    ->helperText('Managed via media library on the profile page. Shows uploaded avatar or OAuth provider image.'),
                            ]),
                    ]),

                Section::make('Account Status')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                DateTimePicker::make('email_verified_at')
                                    ->label('Email Verified At'),
                                Toggle::make('profile_complete')
                                    ->label('Profile Complete'),
                                Placeholder::make('password_status')
                                    ->label('Password Set')
                                    ->content(fn (?User $record): string => $record?->hasPasswordSet() ? 'Yes' : 'No (OAuth only)'),
                                Placeholder::make('password_set_at')
                                    ->label('Password Set At')
                                    ->content(fn (?User $record): string => $record?->password_set_at?->format('M j, Y H:i') ?? '—'),
                            ]),
                        Grid::make(2)
                            ->schema([
                                Toggle::make('can_create_public_entries')
                                    ->label('Can create public entries')
                                    ->helperText('Allow this user to create public game sessions and campaigns visible to everyone. Without this, entries default to private.'),
                                TextInput::make('max_links_per_entity')
                                    ->label('Max short links per entity')
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(50)
                                    ->nullable()
                                    ->helperText('Maximum short links per entity for this GM. Leave empty for default (10).'),
                                Select::make('location_id')
                                    ->label('Location')
                                    ->relationship('linkedLocation', 'address')
                                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->address ?? $record->city ?? ($record->latitude.', '.$record->longitude))
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Geocoded location from profile or onboarding.'),
                            ]),
                    ]),

                Section::make('Access Control')
                    ->schema([
                        Toggle::make('is_disabled')
                            ->label('Disable Account')
                            ->reactive()
                            ->helperText('Disabled users are immediately logged out and cannot log back in. Their content remains visible but they lose all access.'),
                        DateTimePicker::make('disabled_at')
                            ->label('Disabled At')
                            ->visible(fn (callable $get): bool => (bool) $get('is_disabled'))
                            ->helperText('Automatically set when account is disabled.'),
                    ]),

                Section::make('Audit Metadata')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Placeholder::make('created_at')
                                    ->label('Created At')
                                    ->content(fn (?User $record): string => $record?->created_at?->format('M j, Y H:i') ?? '—'),
                                Placeholder::make('updated_at')
                                    ->label('Updated At')
                                    ->content(fn (?User $record): string => $record?->updated_at?->format('M j, Y H:i') ?? '—'),
                                Placeholder::make('profile_updated_at')
                                    ->label('Profile Updated At')
                                    ->content(function (?User $record): string {
                                        if ($record === null) {
                                            return '—';
                                        }

                                        /** @var Carbon|null $date */
                                        $date = $record->profile_updated_at;

                                        return $date?->format('M j, Y H:i') ?? '—';
                                    }),
                                Placeholder::make('profile_version')
                                    ->label('Profile Version')
                                    ->content(fn (?User $record): string => (string) ($record->profile_version ?? '—')),
                            ]),
                    ]),

                Section::make('Roles')
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ]),

                // ── SEO Overrides ─────────────────────────────
                SeoFields::make(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (User $record): ?string => null)
                    ->extraImgAttributes(['aria-hidden' => 'true']),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->copyMessage('Slug copied')
                    ->formatStateUsing(fn (string $state): string => "/u/{$state}"),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('profile_complete')
                    ->label('Profile')
                    ->boolean(),
                IconColumn::make('is_disabled')
                    ->label('Disabled')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)
                    ->falseIcon(Heroicon::OutlinedLockOpen)
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->sortable(),
                TextColumn::make('preferred_language')
                    ->label('Language')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('can_create_public_entries')
                    ->label('Public entries')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_disabled')
                    ->label('Disabled')
                    ->placeholder('All users')
                    ->trueLabel('Disabled only')
                    ->falseLabel('Active only'),
                TernaryFilter::make('email_verified_at')
                    ->label('Email Verified')
                    ->placeholder('All users')
                    ->trueLabel('Verified only')
                    ->falseLabel('Unverified only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query) => $query->whereNull('email_verified_at'),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('profile_complete')
                    ->label('Profile Complete'),
                SelectFilter::make('preferred_language')
                    ->label('Language')
                    ->options(
                        collect(ContentLanguage::cases())->mapWithKeys(
                            fn (ContentLanguage $lang) => [$lang->value => $lang->label()]
                        )
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('disable')
                    ->label('Disable')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Disable User Account')
                    ->modalDescription('This user will be immediately logged out and cannot log back in. Their data will be preserved.')
                    ->visible(fn (User $record): bool => ! $record->is_disabled)
                    ->action(function (User $record): bool {
                        $record->update([
                            'is_disabled' => true,
                            'disabled_at' => now(),
                        ]);
                        Log::warning('User account disabled via admin panel', [
                            'user_id' => $record->id,
                            'disabled_by' => auth()->id(),
                        ]);

                        return true;
                    }),
                Action::make('enable')
                    ->label('Re-enable')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Re-enable User Account')
                    ->modalDescription('This user will be able to log in again.')
                    ->visible(fn (User $record): bool => (bool) $record->is_disabled)
                    ->action(function (User $record): bool {
                        $record->update([
                            'is_disabled' => false,
                            'disabled_at' => null,
                        ]);
                        Log::info('User account re-enabled via admin panel', [
                            'user_id' => $record->id,
                            'enabled_by' => auth()->id(),
                        ]);

                        return true;
                    }),
                // ── Comp membership (R083, spec §2.5) ─────────────
                // Flagged local subscription on the GM plan carrying the
                // full GM benefit set and the Supporting Member badge —
                // the service owns the row, benefits arrive through the
                // S02 seam. All mutation stays in CompMembershipService.
                Action::make('grantCompMembership')
                    ->label('Grant comp')
                    // Comp granting is an admin-only capability, not an
                    // incidental of panel admission (the QueueStructure/
                    // reports idiom): any role that can reach the user
                    // table must not thereby gain comp-granting power.
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->icon(Heroicon::OutlinedGift)
                    ->color('success')
                    ->modalHeading('Grant comp membership')
                    ->modalDescription('Creates a flagged comp subscription on the GM plan with full GM benefits and the Supporting Member badge. Re-granting updates the existing comp row in place.')
                    ->form([
                        Select::make('period')
                            ->label('Period')
                            ->options([
                                'permanent' => 'Permanent',
                                '3' => '3 months',
                                '6' => '6 months',
                                '12' => '12 months',
                            ])
                            ->default('permanent')
                            ->required(),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000)
                            ->rows(3)
                            ->helperText('Why this user receives the comp — recorded on the subscription row.'),
                    ])
                    ->action(function (User $record, array $data): void {
                        $actor = auth()->user();

                        // Server-side twin of the visible() gate above —
                        // Livewire can invoke an action without re-running
                        // visibility, so the capability check is enforced
                        // here too (defense in depth).
                        if (! $actor instanceof User || ! $actor->isAdmin()) {
                            return;
                        }

                        $endsAt = $data['period'] === 'permanent'
                            ? null
                            : now()->addMonths((int) $data['period']);

                        $subscription = app(CompMembershipService::class)
                            ->grant($record, $endsAt, $actor, (string) $data['reason']);

                        Notification::make()
                            ->success()
                            ->title($endsAt === null
                                ? 'Comp membership granted — permanent'
                                : 'Comp membership granted — expires '.$endsAt->format('M j, Y'))
                            ->send();
                    }),
                Action::make('withdrawCompMembership')
                    ->label('Withdraw comp')
                    ->visible(fn (User $record): bool => (auth()->user()?->isAdmin() ?? false)
                        && $record->localSubscriptions()
                            ->where('source', LocalSubscriptionSource::Comp)
                            ->active()
                            ->exists())
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->modalHeading('Withdraw comp membership')
                    ->modalDescription('Cancels the comp subscription. GM benefits lapse only when no other active membership remains; the Supporting Member badge follows via the nightly sweep.')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000)
                            ->rows(3)
                            ->helperText('Why the comp is withdrawn — recorded in the structured log.'),
                    ])
                    ->action(function (User $record, array $data): void {
                        $actor = auth()->user();

                        // Server-side twin of the visible() gate above.
                        if (! $actor instanceof User || ! $actor->isAdmin()) {
                            return;
                        }

                        $withdrawn = app(CompMembershipService::class)
                            ->withdraw($record, $actor, (string) $data['reason']);

                        if (! $withdrawn) {
                            Notification::make()
                                ->info()
                                ->title('No active comp membership to withdraw')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title($record->hasActiveMembership()
                                ? 'Comp membership withdrawn — other membership benefits remain'
                                : 'Comp membership withdrawn — GM benefits downgraded')
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LinkedAccountsRelationManager::class,
            BadgesRelationManager::class,
            BadgeEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
