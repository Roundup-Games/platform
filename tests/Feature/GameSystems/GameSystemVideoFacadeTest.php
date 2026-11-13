<?php

use App\Models\GameSystem;
use App\View\Components\YouTubeFacade;

use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// YouTube facade (CWV 2026-11): the "How to Play" video on
// game-system pages used to render a direct embed iframe whose
// ~855KB of eager player JS starved the critical path (the
// game-system-ttrpg template measured 57-59 mobile). The facade
// renders poster + play button; the iframe exists only after a
// click. These tests pin the parser and the no-iframe-on-load
// contract.
// ═══════════════════════════════════════════════════════════

describe('YouTubeFacade::parseYouTubeUrl', function () {
    it('is registered as the youtube-facade tag alias (case-sensitivity pin)', function () {
        // Str::studly('youtube-facade') = 'YoutubeFacade' ≠ YouTubeFacade —
        // without the explicit alias, Blade falls back to an ANONYMOUS
        // component on case-sensitive filesystems (production Linux),
        // which 500s video-bearing pages. macOS dev cannot catch this
        // (case-insensitive autoload), so the registration is pinned here.
        expect(Blade::getClassComponentAliases()['youtube-facade'] ?? null)
            ->toBe(YouTubeFacade::class);
    });
    it('extracts ids from every YouTube URL shape', function (string $url, string $id) {
        expect(YouTubeFacade::parseYouTubeUrl($url))->toBe(['id' => $id, 'params' => '']);
    })->with([
        'watch' => ['https://www.youtube.com/watch?v=0TsicWGho7c', '0TsicWGho7c'],
        'short link' => ['https://youtu.be/0TsicWGho7c', '0TsicWGho7c'],
        'embed' => ['https://www.youtube.com/embed/0TsicWGho7c', '0TsicWGho7c'],
        'nocookie' => ['https://www.youtube-nocookie.com/embed/0TsicWGho7c', '0TsicWGho7c'],
        'mobile' => ['https://m.youtube.com/watch?v=0TsicWGho7c', '0TsicWGho7c'],
        'shorts' => ['https://www.youtube.com/shorts/0TsicWGho7c', '0TsicWGho7c'],
    ]);

    it('retains benign params and drops the watch-page v param', function () {
        $parsed = YouTubeFacade::parseYouTubeUrl('https://www.youtube.com/watch?v=0TsicWGho7c&si=abc123&t=30');

        expect($parsed)->toBe(['id' => '0TsicWGho7c', 'params' => 'si=abc123&t=30']);
    });

    it('rejects non-YouTube URLs and garbage', function (string $url) {
        expect(YouTubeFacade::parseYouTubeUrl($url))->toBeNull();
    })->with([
        'vimeo' => ['https://vimeo.com/12345'],
        'relative' => ['/games/some-id'],
        'empty-ish host' => ['https://youtube.com.evil.example/watch?v=0TsicWGho7c'],
        'bad id' => ['https://youtu.be/!!invalid id!!'],
    ]);
});

describe('game-system page video facade', function () {
    it('renders the facade button and no iframe on initial load', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'video-facade-system',
            'instructions' => [
                'description' => 'A quick intro',
                'videoUrl' => 'https://www.youtube.com/watch?v=0TsicWGho7c',
            ],
        ]);

        $html = get("/en/game-systems/{$system->slug}")->assertOk()->getContent();

        // Poster + play button contract the JS handler binds to…
        expect($html)
            ->toContain('data-youtube-facade-play')
            ->toContain('data-video-id="0TsicWGho7c"')
            ->toContain('https://i.ytimg.com/vi/0TsicWGho7c/hqdefault.jpg')
            // …and no YouTube iframe may ship in the initial document.
            ->not->toContain('<iframe');
    });

    it('degrades an unparseable video URL to an outbound link, never a broken embed', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'bad-video-url-system',
            'instructions' => [
                'description' => 'Intro',
                'videoUrl' => 'https://vimeo.com/12345',
            ],
        ]);

        $html = get("/en/game-systems/{$system->slug}")->assertOk()->getContent();

        expect($html)
            ->toContain('https://vimeo.com/12345')
            ->toContain(__('common.action_watch_video_external', ['title' => $system->name.' — How to Play'], 'en'))
            ->not->toContain('<iframe');
    });

    it('renders nothing when no videoUrl is set', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'no-video-system',
            'instructions' => ['description' => 'Just text'],
        ]);

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertDontSee('data-youtube-facade-play');
    });
});
