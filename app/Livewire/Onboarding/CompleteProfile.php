<?php

namespace App\Livewire\Onboarding;

use App\Enums\ContentLanguage;
use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('onboarding.layout')]
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
    #[Validate(['array'])]
    public array $favoriteGameSystemIds = [];

    public function rules(): array
    {
        return [
            'favoriteGameSystemIds.*' => ['exists:game_systems,id'],
        ];
    }

    public function mount(): void
    {
        $user = Auth::user();

        if ($user->profile_complete) {
            $this->redirectRoute('dashboard');

            return;
        }

        // Pre-fill from any existing user data (e.g. from OAuth)
        $this->gender = $user->gender ?? '';
        $this->pronouns = $user->pronouns ?? '';
        $this->phone = $user->phone ?? '';
    }

    public function nextStep(): void
    {
        $this->validateStep($this->step);
        $this->step++;
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function complete(): void
    {
        // Validate all steps before completing
        $this->validateStep(1);
        $this->validateStep(2);

        // Validate game system IDs against actual GameSystem records
        $this->validate([
            'favoriteGameSystemIds' => ['array'],
            'favoriteGameSystemIds.*' => ['exists:game_systems,id'],
        ]);

        $user = Auth::user();

        $locale = app()->getLocale();
        $preferredLanguage = match ($locale) {
            'de' => ContentLanguage::De,
            default => ContentLanguage::En,
        };

        $user->update([
            'gender' => $this->gender,
            'pronouns' => $this->pronouns,
            'phone' => $this->phone ?: null,
            'preferred_language' => $preferredLanguage,
            'profile_complete' => true,
            'profile_version' => ($user->profile_version ?? 0) + 1,
            'profile_updated_at' => now(),
        ]);

        // Sync favorite game systems using syncWithPivotValues for idempotency
        $syncData = collect($this->favoriteGameSystemIds)->mapWithKeys(fn ($id) => [
            $id => ['preference_type' => 'favorite'],
        ])->toArray();
        $user->gameSystemPreferences()->sync($syncData);

        Log::info('Onboarding completed', [
            'user_id' => $user->id,
            'gender' => $this->gender,
            'game_systems_count' => count($this->favoriteGameSystemIds),
            'profile_version' => $user->profile_version,
        ]);

        $this->redirectRoute('dashboard');
    }

    public function render()
    {
        return view('livewire.onboarding.complete-profile', [
            'gameSystems' => GameSystem::orderBy('name')->get(),
        ]);
    }

    private function validateStep(int $step): void
    {
        match ($step) {
            1 => (function () {
                $this->validateOnly('gender');
                $this->validateOnly('pronouns');
            })(),
            2 => $this->validateOnly('phone'),
            default => null,
        };
    }
}
