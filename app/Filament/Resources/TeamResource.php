<?php

namespace App\Filament\Resources;

use App\Filament\Components\SeoFields;
use App\Filament\Resources\TeamResource\Pages;
use App\Filament\Resources\TeamResource\RelationManagers\MembersRelationManager;
use App\Models\Team;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

class TeamResource extends Resource
{
    use Translatable;

    protected static ?string $model = Team::class;

    protected static ?int $navigationSort = 2;

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedUserGroup;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Team Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('slug')
                                    ->maxLength(255)
                                    ->helperText('Auto-generated from name if left empty'),
                                TextInput::make('city')
                                    ->maxLength(255),
                                TextInput::make('country')
                                    ->maxLength(255),
                                TextInput::make('founded_year')
                                    ->numeric()
                                    ->minValue(1800)
                                    ->maxValue(date('Y')),
                                TextInput::make('website')
                                    ->url()
                                    ->maxLength(255),
                            ]),
                    ]),

                Section::make('Branding')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                ColorPicker::make('primary_color'),
                                ColorPicker::make('secondary_color'),
                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true),
                            ]),
                    ]),

                Section::make('Description')
                    ->schema([
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000),
                    ]),

                // ── SEO Overrides ─────────────────────────────
                SeoFields::make(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('country')
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('activeMembers_count')
                    ->label('Members')
                    ->counts('activeMembers')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
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
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeams::route('/'),
            'create' => Pages\CreateTeam::route('/create'),
            'edit' => Pages\EditTeam::route('/{record}/edit'),
        ];
    }
}
