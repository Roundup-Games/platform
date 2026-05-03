<?php

use App\Livewire\Media\ImageUpload;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

describe('ImageUpload security', function () {
    it('does not expose model attributes in Livewire payload', function () {
        Storage::fake('public');

        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create([
            'created_by' => $user->id,
            'is_active' => true,
            'name' => 'Secret Team Name',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
                'label' => 'Team Logo',
            ]);

        // The component should have model_type and model_id
        $component
            ->assertSet('model_type', Team::class)
            ->assertSet('model_id', $team->id);

        // Inspect the snapshot data to ensure no model attributes are leaked
        $data = $component->getData();

        // model_type and model_id are expected
        expect($data)->toHaveKey('model_type');
        expect($data)->toHaveKey('model_id');

        // Ensure no full model data is serialized (e.g. name, created_by, etc.)
        expect($data)->not->toHaveKey('model');
        expect($data)->not->toHaveKey('name');
        expect($data)->not->toHaveKey('created_by');
        expect($data)->not->toHaveKey('is_active');
    })->group('smoke');

    it('locks model_type and model_id from client-side tampering', function () {
        Storage::fake('public');

        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['created_by' => $user->id, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->set('model_type', 'App\Models\Event');

    })->throws(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class)->group('smoke');

    it('locks model_id from client-side tampering', function () {
        Storage::fake('public');

        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['created_by' => $user->id, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
            ])
            ->set('model_id', 99999);

    })->throws(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class)->group('smoke');

    it('resolves the correct model from stored type and id', function () {
        Storage::fake('public');

        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['created_by' => $user->id, 'is_active' => true]);

        // Verify the component works end-to-end with the new resolveModel() approach
        Livewire::actingAs($user)
            ->test(ImageUpload::class, [
                'model' => $team,
                'collection' => 'logo',
                'label' => 'Logo',
            ])
            ->assertSet('hasMedia', false);
    })->group('smoke');
});
