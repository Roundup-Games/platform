<?php

namespace App\Livewire\Profile;

use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public string $name = '';
    public string $email = '';
    public string $gender = '';
    public string $pronouns = '';
    public string $phone = '';

    /** @var array<int> */
    public array $favoriteGameSystemIds = [];

    public bool $saved = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->gender = $user->gender ?? '';
        $this->pronouns = $user->pronouns ?? '';
        $this->phone = $user->phone ?? '';
        $this->favoriteGameSystemIds = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'favorite')
            ->pluck('game_systems.id')
            ->toArray();
    }

    public function save(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'gender' => ['nullable', 'string', 'max:50'],
            'pronouns' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'favoriteGameSystemIds' => ['array'],
            'favoriteGameSystemIds.*' => ['exists:game_systems,id'],
        ]);

        $emailChanged = $user->email !== $validated['email'];

        $user->update([
            ...$validated,
            'profile_version' => ($user->profile_version ?? 0) + 1,
            'profile_updated_at' => now(),
        ]);

        if ($emailChanged) {
            $user->update(['email_verified_at' => null]);
            Log::info('Profile email changed', [
                'user_id' => $user->id,
                'new_email' => $validated['email'],
            ]);
        }

        // Sync game system preferences
        $syncData = collect($this->favoriteGameSystemIds)->mapWithKeys(fn ($id) => [
            $id => ['preference_type' => 'favorite'],
        ])->toArray();
        $user->gameSystemPreferences()->sync($syncData);

        Log::info('Profile edited', [
            'user_id' => $user->id,
            'fields_updated' => array_keys($validated),
            'game_systems_count' => count($this->favoriteGameSystemIds),
            'profile_version' => $user->profile_version,
        ]);

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.profile.edit', [
            'gameSystems' => GameSystem::orderBy('name')->get(),
        ]);
    }
}
