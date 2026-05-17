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

});

// ── Logging ─────────────────────────────────────────────

describe('Media upload logging', function () {
    it('logs successful uploads', function () {
        Storage::fake('public');
        Log::shouldReceive('debug')->zeroOrMoreTimes();
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
        Log::shouldReceive('debug')->zeroOrMoreTimes();
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
