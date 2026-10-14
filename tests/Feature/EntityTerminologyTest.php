<?php

use App\Models\ShortLink;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Entity terminology hygiene (M066/S02/T07)
|--------------------------------------------------------------------------
|
| Developer jargon must not reach users: no "Entity" column header, no
| "for this entity" error copy, and never a class_basename() fallback in
| the short-link table. Share links always belong to a game or a campaign
| (ManagesShortLinks is used by GameDetail/CampaignDetail only), so the
| UI names those. DE follows the glossary canon: Game = Runde,
| Campaign = Kampagne.
|
| K004: expectations are built through the translator (or assert
| banned-token negatives), never literal lang values — the wording stays
| free to change; only the jargon-free and localization invariants are
| pinned here.
*/

function t07_renderShareLinksManager(ShortLink $link): string
{
    return view('livewire.partials._share-links-manager', [
        'links' => collect([$link]),
        'showActions' => false,
    ])->render();
}

it('labels the short-link target column without entity jargon', function () {
    app()->setLocale('en');

    $link = ShortLink::factory()->make(['label' => null, 'linkable_id' => null]);
    $html = t07_renderShareLinksManager($link);

    // The rendered header must carry the localized column label, never the
    // developer word "Entity".
    expect($html)
        ->toContain(__('gws.link_col_entity', [], 'en'))
        ->not->toContain('>Entity<');

    app()->setLocale('de');

    expect(t07_renderShareLinksManager($link))
        ->toContain(__('gws.link_col_entity', [], 'de'))
        ->not->toContain('Entität');
});

it('falls back to the localized type label for orphaned links, never a class name', function (string $locale) {
    app()->setLocale($locale);

    // Orphaned: linkable_id points at no row, so $link->linkable is null and
    // the type-fallback branch renders (previously: class_basename('game')).
    $link = ShortLink::factory()->make([
        'label' => null,
        'linkable_type' => 'game',
        'linkable_id' => Str::uuid()->toString(),
    ]);

    $html = t07_renderShareLinksManager($link);

    // The fallback must be the localized entity type label (Game = Runde in
    // DE per the glossary canon), never the morph alias or class name.
    $label = __('games.content_game', [], $locale);

    expect($html)
        ->toContain('>'.$label.'</span>')
        ->not->toContain('>game<');
})->with(['en', 'de']);

it('words the short-link limit error in plain user-facing language', function () {
    // K004: pin the invariants — translated in both locales (never the raw
    // key, never an English fallback in DE) and free of developer jargon.
    // The concrete wording lives in lang/ and may change freely.
    $en = __('common.error_max_links_reached', [], 'en');
    $de = __('common.error_max_links_reached', [], 'de');

    expect($en)
        ->not->toBe('common.error_max_links_reached')
        ->not->toContain('entity');

    expect($de)
        ->not->toBe('common.error_max_links_reached')
        ->not->toBe($en)
        ->not->toContain('Entität');
});
