<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\OAuthProvider;
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
                    ->color(function ($state): string {
                        // provider is enum-cast (OAuthProvider|null via
                        // OAuthProviderCast), but legacy rows (bgg, pre-enum)
                        // surface as raw strings. Normalise either form.
                        $enum = $state instanceof OAuthProvider
                            ? $state
                            : (is_string($state) ? OAuthProvider::tryFrom($state) : null);

                        return $enum?->filamentColor() ?? 'info';
                    })
                    ->formatStateUsing(function ($state): string {
                        $enum = $state instanceof OAuthProvider
                            ? $state
                            : (is_string($state) ? OAuthProvider::tryFrom($state) : null);

                        if ($enum !== null) {
                            return $enum->label();
                        }

                        return is_string($state) ? ucfirst($state) : '—';
                    })
                    ->sortable(),
                TextColumn::make('provider_user_id')
                    ->label('Provider User ID')
                    ->limit(30)
                    ->copyable()
                    ->tooltip(fn ($record): string => $record->provider_user_id),
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
