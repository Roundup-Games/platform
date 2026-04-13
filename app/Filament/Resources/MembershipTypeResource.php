<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MembershipTypeResource\Pages;
use App\Models\MembershipType;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembershipTypeResource extends Resource
{
    protected static ?string $model = MembershipType::class;

    protected static ?int $navigationSort = 6;

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return Heroicon::OutlinedCreditCard;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Membership Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('status')
                                    ->options([
                                        'active' => 'Active',
                                        'inactive' => 'Inactive',
                                        'archived' => 'Archived',
                                    ])
                                    ->default('active')
                                    ->required(),
                                TextInput::make('price_cents')
                                    ->label('Price (cents)')
                                    ->required()
                                    ->numeric()
                                    ->prefix('¢')
                                    ->minValue(0),
                                TextInput::make('duration_months')
                                    ->label('Duration (months)')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1),
                                TextInput::make('paddle_price_id')
                                    ->label('Paddle Price ID')
                                    ->maxLength(255)
                                    ->helperText('Automatically set by Paddle webhook'),
                            ]),
                    ]),

                Section::make('Description')
                    ->schema([
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('formattedPrice')
                    ->label('Price')
                    ->state(fn (MembershipType $record): string => $record->formattedPrice())
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('price_cents', $direction)),
                TextColumn::make('duration_months')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => $state === 1 ? '1 month' : "{$state} months")
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('paddle_price_id')
                    ->label('Paddle ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembershipTypes::route('/'),
            'create' => Pages\CreateMembershipType::route('/create'),
            'edit' => Pages\EditMembershipType::route('/{record}/edit'),
        ];
    }
}
