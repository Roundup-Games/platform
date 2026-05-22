<?php

namespace App\Livewire\Teams;

use App\Models\Team;
use App\Traits\BuildsTranslatableFormFields;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ManageTeam extends Component
{
    use BuildsTranslatableFormFields;

    public Team $team;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    // ── Translatable fields ──
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

    public bool $saved = false;

    public function mount(string $slug): void
    {
        $team = Team::where('slug', $slug)->firstOrFail();
        $this->authorize('update', $team);
        $this->team = $team;

        $this->name = $team->name;
        $this->description = $team->description ?? '';
        $this->city = $team->city ?? '';
        $this->country = $team->country ?? '';
        $this->primary_color = $team->primary_color ?? '';
        $this->secondary_color = $team->secondary_color ?? '';
        $this->founded_year = $team->founded_year ?? '';

        // Load secondary locale translations for description
        $this->loadTranslatableValues($team, ['description']);
    }

    public function save(): void
    {
        $this->authorize('update', $this->team);

        $validated = $this->validate();

        // Build translatable value for description only
        $primaryLocale = $this->team->language ?? app()->getLocale();
        $translatable = $this->buildTranslatableValues(
            ['description'],
            $primaryLocale,
            $validated,
        );

        $this->team->update([
            'name' => $validated['name'],
            'description' => $translatable['description'],
            'city' => $validated['city'],
            'country' => $validated['country'],
            'primary_color' => $validated['primary_color'],
            'secondary_color' => $validated['secondary_color'],
            'founded_year' => $validated['founded_year'],
        ]);

        Log::info('Team updated', [
            'team_id' => $this->team->id,
            'updated_by' => Auth::id(),
            'fields_updated' => array_keys($validated),
        ]);

        $this->saved = true;
    }

    public function deleteTeam(): void
    {
        $this->authorize('delete', $this->team);

        Log::info('Team deleted', [
            'team_id' => $this->team->id,
            'team_name' => $this->team->name,
            'deleted_by' => Auth::id(),
        ]);

        $this->team->delete();

        session()->flash('success', __('teams.flash_team_deleted_successfully'));

        $this->redirect(route('teams.browse'), navigate: true);
    }

    public function render()
    {
        return view('livewire.teams.manage-team', [
            'team' => $this->team,
        ]);
    }
}
