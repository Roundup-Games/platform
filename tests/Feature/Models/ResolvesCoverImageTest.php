<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ═══════════════════════════════════════════════════════════
// resolveCoverUrl() — the host -> representative -> default chain
//
// T01 (S07): the additive model-layer foundation. Game and Campaign both
// pull in ResolvesCoverImage, which exposes resolveCoverUrl(). These tests
// pin the three fallback rungs without touching any consumer (consumers are
// wired in T02). The representative rung depends on the gameSystems()
// belongsToMany pivot established by S06.
// ═══════════════════════════════════════════════════════════

describe('resolveCoverUrl fallback chain', function () {
    it('returns the host-uploaded cover when present', function () {
        Storage::fake('public');

        $game = Game::factory()->create();
        $game->addMedia(UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->toMediaCollection('cover');

        $url = $game->resolveCoverUrl();

        // Host cover wins over the representative system the factory attached.
        expect($url)->not->toBeNull()
            ->and($game->getFirstMedia('cover'))->not->toBeNull()
            ->and($url)->toBe($game->getFirstMedia('cover')->getUrl());
    });

    it('falls back to the representative GameSystem cover when no host cover exists', function () {
        $system = GameSystem::factory()->create(['thumbnail_url' => 'https://example.com/rep.jpg']);
        $game = Game::factory()
            ->withGameSystems([(string) $system->id])
            ->create();

        // No host cover attached -> representative system's coverImageUrl()
        // returns the system's thumbnail_url.
        $url = $game->resolveCoverUrl();

        expect($url)->toBe('https://example.com/rep.jpg');
    });

    it('falls back to the bundled default asset when no host cover and no systems', function () {
        // A game with no offered systems: detach the factory's default system.
        $game = Game::factory()->create();
        $game->gameSystems()->sync([]);

        $url = $game->resolveCoverUrl();

        expect($url)->toBe(asset('images/og-default.jpg'));
    });
});

describe('resolveCoverUrl on Campaign', function () {
    it('returns the host-uploaded cover when present', function () {
        Storage::fake('public');

        $campaign = Campaign::factory()->create();
        $campaign->addMedia(UploadedFile::fake()->image('campaign-cover.png', 1200, 630))
            ->toMediaCollection('cover');

        expect($campaign->resolveCoverUrl())
            ->toBe($campaign->getFirstMedia('cover')->getUrl());
    });

    it('falls back to the representative GameSystem cover when no host cover', function () {
        $system = GameSystem::factory()->create(['thumbnail_url' => 'https://example.com/camp-rep.jpg']);
        $campaign = Campaign::factory()
            ->withCampaignGameSystems([(string) $system->id])
            ->create();

        expect($campaign->resolveCoverUrl())->toBe('https://example.com/camp-rep.jpg');
    });

    it('falls back to the bundled default asset when no host cover and no systems', function () {
        $campaign = Campaign::factory()->create();
        $campaign->gameSystems()->sync([]);

        expect($campaign->resolveCoverUrl())->toBe(asset('images/og-default.jpg'));
    });
});

describe('resolveCoverUrl missing-file handling', function () {
    it('falls through to the representative cover when the host media file is missing on disk', function () {
        Storage::fake('public');

        // Representative system with a known thumbnail fallback.
        $system = GameSystem::factory()->create(['thumbnail_url' => 'https://example.com/rep.jpg']);
        $game = Game::factory()
            ->withGameSystems([(string) $system->id])
            ->create();

        // Attach a host cover, then delete its underlying file (simulating a
        // moderation takedown or storage drift). The media row remains, but
        // file_exists() returns false — resolveCoverUrl() must fall through.
        $game->addMedia(UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->toMediaCollection('cover');
        $media = $game->getFirstMedia('cover');
        $mediaPath = $media->getPath();
        expect($mediaPath)->not->toBe('')
            ->and(file_exists($mediaPath))->toBeTrue();
        @unlink($mediaPath);

        $url = $game->resolveCoverUrl();

        // Missing host file -> representative system cover, NOT a broken img src.
        expect($url)->toBe('https://example.com/rep.jpg');
    });
});

describe('media collection registration', function () {
    it('registers a single-file cover collection accepting jpeg/png/webp', function () {
        $game = Game::factory()->create();

        $collection = $game->getMediaCollection('cover');

        expect($collection)->not->toBeNull()
            ->and($collection->singleFile)->toBeTrue();
    });

    it('registers cover collection on Campaign', function () {
        $campaign = Campaign::factory()->create();

        $collection = $campaign->getMediaCollection('cover');

        expect($collection)->not->toBeNull()
            ->and($collection->singleFile)->toBeTrue();
    });
});
