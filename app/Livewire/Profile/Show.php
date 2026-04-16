<?php

namespace App\Livewire\Profile;

use App\Enums\ContentLanguage;
use App\Enums\VibeFlag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class Show extends Component
{
    use WithFileUploads;

    // Profile fields
    public string $name = '';
    public string $email = '';
    public string $gender = '';
    public string $pronouns = '';
    public string $phone = '';

    public string $preferredLanguage = '';
    public string $locationAddress = '';

    #[Locked]
    public bool $userHasPassword;

    /** @var array<int> */
    public array $favoriteGameSystemIds = [];

    /** @var array<int> */
    public array $avoidedGameSystemIds = [];

    /** @var array<string, string|null> Map of VibeFlag value → null|'favorite'|'avoid' */
    public array $vibePreferences = [];

    #[Validate(['nullable', 'image', 'max:1024'])]
    public $avatar;

    // Password fields
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $showPasswordForm = false;

    // Account deletion
    public bool $showDeleteForm = false;
    public string $delete_password = '';
    public string $delete_confirmation = '';

    public bool $saved = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->gender = $user->gender ?? '';
        $this->pronouns = $user->pronouns ?? '';
        $this->phone = $user->phone ?? '';
        $this->preferredLanguage = $user->preferred_language?->value ?? '';
        $this->locationAddress = $user->location['address'] ?? '';
        $this->userHasPassword = $user->hasPasswordSet();
        $this->favoriteGameSystemIds = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'favorite')
            ->pluck('game_systems.id')
            ->toArray();
        $this->avoidedGameSystemIds = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'avoid')
            ->pluck('game_systems.id')
            ->toArray();

        // Load existing vibe preferences
        $this->vibePreferences = $user->vibePreferences->mapWithKeys(function ($pref) {
            return [$pref->vibe_preference_value->value => $pref->preference_type];
        })->toArray();
    }

    public function selectionChanged(string $preferenceType, array $selectedIds): void
    {
        if ($preferenceType === 'favorite') {
            $this->favoriteGameSystemIds = array_map('intval', $selectedIds);
        } elseif ($preferenceType === 'avoid') {
            $this->avoidedGameSystemIds = array_map('intval', $selectedIds);
        }
    }

    protected function getListeners(): array
    {
        return [
            'selection-changed' => 'selectionChanged',
            'vibe-preferences-changed' => 'vibePreferencesChanged',
        ];
    }

    public function vibePreferencesChanged(array $preferences): void
    {
        $this->vibePreferences = $preferences;
    }

    public function saveProfile(): void
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
            'avoidedGameSystemIds' => ['array'],
            'avoidedGameSystemIds.*' => ['exists:game_systems,id'],
            'vibePreferences' => ['array'],
            'vibePreferences.*' => ['nullable', 'in:favorite,avoid'],
            'preferredLanguage' => ['nullable', 'string', 'in:' . implode(',', ContentLanguage::values())],
            'locationAddress' => ['nullable', 'string', 'max:255'],
        ]);

        $emailChanged = $user->email !== $validated['email'];

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'gender' => $validated['gender'],
            'pronouns' => $validated['pronouns'],
            'phone' => $validated['phone'],
            'preferred_language' => $validated['preferredLanguage'] ?: null,
            'location' => $validated['locationAddress'] ? ['address' => $validated['locationAddress']] : null,
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

        // Sync game system preferences (favorites AND avoids)
        $favoriteSync = collect($validated['favoriteGameSystemIds'])->mapWithKeys(fn ($id) => [
            $id => ['preference_type' => 'favorite'],
        ]);
        $avoidSync = collect($validated['avoidedGameSystemIds'])->mapWithKeys(fn ($id) => [
            $id => ['preference_type' => 'avoid'],
        ]);
        $user->gameSystemPreferences()->sync(array_replace($favoriteSync->toArray(), $avoidSync->toArray()));

        // Save vibe preferences (delete-and-insert)
        $validVibeValues = VibeFlag::values();
        $user->vibePreferences()->delete();
        $inserts = [];
        foreach ($this->vibePreferences as $flagValue => $type) {
            if ($type !== null && in_array($type, ['favorite', 'avoid']) && in_array($flagValue, $validVibeValues)) {
                $inserts[] = [
                    'user_id' => $user->id,
                    'vibe_preference_value' => $flagValue,
                    'preference_type' => $type,
                ];
            }
        }
        if (!empty($inserts)) {
            $user->vibePreferences()->createMany($inserts);
        }

        if ($this->avatar) {
            $user->clearMediaCollection('avatar');
            $user->addMediaFromTemporaryUpload($this->avatar)
                ->toMediaCollection('avatar');

            Log::info('Avatar uploaded', ['user_id' => $user->id]);
        }

        Log::info('Profile updated', [
            'user_id' => $user->id,
            'fields_updated' => array_keys($validated),
            'favorite_game_systems_count' => count($validated['favoriteGameSystemIds']),
            'avoided_game_systems_count' => count($validated['avoidedGameSystemIds']),
            'vibe_favorites_count' => count(array_filter($this->vibePreferences, fn ($v) => $v === 'favorite')),
            'vibe_avoids_count' => count(array_filter($this->vibePreferences, fn ($v) => $v === 'avoid')),
            'profile_version' => $user->profile_version,
        ]);

        $this->saved = true;
    }

    public function changePassword(): void
    {
        $user = Auth::user();

        if ($user->hasPasswordSet()) {
            // Existing password — must confirm current
            $this->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if (! Hash::check($this->current_password, $user->password)) {
                Log::warning('Password change failed: incorrect current password', [
                    'user_id' => $user->id,
                ]);

                $this->addError('current_password', 'The provided password is incorrect.');

                return;
            }
        } else {
            // No password set (OAuth user) — just set a new one
            $this->validate([
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
        }

        $user->update([
            'password' => Hash::make($this->password),
            'password_set_at' => now(),
        ]);

        $this->userHasPassword = true;

        Log::info('Password changed', [
            'user_id' => $user->id,
            'had_password_before' => $user->wasRecentlyCreated ? false : ! $user->wasChanged('password_set_at'),
        ]);

        $this->reset(['current_password', 'password', 'password_confirmation', 'showPasswordForm']);
        session()->flash('password_updated', __('Password updated successfully.'));
    }

    public function removeAvatar(): void
    {
        $user = Auth::user();
        $user->clearMediaCollection('avatar');

        Log::info('Avatar removed', ['user_id' => $user->id]);
    }

    public function deleteAccount(): void
    {
        $user = Auth::user();

        if ($user->hasPasswordSet()) {
            $this->validate([
                'delete_password' => ['required', 'string'],
            ]);

            if (! Hash::check($this->delete_password, $user->password)) {
                $this->addError('delete_password', 'The provided password is incorrect.');

                return;
            }
        } else {
            $this->validate([
                'delete_confirmation' => ['required', 'string', 'in:DELETE'],
            ], [
                'delete_confirmation.in' => __('Please type DELETE to confirm account deletion.'),
            ]);
        }

        Log::info('Account deletion initiated by user', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'had_password' => $user->hasPasswordSet(),
        ]);

        Auth::logout();

        $user->delete();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/', navigate: false);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.profile.show', [
            'linkedAccounts' => $user->linkedAccounts()->get(),
        ]);
    }
}
