<?php

namespace App\Filament\Components;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * Reusable Filament form section for editing per-locale SEO overrides on
 * any model using the HasEntitySeo concern (D172).
 *
 * Usage in a Resource form():  SeoFields::make(),
 *
 * Writes the translatable seo_title/seo_description model columns —
 * per-locale editing follows the resource's Translatable concern (locale
 * switcher). Fields left empty (or whitespace-only) fall through to the
 * model's generated metadata (getDynamicSEOData()); one locale's curated
 * value never serves another locale.
 *
 * The retired seo-table row layer also offered image/canonical/robots
 * overrides — dropped deliberately: verified against the live deployment
 * that no row ever used them, and images, robots, and JSON-LD schema are
 * generator-owned by design (D172).
 */
class SeoFields
{
    public static function make(): Section
    {
        return Section::make('SEO Overrides')
            ->description('Per-locale overrides for the search-result title and snippet. Leave empty to use the generated metadata — switch locales to curate each language.')
            ->schema([
                TextInput::make('seo_title')
                    ->label('SEO title')
                    ->nullable()
                    ->maxLength(70)
                    ->helperText('Search-result headline (~50-60 chars visible). Empty = generated.'),
                Textarea::make('seo_description')
                    ->label('Meta description')
                    ->nullable()
                    ->maxLength(170)
                    ->rows(2)
                    ->helperText('Search-result snippet (~150-160 chars visible). Empty = generated.'),
            ])
            ->collapsible()
            ->collapsed();
    }
}
