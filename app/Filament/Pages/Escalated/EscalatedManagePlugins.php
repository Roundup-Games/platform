<?php

namespace App\Filament\Pages\Escalated;

use Escalated\Filament\Pages\ManagePlugins;

/**
 * Manage Plugins page gated behind escalated-admin AND the plugin config flag.
 */
class EscalatedManagePlugins extends ManagePlugins
{
    public static function canAccess(): bool
    {
        return parent::canAccess() && (auth()->user()?->can('escalated-admin') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (auth()->user()?->can('escalated-admin') ?? false);
    }
}
