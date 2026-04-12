<?php

namespace App\Livewire\Onboarding;

use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
class CompleteProfile extends Component
{
    public int $step = 1;

    #[Validate(['required', 'string', 'max:50'])]
    public string $gender = '';

    #[Validate(['required', 'string', 'max:50'])]
    public string $pronouns = '';

    #[Validate(['nullable', 'string', 'max:30'])]
    public string $phone = '';

    /** @var array<int> */
    public array $favoriteGameSystemIds = [];

    public function mount(): void
    {
        if (Auth::user()->profile_complete) {
            $this->redirectRoute('dashboard');
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validateOnly('gender');
            $this->validateOnly('pronouns');
        }

        if ($this->step === 2) {
            $this->validateOnly('phone');
        }

        $this->step++;
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function complete(): void
    {
        $user = Auth::user();

        $user->update([
            'gender' => $this->gender,
            'pronouns' => $this->pronouns,
            'phone' => $this->phone ?: null,
            'profile_complete' => true,
            'profile_version' => $user->profile_version + 1,
            'profile_updated_at' => now(),
        ]);

        // Sync favorite game systems
        if (! empty($this->favoriteGameSystemIds)) {
            $user->gameSystemPreferences()->detach();
            foreach ($this->favoriteGameSystemIds as $gameSystemId) {
                $user->gameSystemPreferences()->attach($gameSystemId, [
                    'preference_type' => 'favorite',
                ]);
            }
        }

        $this->redirectRoute('dashboard');
    }

    public function render()
    {
        return view('livewire.onboarding.complete-profile', [
            'gameSystems' => GameSystem::orderBy('name')->get(),
        ]);
    }
}
