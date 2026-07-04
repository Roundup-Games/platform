<?php

namespace App\Livewire\Campaigns;

use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\SessionAddedToCampaign;
use App\Services\NotificationService;
use App\Services\OwnerParticipantService;
use App\Services\ParticipantService;
use App\Services\RecurrenceService;
use Illuminate\Contracts\View\View;
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

        // Pre-fill the next cadence date when deep-linked from a "plan ahead"
        // nudge CTA (?prefill=1). Only date/time is pre-filled — the host still
        // names the session. RecurrenceService::nextSuggestedDateTime returns
        // null for any campaign without a recognisable recurrence, so the null
        // check below is the sole (defensive) gate. (The campaigns.recurrence
        // enum column currently restricts values to weekly/bi-weekly/monthly,
        // so the suggestion is non-null for every persisted campaign.)
        if (request()->boolean('prefill')) {
            $suggested = app(RecurrenceService::class)->nextSuggestedDateTime($this->campaign);
            if ($suggested) {
                $this->date_time = $suggested->format('Y-m-d\TH:i'); // datetime-local input format
            }
        }
    }

    /**
     * @return array<string, string>
     */
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

        // Resolve the campaign's intended game type, defaulting to 'ttrpg' for
        // legacy campaigns created before campaigns.game_type existed (R050).
        // A Gathering-type campaign spawns Gathering-type sessions so recurring
        // nights flow through S03's Gathering-aware discovery/card path.
        $campaignGameTypeObject = $campaign->game_type;
        $campaignGameType = $campaignGameTypeObject !== null ? $campaignGameTypeObject->value : 'ttrpg';

        // Defensive check: warn only when a TTRPG-typed campaign carries a
        // non-TTRPG game system. (Gathering campaigns legitimately host any
        // system, so the warning is gated on the ttrpg type.)
        if ($campaignGameType === 'ttrpg' && $campaign->gameSystem && $campaign->gameSystem->type !== 'ttrpg') {
            Log::warning('add_session_to_campaign.non_ttrpg_system', [
                'campaign_id' => $campaign->id,
                'game_system_id' => $campaign->game_system_id,
                'game_system_type' => $campaign->gameSystem->type,
            ]);
        }

        // Copy-on-write: the spawned session gets its OWN game_game_system
        // rows duplicated from the campaign's campaign_game_system default set at
        // creation time. Once created, the session's offering is frozen and
        // independent — editing the campaign's default later does NOT change
        // already-scheduled sessions (RSVP stability). This enables the 'special
        // session' override: a host can add/remove a system on a single session
        // without touching the recurring default.
        //
        // campaign_game_system is the canonical template (single-system today,
        // multi-system ready). For legacy single-system campaigns it carries one
        // row; for Gathering campaigns it carries the host's picked set. We read
        // it once here and pass it both to the legacy column writes (kept alive
        // until T06 drops them) and to the pivot sync below.
        $campaignSystemIds = $campaign->gameSystems()->allRelatedIds()->all();

        // Fallback for campaigns whose pivot was never populated (defensive —
        // should not happen post-backfill, but keeps legacy data working):
        // derive the set from the cached anchor.
        if (empty($campaignSystemIds) && $campaign->game_system_id !== null) {
            $campaignSystemIds = [$campaign->game_system_id];
        }

        // For a Gathering campaign, materialize the 1-element game_systems set
        // (R046: a single-system game is a 1-element set) so the spawned session
        // is a valid Gathering that renders/ranks through S03's Gathering-aware
        // path. The Game saving event (S01) keeps game_system_id === game_systems[0].
        $gameSystems = ($campaignGameType === 'gathering' && ! empty($campaignSystemIds))
            ? array_values(array_map('strval', $campaignSystemIds))
            : null;

        $game = DB::transaction(function () use ($validated, $campaign, $ownerId, $campaignGameType, $gameSystems, $campaignSystemIds) {
            $game = Game::create([
                'owner_id' => $ownerId,
                'campaign_id' => $campaign->id,
                'game_system_id' => $campaign->game_system_id,
                'game_systems' => $gameSystems,
                'name' => $validated['name'],
                'description' => $validated['description'],
                'date_time' => $validated['date_time'],
                'expected_duration' => $campaign->session_duration ?? 2,
                'price' => $campaign->price_per_session ?? 0,
                'language' => $campaign->language,
                'location' => [
                    'details' => $validated['location_details'],
                ],
                'game_type' => $campaignGameType,
                'status' => 'scheduled',
                'visibility' => $campaign->visibility,
                'min_players' => $campaign->min_players,
                'max_players' => $campaign->max_players,
                'experience_level' => $campaign->experience_level,
                'complexity' => $campaign->complexity,
                'vibe_flags' => $campaign->vibe_flags,
                'bench_mode' => $campaign->bench_mode,
            ]);

            // Ensure owner participant exists before counting capacity
            app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

            // Copy-on-write the campaign's default system set into the session's
            // own game_game_system rows. This is the canonical write — the legacy
            // column writes above are kept in sync for the transition (retired
            // in T06). Empty set is guarded so sync() never detaches.
            if (! empty($campaignSystemIds)) {
                $game->gameSystems()->sync(array_values(array_map('strval', $campaignSystemIds)));
            }

            // Auto-invite approved campaign participants as invited to this session
            $autoInvitedCount = 0;
            $benchedCount = 0;
            $approvedParticipants = $campaign->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $ownerId)
                ->get();

            foreach ($approvedParticipants as $campaignParticipant) {
                // Re-check capacity each iteration (previous invite may have filled the last slot)
                $isFull = app(ParticipantService::class)->isAtCapacity($game);

                if ($isFull) {
                    // Place on bench instead of inviting
                    GameParticipant::create([
                        'game_id' => $game->id,
                        'user_id' => $campaignParticipant->user_id,
                        'role' => ParticipantRole::Player->value,
                        'status' => ParticipantStatus::Benched->value,
                        'benched_at' => now(),
                    ]);
                    $benchedCount++;
                } else {
                    GameParticipant::create([
                        'game_id' => $game->id,
                        'user_id' => $campaignParticipant->user_id,
                        'role' => ParticipantRole::Invited->value,
                        'status' => ParticipantStatus::Pending->value,
                    ]);
                    $autoInvitedCount++;
                }
            }

            Log::info('Game session added to campaign', [
                'game_id' => $game->id,
                'campaign_id' => $campaign->id,
                'owner_id' => $ownerId,
                'auto_invited_count' => $autoInvitedCount,
                'benched_count' => $benchedCount,
            ]);

            return $game;
        });

        // Dispatch SessionAddedToCampaign to each auto-invited participant
        try {
            $notificationService = app(NotificationService::class);
            $notification = new SessionAddedToCampaign($game, $campaign);

            $notifiedUserIds = $campaign->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $ownerId)
                ->pluck('user_id');

            foreach ($notifiedUserIds as $userId) {
                $participant = User::find(is_string($userId) ? $userId : null);
                if ($participant) {
                    $notificationService->send(
                        $participant,
                        new SessionAddedToCampaign($game, $campaign),
                        NotificationCategory::SessionAddedToCampaign,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error('notification.session_added_dispatch_failed', [
                'game_id' => $game->id,
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        session()->flash('success', __('campaigns.flash_session_name_added_to_campaign', ['name' => $game->name]));

        $this->redirect(route('games.show', $game->id), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.campaigns.add-session-to-campaign');
    }
}
