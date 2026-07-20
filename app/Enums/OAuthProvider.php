<?php

namespace App\Enums;

/**
 * OAuth login providers supported by roundup via Socialite.
 *
 * Single source of truth for the `linked_accounts.provider` column and the
 * OAuthController allowlist guard. To enable a new login provider: add a case
 * here, register the Socialite listener in AppServiceProvider::boot(), and add
 * the credentials block to config/services.php.
 *
 * Distinct from two related but wider concepts:
 *  - `config/platforms.php` — the registry of GM social-link platforms
 *    (Twitter, Instagram, …). Only Discord overlaps; the rest cannot be used
 *    to log in.
 *  - `users.signup_oauth_provider` — persisted at signup, carries the same
 *    values as this enum PLUS 'email' for native (non-OAuth) registrations,
 *    so it stays a plain string column.
 */
enum OAuthProvider: string
{
    case Google = 'google';
    case Discord = 'discord';

    /**
     * Socialite driver name and config/services.php key. Identical for both
     * current providers; the method exists so callers don't reach for ->value.
     */
    public function socialiteDriver(): string
    {
        return $this->value;
    }

    /**
     * Human-readable brand name for badges, filters, and report labels.
     * Brand names are not translated.
     */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Discord => 'Discord',
        };
    }

    /**
     * Filament badge color (Tailwind key) used by LinkedAccountsRelationManager
     * and SignupAttributionReport. Canonicalized across both surfaces.
     */
    public function filamentColor(): string
    {
        return match ($this) {
            self::Google => 'success',
            self::Discord => 'info',
        };
    }

    /**
     * All backed string values — for any caller that needs the full set as
     * plain strings (factories, seeders, query IN clauses).
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Coerce a route param, DB value, or fixture string into the enum, or
     * return null when the value isn't a supported provider. Prefer this over
     * tryFrom() at call sites that branch on "unsupported" rather than throw.
     */
    public static function fromRequest(string $provider): ?self
    {
        return self::tryFrom($provider);
    }
}
