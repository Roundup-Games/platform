<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GameSystemRequestResource\Pages;
use App\Models\GameSystemRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GameSystemRequestResource extends Resource
{
    protected static ?string $model = GameSystemRequest::class;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string | BackedEnum | null
    {
        return 'Game Systems';
    }

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::pending()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): string | array | null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request Details')
                    ->schema([
                        Placeholder::make('name_display')
                            ->label('Name')
                            ->content(fn ($record) => $record->name),
                        Placeholder::make('type_display')
                            ->label('Type')
                            ->content(fn ($record) => match ($record->type) {
                                'boardgame' => 'Board Game',
                                'ttrpg' => 'TTRPG',
                                'other' => 'Other',
                                default => $record->type,
                            }),
                        Placeholder::make('status_display')
                            ->label('Status')
                            ->content(fn ($record) => ucfirst($record->status)),
                        Placeholder::make('requester_display')
                            ->label('Requested By')
                            ->content(fn ($record) => $record->requester?->name ?? '—'),
                        Placeholder::make('bgg_url_display')
                            ->label('BGG URL')
                            ->content(fn ($record) => $record->bgg_url
                                ? new HtmlString('<a href="' . e($record->bgg_url) . '" target="_blank" class="text-primary-600 underline">' . e($record->bgg_url) . '</a>')
                                : '—'),
                        Placeholder::make('publisher_display')
                            ->label('Publisher')
                            ->content(fn ($record) => $record->publisher ?? '—'),
                        Placeholder::make('designer_display')
                            ->label('Designer')
                            ->content(fn ($record) => $record->designer ?? '—'),
                        Placeholder::make('notes_display')
                            ->label('Notes')
                            ->content(fn ($record) => $record->notes ?? '—'),
                    ])
                    ->columns(2),

                Section::make('Review')
                    ->schema([
                        Placeholder::make('reviewer_display')
                            ->label('Reviewed By')
                            ->content(fn ($record) => $record->reviewer?->name ?? '—'),
                        Placeholder::make('game_system_display')
                            ->label('Linked Game System')
                            ->content(fn ($record) => $record->gameSystem
                                ? new HtmlString('<a href="' . route('filament.admin.resources.game-systems.edit', $record->gameSystem) . '" class="text-primary-600 underline">' . e($record->gameSystem->name) . '</a>')
                                : '—'),
                        Placeholder::make('rejection_reason_display')
                            ->label('Rejection Reason')
                            ->content(fn ($record) => $record->rejection_reason ?? '—')
                            ->visible(fn ($record) => $record->rejection_reason !== null),
                    ])
                    ->columns(2)
                    ->visible(fn ($record) => $record->status !== 'pending'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'boardgame' => 'success',
                        'ttrpg' => 'warning',
                        'other' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'boardgame' => 'Board Game',
                        'ttrpg' => 'TTRPG',
                        'other' => 'Other',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'in_review' => 'info',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'duplicate' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pending',
                        'in_review' => 'In Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'duplicate' => 'Duplicate',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'in_review' => 'In Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'duplicate' => 'Duplicate',
                    ]),
                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'boardgame' => 'Board Game',
                        'ttrpg' => 'TTRPG',
                        'other' => 'Other',
                    ]),
            ])
            ->defaultSort('created_at', 'asc')
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGameSystemRequests::route('/'),
            'edit' => Pages\EditGameSystemRequest::route('/{record}/edit'),
        ];
    }
}
