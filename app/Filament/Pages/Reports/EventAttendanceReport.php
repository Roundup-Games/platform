<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\EventAttendanceExporter;
use App\Models\EventRegistration;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventAttendanceReport extends Page implements HasTable
{
    use InteractsWithTable;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-calendar-days';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Reports';
    }

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.reports.event-attendance-report';

    protected static ?string $title = 'Event Attendance Report';

    protected static ?string $navigationLabel = 'Event Attendance';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                EventRegistration::query()
                    ->with(['event', 'user', 'team'])
            )
            ->columns([
                TextColumn::make('event.name')
                    ->label('Event')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('event.start_date')
                    ->label('Event Date')
                    ->date()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('user.name')
                    ->label('Participant')
                    ->searchable()
                    ->sortable(),
                ImageColumn::make('user.avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (): ?string => null)
                    ->extraImgAttributes(['aria-hidden' => 'true'])
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->searchable()
                    ->toggleable()
                    ->default('—'),
                TextColumn::make('registration_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('division')
                    ->label('Division')
                    ->searchable()
                    ->default('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'pending' => 'warning',
                        'waitlisted' => 'info',
                        'cancelled' => 'danger',
                        'refunded' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        'refunded' => 'info',
                        'waived' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
                TextColumn::make('confirmed_at')
                    ->label('Confirmed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('event_id')
                    ->label('Event')
                    ->relationship('event', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'waitlisted' => 'Waitlisted',
                        'cancelled' => 'Cancelled',
                        'refunded' => 'Refunded',
                    ]),
                SelectFilter::make('payment_status')
                    ->label('Payment Status')
                    ->options([
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'refunded' => 'Refunded',
                        'failed' => 'Failed',
                        'waived' => 'Waived',
                    ]),
                SelectFilter::make('registration_type')
                    ->label('Registration Type')
                    ->options([
                        'individual' => 'Individual',
                        'team' => 'Team',
                    ]),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('registered_from')
                            ->label('Registered From'),
                        DatePicker::make('registered_until')
                            ->label('Registered Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['registered_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['registered_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->toolbarActions([
                ExportAction::make()
                    ->exporter(EventAttendanceExporter::class),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
