<?php

namespace App\Filament\Components;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;

/**
 * Reusable Filament form section for editing SEO overrides on any model
 * that uses the HasSEO trait (RalphJSmit\Laravel\SEO).
 *
 * Usage in a Resource form():
 *   SeoFields::make(),
 *
 * The section uses ->relationship('seo') to bind to the MorphOne seo
 * relationship. Fields left empty fall through to getDynamicSEOData().
 */
class SeoFields
{
    /**
     * Create the SEO editing section.
     *
     * The section is collapsible and collapsed by default, since most
     * models derive SEO from their content automatically.
     */
    public static function make(): Section
    {
        return Section::make('SEO Overrides')
            ->description('Override the dynamically generated SEO metadata. Leave fields empty to use defaults.')
            ->relationship('seo')
            ->schema([
                Grid::make(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('SEO Title')
                            ->nullable()
                            ->maxLength(255)
                            ->helperText('Override the page title. Leave empty for default.')
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label('SEO Description')
                            ->nullable()
                            ->maxLength(160)
                            ->rows(2)
                            ->helperText('Recommended: 120–160 characters. Leave empty for default.')
                            ->columnSpanFull(),

                        TextInput::make('image')
                            ->label('SEO Image URL')
                            ->nullable()
                            ->url()
                            ->maxLength(500)
                            ->helperText('Override OG/Twitter image. Leave empty for default.'),

                        TextInput::make('canonical_url')
                            ->label('Canonical URL')
                            ->nullable()
                            ->url()
                            ->maxLength(500)
                            ->helperText('Explicit canonical URL if needed.'),

                        Select::make('robots')
                            ->label('Robots Directive')
                            ->nullable()
                            ->options([
                                'index, follow' => 'Index, Follow (default)',
                                'noindex, follow' => 'Noindex, Follow',
                                'index, nofollow' => 'Index, Nofollow',
                                'noindex, nofollow' => 'Noindex, Nofollow',
                            ])
                            ->helperText('Controls search engine indexing behavior.'),
                    ]),
            ])
            ->collapsible()
            ->collapsed();
    }
}
