<?php

use App\Livewire\Media\ImageUpload;
use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Traits\CreatesTeams;

uses(CreatesTeams::class);

// ── Helpers ─────────────────────────────────────────────

function createEventOrganizer(): array
{
    $user = User::factory()->create(['profile_complete' => true]);
    $event = Event::factory()->create(['organizer_id' => $user->id]);

    return ['user' => $user, 'event' => $event];
}

function validImage(): UploadedFile
{
    return UploadedFile::fake()->image('logo.jpg', 200, 200);
}

function validBannerImage(): UploadedFile
{
    return UploadedFile::fake()->image('banner.jpg', 1200, 630);
}

// ── Model Media Conversions ─────────────────────────────

describe('Team media conversions', function () {
    it('registers logo collection as single file', function () {
        $team = Team::factory()->create();
        $collections = $team->getRegisteredMediaCollections();

        $logo = $collections->first(fn ($c) => $c->name === 'logo');
        expect($logo)->not->toBeNull();
        expect($logo->singleFile)->toBeTrue();
    });

// smoke: accepts logo upload
    it('accepts logo upload and creates media record', function () {
        Storage::fake('public');

        $team = Team::factory()->create();
        $media = $team->addMedia(validImage())->toMediaCollection('logo');

        expect($team->hasMedia('logo'))->toBeTrue();
        expect($media->collection_name)->toBe('logo');
        expect($media->mime_type)->toBe('image/jpeg');
    })->group('smoke');

    it('replaces existing logo on new upload', function () {
        Storage::fake('public');

        $team = Team::factory()->create();
        $team->addMedia(validImage())->toMediaCollection('logo');
        expect($team->getMedia('logo'))->toHaveCount(1);

        $team->clearMediaCollection('logo');
        $team->addMedia(validImage())->toMediaCollection('logo');
        expect($team->getMedia('logo'))->toHaveCount(1);
    });

    it('generates thumb, medium, and large conversions', function () {
        Storage::fake('public');

        $team = Team::factory()->create();
        $media = $team->addMedia(validImage())->toMediaCollection('logo');

        $conversions = $media->getMediaConversionNames();
        expect($conversions)->toContain('thumb');
        expect($conversions)->toContain('medium');
        expect($conversions)->toContain('large');
    });

    it('restricts accepted mime types for logo', function () {
        $team = Team::factory()->create();
        $collections = $team->getRegisteredMediaCollections();
        $logo = $collections->first(fn ($c) => $c->name === 'logo');

        expect($logo->acceptsMimeTypes)->toContain('image/jpeg');
        expect($logo->acceptsMimeTypes)->toContain('image/png');
        expect($logo->acceptsMimeTypes)->toContain('image/webp');
    });
});

describe('Event media conversions', function () {
    it('registers logo and banner collections', function () {
        $event = Event::factory()->create();
        $collections = $event->getRegisteredMediaCollections();

        $logo = $collections->first(fn ($c) => $c->name === 'logo');
        $banner = $collections->first(fn ($c) => $c->name === 'banner');

        expect($logo)->not->toBeNull();
        expect($banner)->not->toBeNull();
        expect($logo->singleFile)->toBeTrue();
        expect($banner->singleFile)->toBeTrue();
    });

    it('accepts logo upload', function () {
        Storage::fake('public');

        $event = Event::factory()->create();
        $media = $event->addMedia(validImage())->toMediaCollection('logo');

        expect($event->hasMedia('logo'))->toBeTrue();
        expect($media->collection_name)->toBe('logo');
    });

    it('accepts banner upload', function () {
        Storage::fake('public');

        $event = Event::factory()->create();
        $media = $event->addMedia(validBannerImage())->toMediaCollection('banner');

        expect($event->hasMedia('banner'))->toBeTrue();
        expect($media->collection_name)->toBe('banner');
    });

    it('generates event-specific conversions including banner_thumb', function () {
        Storage::fake('public');

        $event = Event::factory()->create();
        $media = $event->addMedia(validImage())->toMediaCollection('logo');

        $conversions = $media->getMediaConversionNames();
        expect($conversions)->toContain('thumb');
        expect($conversions)->toContain('medium');
        expect($conversions)->toContain('large');
        expect($conversions)->toContain('banner_thumb');
    });
});

// ── ImageUpload Livewire Component ──────────────────────

describe('ImageUpload component', function () {
    it('renders with model and collection', function () {
        Storage::fake('public');
        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
                'label' => 'Team Logo',
            ])
            ->assertOk()
            ->assertSee('Team Logo')
            ->assertSee('Drag and drop');
    });

    it('shows current media when exists', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();
        $team->addMedia(validImage())->toMediaCollection('logo');

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
                'label' => 'Team Logo',
            ])
            ->assertSet('hasMedia', true);
    });

    it('shows no media state initially', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
                'label' => 'Team Logo',
            ])
            ->assertSet('hasMedia', false);
    });

    it('validates image is required on upload', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->call('upload')
            ->assertHasErrors(['image']);
    });

    it('validates image max size', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();
        $bigFile = UploadedFile::fake()->create('huge.jpg', 5000);

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->set('image', $bigFile)
            ->call('upload')
            ->assertHasErrors(['image']);
    });

    it('validates image file type via mimes rule', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();
        $textFile = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->set('image', $textFile)
            ->call('upload')
            ->assertHasErrors(['image']);
    });

    it('uploads image to team logo collection', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
                'label' => 'Team Logo',
            ])
            ->set('image', validImage())
            ->call('upload')
            ->assertSet('message', 'Team Logo uploaded successfully.')
            ->assertSet('messageType', 'success');

        expect($team->fresh()->hasMedia('logo'))->toBeTrue();
    });

    it('uploads image to event banner collection', function () {
        Storage::fake('public');

        ['user' => $user, 'event' => $event] = createEventOrganizer();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $event,
                'collection' => 'banner',
                'label' => 'Event Banner',
            ])
            ->set('image', validBannerImage())
            ->call('upload')
            ->assertSet('message', 'Event Banner uploaded successfully.')
            ->assertSet('messageType', 'success');

        expect($event->fresh()->hasMedia('banner'))->toBeTrue();
    });

    it('replaces existing image on re-upload', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();
        $team->addMedia(validImage())->toMediaCollection('logo');
        expect($team->getMedia('logo'))->toHaveCount(1);

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
                'label' => 'Team Logo',
            ])
            ->set('image', validImage())
            ->call('upload');

        expect($team->fresh()->getMedia('logo'))->toHaveCount(1);
    });

    it('removes image from collection', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();
        $team->addMedia(validImage())->toMediaCollection('logo');

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
                'label' => 'Team Logo',
            ])
            ->call('remove')
            ->assertSet('message', 'Team Logo removed.')
            ->assertSet('messageType', 'success');

        expect($team->fresh()->hasMedia('logo'))->toBeFalse();
    });

    it('accepts PNG images', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->set('image', UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('upload')
            ->assertSet('messageType', 'success');

        expect($team->fresh()->hasMedia('logo'))->toBeTrue();
    });

    it('accepts WebP images', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->set('image', UploadedFile::fake()->image('logo.webp', 200, 200))
            ->call('upload')
            ->assertSet('messageType', 'success');

        expect($team->fresh()->hasMedia('logo'))->toBeTrue();
    });

    it('accepts GIF images', function () {
        Storage::fake('public');

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->set('image', UploadedFile::fake()->image('logo.gif', 200, 200))
            ->call('upload')
            ->assertSet('messageType', 'success');

        expect($team->fresh()->hasMedia('logo'))->toBeTrue();
    });
});

// ── Logging ─────────────────────────────────────────────

describe('Media upload logging', function () {
    it('logs successful uploads', function () {
        Storage::fake('public');
        Log::shouldReceive('info')
            ->with('Media uploaded', \Mockery::on(fn ($ctx) => isset($ctx['collection'], $ctx['model_id'], $ctx['uploaded_by'])))
            ->once();

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->set('image', validImage())
            ->call('upload');
    });

    it('logs media removal', function () {
        Storage::fake('public');
        Log::shouldReceive('info')
            ->with('Media removed', \Mockery::on(fn ($ctx) => isset($ctx['collection'], $ctx['model_id'])))
            ->once();

        ['captain' => $user, 'team' => $team] = $this->createTeamWithCaptain();
        $team->addMedia(validImage())->toMediaCollection('logo');

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->call('remove');
    });
});
