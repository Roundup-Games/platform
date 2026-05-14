<?php

namespace App\Filament\Pages\Escalated;

use Escalated\Filament\Pages\Reports;

/**
 * Reports page gated behind escalated-agent (Platform Admin + Service Admin).
 * Agents can view ticket reports and metrics.
 */
class EscalatedReports extends Reports
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('escalated-agent') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
