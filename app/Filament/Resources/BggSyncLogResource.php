<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BggSyncLogResource\Pages;
use App\Models\BggSyncLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BggSyncLogResource extends Resource
{
    protected static ?string $model = BggSyncLog::class;

    protected static ?int $navigationSort = 3;

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    public static function getNavigationGroup(): string|BackedEnum|null
    {
        return 'BGG Data';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('bgg_ids_count')
                    ->label('BGG IDs')
                    ->state(fn ($record) => count($record->bgg_ids ?? [])),
                TextColumn::make('items_synced')
                    ->label('Synced')
                    ->sortable(),
                TextColumn::make('items_failed')
                    ->label('Failed')
                    ->sortable(),
                TextColumn::make('error_message')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn ($record) => $record->completed_at && $record->started_at
                        ? $record->started_at->diffInSeconds($record->completed_at).'s'
                        : '-'
                    )
                    ->toggleable(),
            ])
            ->defaultSort('started_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBggSyncLogs::route('/'),
        ];
    }
}
