<?php

namespace App\Livewire\GM\SessionZero;

use App\Enums\SafetyTool;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\SessionZeroSurvey;
use App\Services\GmRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateSessionZero extends Component
{
    // ── Form State ───────────────────────────────────────

    public ?string $game_id = null;

    public string $title = '';

    /** @var string[] Selected safety tool values */
    public array $selectedSafetyTools = [];

    public string $linesAndVeilsText = '';

    public string $safetyCustomNote = '';

    public string $tone_and_genre = '';

    public string $house_rules = '';

    public string $content_warnings = '';

    public string $player_expectations = '';

    // ── UI State ─────────────────────────────────────────

    public bool $saved = false;

    public ?string $shareableLink = null;

    public ?string $shareableUuid = null;

    // ── Lifecycle ────────────────────────────────────────

    public function mount(?string $game_id = null): void
    {
        $user = Auth::user();
        $gmRoleService = app(GmRoleService::class);

        if (! $gmRoleService->isGmActive($user)) {
            $this->redirect(route('dashboard', app()->getLocale()));
            return;
        }

        $this->game_id = $game_id;

        // Default title from game name when a game_id is provided
        if ($game_id) {
            $game = Game::find($game_id);
            if ($game && $game->owner_id === $user->id) {
                $this->title = __('session_zero.title_default_for_game', ['game' => $game->name]);
            }
        }
    }

    // ── Validation ───────────────────────────────────────

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'selectedSafetyTools' => 'nullable|array',
            'selectedSafetyTools.*' => 'string|in:' . implode(',', SafetyTool::values()),
            'linesAndVeilsText' => 'nullable|string|max:2000',
            'safetyCustomNote' => 'nullable|string|max:2000',
            'tone_and_genre' => 'nullable|string|max:2000',
            'house_rules' => 'nullable|string|max:5000',
            'content_warnings' => 'nullable|string|max:5000',
            'player_expectations' => 'nullable|string|max:5000',
        ];
    }

    // ── Event Listeners ──────────────────────────────────

    #[On('safety-tools-changed')]
    public function onSafetyToolsChanged(array $safetyRules): void
    {
        $this->selectedSafetyTools = $safetyRules['tools'] ?? [];
        $this->linesAndVeilsText = $safetyRules['lines_and_veils_text'] ?? '';
        $this->safetyCustomNote = $safetyRules['custom_note'] ?? '';
    }

    // ── Actions ──────────────────────────────────────────

    public function save(): void
    {
        $validated = $this->validate();

        $user = Auth::user();
        $gmProfile = $user->gmProfile;

        if (! $gmProfile) {
            $this->redirect(route('dashboard', app()->getLocale()));
            return;
        }

        // Resolve game_id — only allow games owned by this GM
        $gameId = $this->game_id;
        if ($gameId) {
            $game = Game::find($gameId);
            if (! $game || $game->owner_id !== $user->id) {
                $gameId = null;
            }
        }

        $content = [
            'safety_tools' => $validated['selectedSafetyTools'],
            'lines_and_veils_text' => $validated['linesAndVeilsText'],
            'safety_custom_note' => $validated['safetyCustomNote'],
            'tone_and_genre' => $validated['tone_and_genre'],
            'house_rules' => $validated['house_rules'],
            'content_warnings' => $validated['content_warnings'],
            'player_expectations' => $validated['player_expectations'],
        ];

        $survey = SessionZeroSurvey::create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $gameId,
            'title' => $validated['title'],
            'content' => $content,
        ]);

        Log::info('Session Zero survey created', [
            'survey_id' => $survey->id,
            'uuid' => $survey->uuid,
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $gameId,
        ]);

        $locale = app()->getLocale();
        $this->shareableUuid = $survey->uuid;
        $this->shareableLink = url("/{$locale}/session-zero/{$survey->uuid}");
        $this->saved = true;
    }

    // ── Computed ─────────────────────────────────────────

    /**
     * Get the current safety rules payload for the picker component.
     */
    public function getSafetyRulesProperty(): array
    {
        return [
            'tools' => $this->selectedSafetyTools,
            'lines_and_veils_text' => $this->linesAndVeilsText,
            'custom_note' => $this->safetyCustomNote,
        ];
    }

    // ── Render ───────────────────────────────────────────

    public function render()
    {
        return view('livewire.gm.session-zero.create-session-zero');
    }
}
