<?php

namespace App\Filament\Resources\BadgeDefinitionResource\Pages;

use App\Filament\Resources\BadgeDefinitionResource;
use App\Services\Badges\BadgesSweep;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBadgeDefinitions extends ListRecords
{
    protected static string $resource = BadgeDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The BadgesSweep docblock explicitly anticipates admin
            // surfaces calling sweep(): same orchestrator as the nightly
            // 06:30 run (registry sync + every evaluator), idempotent
            // end-to-end because every write flows through the audited
            // BadgeService pipeline.
            Action::make('runSweepNow')
                ->label('Run sweep now')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Run the badge sweep now?')
                ->modalDescription('Runs the same orchestrator as the nightly badges:sweep: registry sync, then every evaluator. Safe to re-run — awards are idempotent and failure-isolated.')
                ->action(function (): void {
                    $result = app(BadgesSweep::class)->sweep();

                    $notification = Notification::make()
                        ->title(sprintf(
                            'Badge sweep finished: %d evaluators run, %d failed',
                            $result['evaluators'],
                            $result['failed'],
                        ));

                    if ($result['failed'] > 0) {
                        $notification
                            ->danger()
                            ->body(collect($result['failures'])
                                ->map(fn (array $failure): string => sprintf('%s: %s — %s', $failure['badge'], $failure['exception'], $failure['message']))
                                ->implode("\n"));
                    } else {
                        $notification
                            ->success()
                            ->body('Registry synced and every evaluator completed.');
                    }

                    $notification->send();
                }),
        ];
    }
}
