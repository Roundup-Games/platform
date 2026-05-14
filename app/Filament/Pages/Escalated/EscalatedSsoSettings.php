<?php

namespace App\Filament\Pages\Escalated;

use Escalated\Filament\Pages\SsoSettings;

/**
 * SSO Settings page gated behind escalated-admin (Platform Admin only).
 */
class EscalatedSsoSettings extends SsoSettings
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('escalated-admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
