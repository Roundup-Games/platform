<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepartmentResource\Pages;
use Escalated\Filament\Resources\DepartmentResource as BaseDepartmentResource;
use App\Models\User;
use Filament\Forms;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Override vendor DepartmentResource to fix PostgreSQL DISTINCT on JSON columns.
 *
 * The vendor form uses Select::make('agents')->relationship('agents', ...)
 * which generates SELECT DISTINCT users.* — PostgreSQL cannot compare JSON types
 * for equality, causing SQLSTATE[42883]. We replace the relationship select with
 * a manual options query that only selects id + name columns.
 */
class DepartmentResource extends BaseDepartmentResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),

                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('escalated-filament::filament.resources.department.field_active'))
                            ->default(true),

                        Forms\Components\Select::make('agents')
                            ->label(__('escalated-filament::filament.resources.department.field_agents'))
                            ->multiple()
                            ->preload()
                            ->searchable(['name', 'email'])
                            ->options(fn () => User::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->getSearchResultsUsing(fn (string $search) => User::query()
                                ->where('name', 'ilike', "%{$search}%")
                                ->orWhere('email', 'ilike', "%{$search}%")
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray()),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepartments::route('/'),
            'create' => Pages\CreateDepartment::route('/create'),
            'edit' => Pages\EditDepartment::route('/{record}/edit'),
        ];
    }
}
