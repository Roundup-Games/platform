<?php

namespace App\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Paddle\Subscription;

class MembershipExporter extends Exporter
{
    protected static ?string $model = Subscription::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('Subscription ID'),
            ExportColumn::make('billable.name')
                ->label('User Name')
                ->enabledByDefault(true),
            ExportColumn::make('billable.email')
                ->label('User Email')
                ->enabledByDefault(true),
            ExportColumn::make('type')
                ->label('Membership Type')
                ->enabledByDefault(true),
            ExportColumn::make('status')
                ->label('Status')
                ->enabledByDefault(true)
                ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ExportColumn::make('trial_ends_at')
                ->label('Trial Ends')
                ->enabledByDefault(true),
            ExportColumn::make('ends_at')
                ->label('End Date')
                ->enabledByDefault(true),
            ExportColumn::make('paused_at')
                ->label('Paused At'),
            ExportColumn::make('created_at')
                ->label('Start Date')
                ->enabledByDefault(true),
            ExportColumn::make('items.price_id')
                ->label('Paddle Price ID')
                ->formatStateUsing(fn (array $state) => collect($state)->join(', ')),
            ExportColumn::make('items.product_id')
                ->label('Paddle Product ID')
                ->formatStateUsing(fn (array $state) => collect($state)->join(', ')),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['billable', 'items']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your membership report export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    public function getFileName(Export $export): string
    {
        $id = $export->getKey();
        $keyStr = to_string_id($id);

        return "membership-report-{$keyStr}.csv";
    }
}
