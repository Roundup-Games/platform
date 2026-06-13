<?php

namespace App\Filament\Resources\EventResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AnnouncementsRelationManager extends RelationManager
{
    protected static string $relationship = 'announcements';

    protected static ?string $title = 'Announcements';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(1)
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255),
                        RichEditor::make('content')->required()->columnSpanFull(),
                        Grid::make(3)
                            ->schema([
                                Select::make('author_id')
                                    ->label('Author')
                                    ->relationship('author', 'name')
                                    ->searchable(),
                                Toggle::make('is_published')
                                    ->label('Published')
                                    ->default(false),
                                Toggle::make('is_pinned')
                                    ->label('Pinned')
                                    ->default(false),
                            ]),
                        Select::make('visibility')
                            ->options([
                                'all' => 'All',
                                'registered' => 'Registered Only',
                                'private' => 'Private (Admins)',
                            ])
                            ->default('all')
                            ->required(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40)
                    ->sortable(),
                TextColumn::make('author.name')
                    ->label('Author')
                    ->searchable(),
                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean(),
                IconColumn::make('is_pinned')
                    ->label('Pinned')
                    ->boolean(),
                TextColumn::make('visibility')
                    ->badge(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
