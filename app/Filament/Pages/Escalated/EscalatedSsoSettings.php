<?php

namespace App\Filament\Pages\Escalated;

use Escalated\Filament\Pages\SsoSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Schemas\Components\Utilities\Get;

/**
 * SSO Settings page gated behind escalated-admin (Platform Admin only).
 *
 * Overrides form() to fix Filament v4 type compatibility:
 * vendor uses Filament\Forms\Get but v4 passes Filament\Schemas\Components\Utilities\Get.
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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('escalated-filament::filament.pages.sso_settings.provider'))
                    ->schema([
                        Forms\Components\Select::make('sso_provider')
                            ->label(__('escalated-filament::filament.pages.sso_settings.provider_label'))
                            ->options([
                                'none' => __('escalated-filament::filament.pages.sso_settings.provider_none'),
                                'saml' => __('escalated-filament::filament.pages.sso_settings.provider_saml'),
                                'jwt' => __('escalated-filament::filament.pages.sso_settings.provider_jwt'),
                            ])
                            ->default('none')
                            ->live()
                            ->required(),
                    ]),

                Forms\Components\Section::make(__('escalated-filament::filament.pages.sso_settings.saml_configuration'))
                    ->visible(fn (Get $get): bool => $get('sso_provider') === 'saml')
                    ->schema([
                        Forms\Components\TextInput::make('sso_entity_id')
                            ->label(__('escalated-filament::filament.pages.sso_settings.entity_id'))
                            ->required(),

                        Forms\Components\TextInput::make('sso_url')
                            ->label(__('escalated-filament::filament.pages.sso_settings.sso_url'))
                            ->url()
                            ->required(),

                        Forms\Components\Textarea::make('sso_certificate')
                            ->label(__('escalated-filament::filament.pages.sso_settings.certificate'))
                            ->rows(6)
                            ->required(),
                    ]),

                Forms\Components\Section::make(__('escalated-filament::filament.pages.sso_settings.jwt_configuration'))
                    ->visible(fn (Get $get): bool => $get('sso_provider') === 'jwt')
                    ->schema([
                        Forms\Components\TextInput::make('sso_jwt_secret')
                            ->label(__('escalated-filament::filament.pages.sso_settings.jwt_secret'))
                            ->password()
                            ->required(),

                        Forms\Components\Select::make('sso_jwt_algorithm')
                            ->label(__('escalated-filament::filament.pages.sso_settings.jwt_algorithm'))
                            ->options([
                                'HS256' => 'HS256',
                                'HS384' => 'HS384',
                                'HS512' => 'HS512',
                                'RS256' => 'RS256',
                                'RS384' => 'RS384',
                                'RS512' => 'RS512',
                            ])
                            ->default('HS256')
                            ->required(),
                    ]),

                Forms\Components\Section::make(__('escalated-filament::filament.pages.sso_settings.attribute_mapping'))
                    ->visible(fn (Get $get): bool => $get('sso_provider') !== 'none')
                    ->schema([
                        Forms\Components\TextInput::make('sso_attr_email')
                            ->label(__('escalated-filament::filament.pages.sso_settings.attr_email'))
                            ->default('email'),

                        Forms\Components\TextInput::make('sso_attr_name')
                            ->label(__('escalated-filament::filament.pages.sso_settings.attr_name'))
                            ->default('name'),

                        Forms\Components\TextInput::make('sso_attr_role')
                            ->label(__('escalated-filament::filament.pages.sso_settings.attr_role'))
                            ->default('role'),
                    ])
                    ->columns(3),
            ])
            ->statePath('data');
    }
}
