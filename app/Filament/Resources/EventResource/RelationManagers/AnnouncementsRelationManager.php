<?php

namespace App\Filament\Resources\EventResource\RelationManagers;

use App\Filament\Resources\EventResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                        static::translatableField('title', 'Title', fn () => TextInput::make('title')->required()->maxLength(255)),
                        static::translatableField('content', 'Content', fn () => RichEditor::make('content')->required()->columnSpanFull()),
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
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, $record): array {
                        foreach (static::getTranslationLocales() as $locale) {
                            foreach (['title', 'content'] as $field) {
                                $data["{$field}_{$locale}"] = $record->getTranslation($locale, $field);
                            }
                        }

                        return $data;
                    })
                    ->action(function (array $data, $record): void {
                        // Extract and remove translation fields before updating the model
                        $translations = [];
                        foreach (static::getTranslationLocales() as $locale) {
                            foreach (['title', 'content'] as $field) {
                                $key = "{$field}_{$locale}";
                                if (isset($data[$key])) {
                                    $translations[$locale][$field] = $data[$key];
                                    unset($data[$key]);
                                }
                            }
                        }

                        $record->update($data);

                        // Persist translations
                        foreach ($translations as $locale => $fields) {
                            foreach ($fields as $field => $value) {
                                if ($value !== '' && $value !== null) {
                                    $record->setTranslation($locale, $field, $value);
                                }
                            }
                        }
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return string[]
     */
    public static function getTranslationLocales(): array
    {
        return array_values(array_filter(
            config('app.available_locales', ['en']),
            fn (string $locale) => $locale !== 'en',
        ));
    }

    /**
     * Build a Tabs component for a translatable field.
     *
     * @param  callable(): \Filament\Forms\Components\Field  $enFieldBuilder
     */
    public static function translatableField(string $field, string $label, callable $enFieldBuilder): Tabs
    {
        $tabs = [
            Tab::make('English')
                ->schema([$enFieldBuilder()]),
        ];

        foreach (static::getTranslationLocales() as $locale) {
            $localeLabel = $locale === 'de' ? 'German' : ucfirst($locale);
            $tabs[] = Tab::make($localeLabel)
                ->schema([
                    Textarea::make("{$field}_{$locale}")
                        ->label("{$label} ({$localeLabel})")
                        ->maxLength(65535),
                ]);
        }

        return Tabs::make("{$field}_translations")
            ->tabs($tabs);
    }
}
