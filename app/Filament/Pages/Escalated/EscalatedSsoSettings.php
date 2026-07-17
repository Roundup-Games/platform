<?php

namespace App\Filament\Pages\Escalated;

use Escalated\Filament\Pages\SsoSettings;

/**
 * SSO Settings page gated behind escalated-admin (Platform Admin only).
 *
 * The form() definition is inherited from the vendor SsoSettings page. As of
 * escalated-filament v1.3.0 the vendor ships the correct Filament v5 signature
 * (form(Schema $form): Schema with Filament\Schemas\Components\Utilities\Get), so
 * no local override is needed — this class only adds access gating.
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
