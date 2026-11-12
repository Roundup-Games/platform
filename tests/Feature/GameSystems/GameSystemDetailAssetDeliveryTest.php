<?php

use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// GameSystemDetail — asset delivery (CWV optimization, 2026-11).
//
// Pins the three delivery changes that took the page's LCP/FCP budget back:
//   1. Hero background + thumbnail serve right-sized 'hero'/'card' webp
//      conversions instead of the original upload.
//   2. Livewire's runtime is bundled through Vite (@livewireScriptConfig +
//      app.js import) — no synchronous script tag in <head>.
//   3. Anonymous HTML responses carry Link preload headers so Cloudflare can
//      replay them as 103 Early Hints; authenticated responses never do.
// ═══════════════════════════════════════════════════════════

function performanceTestSystemWithCover(): GameSystem
{
    Storage::fake('public');

    $system = GameSystem::factory()->create([
        'slug' => 'delivery-test-system',
        'name' => ['en' => 'Delivery Test System'],
    ]);

    $system->addMedia(UploadedFile::fake()->image('cover.jpg', 1000, 1400))
        ->toMediaCollection('cover');

    return $system->fresh();
}

describe('GameSystemDetail - hero image conversions', function () {
    it('serves the hero background and thumbnail from right-sized conversions', function () {
        $system = performanceTestSystemWithCover();

        expect($system->coverImageUrl('hero'))->toEndWith('-hero.webp')
            ->and($system->coverImageUrl('card'))->toEndWith('-card.webp');

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            // Hero <img> and its preload both point at the conversion…
            ->assertSee('src="'.$system->coverImageUrl('hero').'"', false)
            ->assertSee('href="'.$system->coverImageUrl('hero').'"', false)
            // …the identity thumbnail at the card conversion…
            ->assertSee('src="'.$system->coverImageUrl('card').'"', false)
            // …and the untouched original must no longer be painted by the
            // page (og:image / JSON-LD meta still reference it, by design).
            ->assertDontSee('src="'.$system->coverImageUrl().'"', false);
    });

    it('falls back to thumbnail_url when the hero conversion is missing', function () {
        Storage::fake('public');

        $system = GameSystem::factory()->create([
            'slug' => 'fallback-hero-system',
            'thumbnail_url' => 'https://example.com/bgg-thumb.jpg',
        ]);

        // No cover media at all: coverImageUrl('hero') degrades to the BGG
        // thumbnail so the page keeps rendering during regeneration windows.
        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertSee('https://example.com/bgg-thumb.jpg', false);
    });
});

describe('GameSystemDetail - script delivery', function () {
    it('bundles Livewire through Vite instead of a synchronous script tag', function () {
        $system = GameSystem::factory()->create(['slug' => 'script-delivery-system']);

        $html = get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->getContent();

        // Config-only injection: the runtime itself is imported by app.js.
        expect($html)->toContain('livewireScriptConfig')
            // The auto-injected endpoint script was the page's biggest
            // render-blocker (450ms modeled) — it must be gone.
            ->not->toContain('livewire.min.js')
            ->not->toContain('src="/livewire');
    });
});

describe('EmitPreloadLinkHeaders middleware', function () {
    it('mirrors preload hints into a Link header for anonymous guests', function () {
        $system = performanceTestSystemWithCover();

        $link = get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertHeader('Link')
            ->headers->get('Link');

        expect($link)->toContain('rel=preload; as=font; type="font/woff2"; crossorigin')
            ->toContain('/fonts/inter-latin-var.woff2')
            ->toContain('/fonts/noto-serif-latin-var.woff2')
            ->toContain('rel=preload; as=image; fetchpriority=high')
            ->toContain($system->coverImageUrl('hero'));
    });

    it('never emits Link headers for authenticated users', function () {
        $system = performanceTestSystemWithCover();

        actingAs(User::factory()->create());

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertHeaderMissing('Link');
    });

    it('omits image hints on pages without a hero preload', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'no-hero-system',
            'thumbnail_url' => null,
        ]);

        $link = get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertHeader('Link')
            ->headers->get('Link');

        expect($link)->toContain('as=font')
            ->not->toContain('as=image');
    });
});
