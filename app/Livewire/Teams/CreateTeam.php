<?php

namespace App\Livewire\Teams;

use App\Models\Team;
use App\Traits\BuildsTranslatableFormFields;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateTeam extends Component
{
    use BuildsTranslatableFormFields;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    // ── Translatable fields ──
    /**
     * @return array<int, string>
     */
    public function getTranslatableFields(): array
    {
        return ['description'];
    }

    #[Validate('nullable|string|max:255')]
    public string $city = '';

    #[Validate('nullable|string|max:3')]
    public string $country = '';

    #[Validate('nullable|string|max:7')]
    public string $primary_color = '';

    #[Validate('nullable|string|max:7')]
    public string $secondary_color = '';

    #[Validate('nullable|string|max:4')]
    public string $founded_year = '';

    public function save(): void
    {
        $this->authorize('create', Team::class);

        $validated = $this->validate();
        $this->validate(
            $this->translatableValidationRules(
                ['description' => 'nullable|string|max:1000'],
                app()->getLocale(),
            ),
        );

        // Teams inherit the creator's locale as their language baseline.
        // This ensures translatable fields (description) are keyed correctly.
        $primaryLocale = app()->getLocale();
        $translatable = $this->buildTranslatableValues(
            ['description'],
            $primaryLocale,
            $validated,
        );

        $team = Team::create([
            'name' => $validated['name'],
            'description' => $translatable['description'],
            'city' => $validated['city'],
            'country' => $validated['country'],
            'primary_color' => $validated['primary_color'],
            'secondary_color' => $validated['secondary_color'],
            'founded_year' => $validated['founded_year'],
            'language' => $primaryLocale,
            'created_by' => Auth::id(),
            'is_active' => true,
        ]);

        // Creator becomes captain
        $team->members()->create([
            'user_id' => Auth::id(),
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Log::info('Team created', [
            'team_id' => $team->id,
            'team_slug' => $team->slug,
            'created_by' => Auth::id(),
        ]);

        session()->flash('success', __('teams.flash_team_name_created_successfully', ['name' => $team->name]));

        $this->redirect(route('teams.detail', $team->slug), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.teams.create-team');
    }
}
