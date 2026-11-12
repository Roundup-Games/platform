<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// Cover rendition delivery (CWV 2026-11 roll-out beyond
// GameSystemDetail): game/campaign heroes serve the 'hero' webp
// conversion through resolveCoverUrl('hero') — keeping the
// hero/preload/og parity triple on one rung — and card/thumbnail
// <img> slots carry DPR-aware srcset built from local conversions.
// ═══════════════════════════════════════════════════════════

describe('game detail hero rendition', function () {
    it('serves hero img, preload, and og:image from the hero conversion', function () {
        Storage::fake('public');

        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'name' => ['en' => 'Hero Rendition Game'],
        ]);
        $game->addMedia(UploadedFile::fake()->image('cover.jpg', 1000, 1400))
            ->toMediaCollection('cover');
        $game = $game->fresh();

        $expectedPath = parse_url($game->resolveCoverUrl('hero'), PHP_URL_PATH);

        $html = get(route('games.detail', $game->id))->assertOk()->getContent();

        expect($html)
            ->toContain('src="'.$expectedPath.'"')
            ->toContain('href="'.$expectedPath.'"')
            // og:image in parity with the hero (single resolver call).
            ->toContain($expectedPath)
            // The untouched original must not be painted by the page.
            ->not->toContain('src="'.parse_url($game->resolveCoverUrl(), PHP_URL_PATH).'"');
    });
});

describe('game-systems grid srcset', function () {
    it('advertises thumb+card widths for media-backed systems', function () {
        Storage::fake('public');

        $system = GameSystem::factory()->create(['name' => ['en' => 'Srcset System']]);
        $system->addMedia(UploadedFile::fake()->image('cover.jpg', 900, 900))
            ->toMediaCollection('cover');
        $system = $system->fresh();

        $html = get(route('game-systems'))->assertOk()->getContent();

        $srcset = $system->coverImageSrcset();
        expect($srcset)->not->toBeNull()
            ->and($html)->toContain('srcset="'.$srcset.'"')
            ->toContain('sizes=')
            ->toContain($system->coverImageUrl('thumb'));
    });

    it('stays single-URL for external BGG fallbacks', function () {
        GameSystem::factory()->create([
            'name' => ['en' => 'External Fallback System'],
            'thumbnail_url' => 'https://example.com/bgg-only.jpg',
        ]);

        $html = get(route('game-systems'))->assertOk()->getContent();

        // External fallback: plain src, never a fabricated srcset.
        expect($html)
            ->toContain('src="https://example.com/bgg-only.jpg"')
            ->not->toContain('srcset="https://example.com/bgg-only.jpg');
    });
});
