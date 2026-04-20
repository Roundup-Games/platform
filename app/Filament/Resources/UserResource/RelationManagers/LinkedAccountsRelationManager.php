<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinkedAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'linkedAccounts';

    protected static ?string $title = 'Linked Accounts (OAuth)';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'google' => 'success',
                        'github' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('provider_user_id')
                    ->label('Provider User ID')
                    ->limit(30)
                    ->copyable()
                    ->tooltip(fn($record): string => $record->provider_user_id),
                TextColumn::make('created_at')
                    ->label('Linked At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
