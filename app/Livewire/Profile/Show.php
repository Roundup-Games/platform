<?php

namespace App\Livewire\Profile;

use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    #[Locked]
    public bool $userHasPassword;

    /** @var array<int> */
    public array $favoriteGameSystemIds = [];

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
        $this->userHasPassword = $user->hasPasswordSet();
        $this->favoriteGameSystemIds = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'favorite')
            ->pluck('game_systems.id')
            ->toArray();
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
        ]);

        $emailChanged = $user->email !== $validated['email'];

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'gender' => $validated['gender'],
            'pronouns' => $validated['pronouns'],
            'phone' => $validated['phone'],
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
        $syncData = collect($validated['favoriteGameSystemIds'])->mapWithKeys(fn ($id) => [
            $id => ['preference_type' => 'favorite'],
        ])->toArray();
        $user->gameSystemPreferences()->sync($syncData);

        if ($this->avatar) {
            $user->clearMediaCollection('avatar');
            $user->addMediaFromTemporaryUpload($this->avatar)
                ->toMediaCollection('avatar');

            Log::info('Avatar uploaded', ['user_id' => $user->id]);
        }

        Log::info('Profile updated', [
            'user_id' => $user->id,
            'fields_updated' => array_keys($validated),
            'game_systems_count' => count($validated['favoriteGameSystemIds']),
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
            'gameSystems' => GameSystem::orderBy('name')->get(),
        ]);
    }
}
