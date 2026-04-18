<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AddSessionToCampaign extends Component
{
    public Campaign $campaign;

    public string $name = '';

    public string $description = '';

    public string $date_time = '';

    public string $location_details = '';

    public function mount(string $id): void
    {
        $this->campaign = Campaign::findOrFail($id);
        $this->authorize('update', $this->campaign);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'date_time' => 'required|date',
            'location_details' => 'nullable|string|max:1000',
        ];
    }

    public function save(): void
    {
        $this->authorize('create', Game::class);

        $validated = $this->validate();
        $campaign = $this->campaign;
        $ownerId = Auth::id();

        $game = DB::transaction(function () use ($validated, $campaign, $ownerId) {
            $game = Game::create([
                'owner_id' => $ownerId,
                'campaign_id' => $campaign->id,
                'game_system_id' => $campaign->game_system_id,
                'name' => $validated['name'],
                'description' => $validated['description'],
                'date_time' => $validated['date_time'],
                'expected_duration' => $campaign->session_duration ?? 2,
                'price' => $campaign->price_per_session ?? 0,
                'language' => $campaign->language,
                'location' => [
                    'details' => $validated['location_details'],
                ],
                'status' => 'scheduled',
                'visibility' => $campaign->visibility,
                'min_players' => $campaign->min_players,
                'max_players' => $campaign->max_players,
                'experience_level' => $campaign->experience_level,
                'complexity' => $campaign->complexity,
                'vibe_flags' => $campaign->vibe_flags,
            ]);

            // Auto-invite approved campaign participants as invited to this session
            $autoInvitedCount = 0;
            $approvedParticipants = $campaign->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $ownerId)
                ->get();

            foreach ($approvedParticipants as $campaignParticipant) {
                GameParticipant::create([
                    'game_id' => $game->id,
                    'user_id' => $campaignParticipant->user_id,
                    'role' => 'invited',
                    'status' => 'pending',
                ]);
                $autoInvitedCount++;
            }

            Log::info('Game session added to campaign', [
                'game_id' => $game->id,
                'campaign_id' => $campaign->id,
                'owner_id' => $ownerId,
                'auto_invited_count' => $autoInvitedCount,
            ]);

            return $game;
        });

        session()->flash('success', __('campaigns.flash_session_name_added_to_campaign', ['name' => $game->name]));

        $this->redirect(route('games.detail', $game->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.campaigns.add-session-to-campaign');
    }
}
