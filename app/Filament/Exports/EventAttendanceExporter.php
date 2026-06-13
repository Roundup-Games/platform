<?php

namespace App\Filament\Exports;

use App\Models\EventRegistration;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class EventAttendanceExporter extends Exporter
{
    protected static ?string $model = EventRegistration::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('Registration ID'),
            ExportColumn::make('event.name')
                ->label('Event Name')
                ->enabledByDefault(true),
            ExportColumn::make('event.start_date')
                ->label('Event Start')
                ->enabledByDefault(true),
            ExportColumn::make('user.name')
                ->label('User Name')
                ->enabledByDefault(true),
            ExportColumn::make('user.email')
                ->label('User Email')
                ->enabledByDefault(true),
            ExportColumn::make('team.name')
                ->label('Team Name')
                ->enabledByDefault(true)
                ->formatStateUsing(fn ($state) => $state ?? '—'),
            ExportColumn::make('registration_type')
                ->label('Registration Type')
                ->enabledByDefault(true)
                ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ExportColumn::make('division')
                ->label('Division')
                ->enabledByDefault(true)
                ->formatStateUsing(fn ($state) => $state ?? '—'),
            ExportColumn::make('status')
                ->label('Status')
                ->enabledByDefault(true)
                ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ExportColumn::make('payment_status')
                ->label('Payment Status')
                ->enabledByDefault(true)
                ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ExportColumn::make('payment_id')
                ->label('Payment ID'),
            ExportColumn::make('confirmed_at')
                ->label('Confirmed At')
                ->enabledByDefault(true),
            ExportColumn::make('cancelled_at')
                ->label('Cancelled At'),
            ExportColumn::make('created_at')
                ->label('Registered At')
                ->enabledByDefault(true),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['event', 'user', 'team']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your event attendance report export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    public function getFileName(Export $export): string
    {
        $id = $export->getKey();
        $keyStr = to_string_id($id);

        return "event-attendance-report-{$keyStr}.csv";
    }
}
