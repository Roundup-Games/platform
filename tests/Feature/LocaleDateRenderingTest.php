<?php

use App\Models\Game;
use App\Models\ShortLink;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| Locale-aware date rendering (M066/S02/T06)
|--------------------------------------------------------------------------
|
| Localized views must render dates through the format_date() helper so
| German visitors get German month/weekday names and 24-hour times — never
| the English "M j, g:i A"-style PHP date formats that used to be hardcoded
| in the Blade templates. These tests pin the rendered-output contract for
| two representative surfaces (share-link manager, session card) and guard
| against reintroducing raw English formats in localized views.
|
| EN-only admin surfaces (resources/views/filament, resources/views/escalated)
| are intentionally excluded — they are not translated.
|
*/

function t06_renderShareLinksManager(ShortLink $link): string
{
    return view('livewire.partials._share-links-manager', [
        'links' => collect([$link]),
        'showActions' => false,
    ])->render();
}

function t06_renderSessionCard(Game $game): string
{
    return view('livewire.components.partials.session-card', [
        'entity' => $game,
        'gameSystems' => null,
        'distanceKm' => 0,
        'participantCount' => 0,
        'type' => 'session',
    ])->render();
}

beforeEach(function () {
    // Pin "now" so the session-card today-branch never triggers for the
    // fixed 2026-04-14 fixture date, keeping expectations deterministic.
    Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
    app()->setLocale('en');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renders share-link created dates in the active locale', function () {
    $link = ShortLink::factory()->make(['label' => 'Friends', 'linkable_id' => null]);
    $link->created_at = Carbon::parse('2026-04-14 14:30:00');

    $english = t06_renderShareLinksManager($link);
    expect($english)->toContain('Apr 14, 2026');

    app()->setLocale('de');
    $german = t06_renderShareLinksManager($link);

    expect($german)->toContain('14. April 2026')
        ->and($german)->not->toContain('Apr 14, 2026');
});

it('renders session-card weekday dates in the active locale', function () {
    $game = Game::factory()->create(['date_time' => Carbon::parse('2026-04-14 14:30:00')]);

    $english = t06_renderSessionCard($game);
    expect($english)->toContain('Tue, Apr 14, 14:30');

    app()->setLocale('de');
    $german = t06_renderSessionCard($game);

    expect($german)->toContain('Di., 14. Apr, 14:30')
        ->and($german)->not->toContain('Apr 14, 14:30');
});

it('keeps localized views free of hardcoded English date formats', function () {
    $englishFormats = ["->format('M j", "->format('D, M j", "->format('l, F j"];

    $hits = collect();
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, 'views/filament') || str_contains($path, 'views/escalated')) {
            continue;
        }
        $content = (string) file_get_contents($path);
        foreach ($englishFormats as $needle) {
            if (str_contains($content, $needle)) {
                $hits->push(str_replace(base_path().'/', '', $path).' → '.$needle);
            }
        }
    }

    expect($hits->values()->toArray())->toBeEmpty(
        "Localized views must use format_date() instead of English PHP date formats. Offenders:\n".$hits->implode("\n")
    );
});
