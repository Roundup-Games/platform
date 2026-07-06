<?php

use App\Livewire\Campaigns\CreateCampaign;
use App\Livewire\Games\CreateGame;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ═══════════════════════════════════════════════════════════
// T02 (S07): cover-image upload via CreateGame / CreateCampaign.
//
// Exercises the WithFileUploads + addMedia()->toMediaCollection('cover')
// path and the resolveCoverUrl() fallback chain as observed by the created
// entity. The model-layer chain is pinned in ResolvesCoverImageTest.php
// (T01); these tests cover the Livewire wiring + consumer observability
// (getDynamicSEOData reads resolveCoverUrl()).
// ═══════════════════════════════════════════════════════════

function createGameOwner(): User
{
    return gameTestCreateUserWithPermission('create game');
}

describe('CreateGame cover-image upload', function () {
    it('persists an uploaded cover to the cover collection and resolves it via resolveCoverUrl()', function () {
        Storage::fake('public');

        $owner = createGameOwner();
        $system = GameSystem::factory()->create();

        Livewire\Livewire::actingAs($owner)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Cover Upload Game')
            ->set('game_system_id', $system->id)
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('cover_image', UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->call('save')
            ->assertHasNoErrors();

        $game = Game::where('owner_id', $owner->id)->first();
        expect($game)->not->toBeNull();

        // Host cover media was attached to the 'cover' collection.
        $media = $game->getFirstMedia('cover');
        expect($media)->not->toBeNull();

        // resolveCoverUrl() returns the host cover (rung 1 wins).
        expect($game->resolveCoverUrl())->toBe($media->getUrl());

        // getDynamicSEOData() (a wired consumer) surfaces the host cover.
        expect($game->getDynamicSEOData()->image)->toBe($media->getUrl());
    });

    it('fires the fallback chain (representative system cover) when no cover is uploaded', function () {
        Storage::fake('public');

        $system = GameSystem::factory()->create([
            'thumbnail_url' => 'https://example.com/rep-system.jpg',
        ]);
        $owner = createGameOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'No Cover Game')
            ->set('game_system_id', $system->id)
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            // No cover_image set.
            ->call('save')
            ->assertHasNoErrors();

        $game = Game::where('owner_id', $owner->id)->first();
        expect($game->getFirstMedia('cover'))->toBeNull()
            ->and($game->resolveCoverUrl())->toBe('https://example.com/rep-system.jpg')
            ->and($game->getDynamicSEOData()->image)->toBe('https://example.com/rep-system.jpg');
    });

    it('rejects a non-image file via the image validation rule', function () {
        Storage::fake('public');

        $owner = createGameOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Bad Upload Game')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('cover_image', UploadedFile::fake()->create('not-an-image.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['cover_image']);
    });
});

describe('CreateCampaign cover-image upload', function () {
    it('persists an uploaded cover and resolves it via resolveCoverUrl()', function () {
        Storage::fake('public');

        $owner = gameTestCreateUserWithPermission('create game');
        $system = GameSystem::factory()->create();

        Livewire\Livewire::actingAs($owner)
            ->test(CreateCampaign::class)
            ->set('name', 'Cover Upload Campaign')
            ->set('game_system_id', $system->id)
            ->set('cover_image', UploadedFile::fake()->image('camp-cover.png', 1200, 630))
            ->call('save')
            ->assertHasNoErrors();

        $campaign = Campaign::where('owner_id', $owner->id)->first();
        $media = $campaign->getFirstMedia('cover');
        expect($media)->not->toBeNull()
            ->and($campaign->resolveCoverUrl())->toBe($media->getUrl())
            ->and($campaign->getDynamicSEOData()->image)->toBe($media->getUrl());
    });

    it('falls back to the representative system cover when no cover is uploaded', function () {
        $system = GameSystem::factory()->create([
            'thumbnail_url' => 'https://example.com/camp-rep.jpg',
        ]);
        $owner = gameTestCreateUserWithPermission('create game');

        Livewire\Livewire::actingAs($owner)
            ->test(CreateCampaign::class)
            ->set('name', 'No Cover Campaign')
            ->set('game_system_id', $system->id)
            ->call('save')
            ->assertHasNoErrors();

        $campaign = Campaign::where('owner_id', $owner->id)->first();
        expect($campaign->resolveCoverUrl())->toBe('https://example.com/camp-rep.jpg');
    });
});
