<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
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

    #[Validate(['nullable', 'image', 'max:1024'])]
    public $avatar;

    // Password change fields
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $showPasswordForm = false;
    public bool $saved = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->gender = $user->gender ?? '';
        $this->pronouns = $user->pronouns ?? '';
        $this->phone = $user->phone ?? '';
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
                'old_email' => $user->email,
                'new_email' => $validated['email'],
            ]);
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
            'profile_version' => $user->profile_version,
        ]);

        $this->saved = true;
    }

    public function changePassword(): void
    {
        $user = Auth::user();

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

        $user->update(['password' => Hash::make($this->password)]);

        Log::info('Password changed', ['user_id' => $user->id]);

        $this->reset(['current_password', 'password', 'password_confirmation', 'showPasswordForm']);
        session()->flash('password_updated', 'Password updated successfully.');
    }

    public function removeAvatar(): void
    {
        $user = Auth::user();
        $user->clearMediaCollection('avatar');

        Log::info('Avatar removed', ['user_id' => $user->id]);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.profile.show', [
            'linkedAccounts' => $user->linkedAccounts()->get(),
            'gameSystemPreferences' => $user->gameSystemPreferences()->get(),
        ]);
    }
}
