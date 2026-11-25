<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

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

// ═══════════════════════════════════════════════════════════
// resolveCoverUrl(conversion) — A CONVERSION THAT DOES NOT EXIST (YET)
//
// The thumb / og / hero renditions are produced by a QUEUED job (M084/S08-T05).
// Until a worker has run it, the media ROW and the original exist and the
// conversion file does not — that is worker lag, and rung 1 serves the original.
// Only a missing ORIGINAL is a takedown or storage drift: warn, and fall back.
//
// phpunit.xml runs the queue sync, so these cases fake the queue to keep the
// conversion job pending — the async shape of production.
// ═══════════════════════════════════════════════════════════

describe('resolveCoverUrl pending conversion', function () {
    it('serves the stored original while the queued conversion has not produced its file, and logs no warning', function (string $modelClass) {
        Storage::fake('public');
        Queue::fake();
        $this->captureLogRecords();

        /** @var Game|Campaign $entity */
        $entity = $modelClass::factory()->create();
        $entity->addMedia(UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->toMediaCollection('cover');
        $media = $entity->getFirstMedia('cover');

        // Pending: the job was pushed, not run — the original is on disk, no rendition is.
        Queue::assertPushed(PerformConversionsJob::class);

        expect(file_exists($media->getPath()))->toBeTrue()
            ->and(file_exists($media->getPath('hero')))->toBeFalse()
            ->and(file_exists($media->getPath('thumb')))->toBeFalse();

        // Rung 1 holds on the original for every rendition.
        expect($entity->resolveCoverUrl('hero'))->toBe($media->getUrl())
            ->and($entity->resolveCoverUrl('thumb'))->toBe($media->getUrl());

        // Worker lag is not a takedown: nothing warns.
        expect($this->logRecord('cover.media_file_missing'))->toBeNull();

        // It stays visible without paging: one info line per stand-in, naming the media.
        $pending = array_values(array_filter(
            $this->logRecords(),
            static fn (array $record): bool => $record['message'] === 'cover.conversion_pending',
        ));

        expect($pending)->toHaveCount(2)
            ->and($pending[0])->toBe([
                'message' => 'cover.conversion_pending',
                'level_name' => 'INFO',
                'context' => [
                    'entity_type' => $modelClass,
                    'entity_id' => $entity->getKey(),
                    'media_id' => $media->getKey(),
                    'conversion' => 'hero',
                ],
            ])
            ->and($pending[1]['context']['conversion'])->toBe('thumb');
    })->with([
        'game' => [Game::class],
        'campaign' => [Campaign::class],
    ]);

    it('still warns and still falls back when the original is gone too — a moderation takedown', function (Closure $createEntity) {
        Storage::fake('public');
        $this->captureLogRecords();

        $system = GameSystem::factory()->create(['thumbnail_url' => 'https://example.com/rep.jpg']);

        /** @var Game|Campaign $entity */
        $entity = $createEntity($system);
        $entity->addMedia(UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->toMediaCollection('cover');
        $media = $entity->getFirstMedia('cover');

        // The original AND its rendition are gone; the media row remains.
        @unlink($media->getPath());
        @unlink($media->getPath('hero'));

        expect(file_exists($media->getPath()))->toBeFalse()
            ->and(file_exists($media->getPath('hero')))->toBeFalse();

        // Nothing of the host cover is left to serve -> representative system cover,
        // NOT a broken img src.
        expect($entity->resolveCoverUrl('hero'))->toBe('https://example.com/rep.jpg');

        expect($this->logRecord('cover.media_file_missing'))->toBe([
            'message' => 'cover.media_file_missing',
            'level_name' => 'WARNING',
            'context' => [
                'entity_type' => $entity::class,
                'entity_id' => $entity->getKey(),
                'media_id' => $media->getKey(),
                'collection' => 'cover',
                'conversion' => 'hero',
                'rung' => 'host_cover',
            ],
        ])
            ->and($this->logRecord('cover.conversion_pending'))->toBeNull();
    })->with([
        'game' => [fn (GameSystem $system): Game => Game::factory()
            ->withGameSystems([(string) $system->id])
            ->create()],
        'campaign' => [fn (GameSystem $system): Campaign => Campaign::factory()
            ->withCampaignGameSystems([(string) $system->id])
            ->create()],
    ]);
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
