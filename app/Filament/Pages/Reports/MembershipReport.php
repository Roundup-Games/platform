<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\MembershipExporter;
use Filament\Actions\ExportAction;
use Filament\Pages\Page;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Paddle\Subscription;

class MembershipReport extends Page implements HasTable
{
    use InteractsWithTable;

    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-chart-bar';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Reports';
    }

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.reports.membership-report';

    protected static ?string $title = 'Membership Report';

    protected static ?string $navigationLabel = 'Memberships';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Subscription::query()
                    ->with(['billable', 'items'])
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('billable.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                ImageColumn::make('billable.avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (): ?string => null)
                    ->extraImgAttributes(['aria-hidden' => 'true'])
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('billable.email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('type')
                    ->label('Membership Type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'trialing' => 'info',
                        'paused' => 'warning',
                        'past_due' => 'danger',
                        'canceled' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
                TextColumn::make('trial_ends_at')
                    ->label('Trial Ends')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Start Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('End Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('paused_at')
                    ->label('Paused At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'trialing' => 'Trialing',
                        'paused' => 'Paused',
                        'past_due' => 'Past Due',
                        'canceled' => 'Canceled',
                    ]),
                SelectFilter::make('type')
                    ->label('Membership Type')
                    ->options(
                        fn () => Subscription::query()
                            ->distinct()
                            ->pluck('type', 'type')
                            ->filter()
                            ->toArray()
                    )
                    ->searchable(),
                Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('Start Date From'),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('Start Date Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->toolbarActions([
                ExportAction::make()
                    ->exporter(MembershipExporter::class),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
