<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GameSystemMechanicResource\Pages;
use App\Models\GameSystemMechanic;
use BackedEnum;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GameSystemMechanicResource extends Resource
{
    protected static ?string $model = GameSystemMechanic::class;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): string | BackedEnum | null
    {
        return 'Game Systems';
    }

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return Heroicon::OutlinedCog6Tooth;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Mechanic Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (string $state, $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->alphaDash()
                                    ->unique(ignoreRecord: true),
                            ]),
                        RichEditor::make('description')
                            ->label('Description')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'bulletList',
                                'orderedList',
                                'link',
                            ])
                            ->helperText('Editorial description (200–400 words recommended). Supports basic formatting.'),
                    ]),

                Section::make('Cross-Links')
                    ->description('Link similar mechanics for discovery and recommendations.')
                    ->schema([
                        Select::make('similarMechanics')
                            ->label('Similar Mechanics')
                            ->relationship('similarMechanics', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->helperText('Mechanics that are related or similar to this one.'),
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
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')
                    ->limit(50)
                    ->html()
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('game_systems_count')
                    ->label('Systems')
                    ->counts('gameSystems')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->defaultSort('name', 'asc')
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGameSystemMechanics::route('/'),
            'create' => Pages\CreateGameSystemMechanic::route('/create'),
            'edit' => Pages\EditGameSystemMechanic::route('/{record}/edit'),
        ];
    }
}
