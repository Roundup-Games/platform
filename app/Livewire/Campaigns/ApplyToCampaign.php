<?php

namespace App\Livewire\Campaigns;

use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\User;
use App\Notifications\NewApplication;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ApplyToCampaign extends Component
{
    public Campaign $campaign;

    #[Validate('nullable|string|max:1000')]
    public string $message = '';

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);

        if (! Auth::check()) {
            $this->redirect(route('login'));

            return;
        }

        $this->authorize('view', $campaign);
        $this->campaign = $campaign;

        if ($campaign->visibility === Visibility::Private) {
            abort(403, __('campaigns.error_this_campaign_does_not_accept_applications'));
        }

        if ($campaign->participants()->where('user_id', Auth::id())->exists()) {
            session()->flash('info', __('campaigns.content_you_are_already_a_participant_of_this_campaign'));

            return;
        }

        if ($campaign->applications()->where('user_id', Auth::id())->exists()) {
            session()->flash('info', __('campaigns.content_you_have_already_applied_to_this_campaign'));

            return;
        }
    }

    public function submitApplication(): void
    {
        if ($this->campaign->owner_id === Auth::id()) {
            $this->addError('message', __('campaigns.error_you_cannot_apply_to_your_own_campaign'));

            return;
        }

        $campaignId = $this->campaign->id;
        $userId = Auth::id();
        $message = $this->message;

        $this->validate();

        try {
            DB::transaction(function () use ($campaignId, $userId, $message) {
                CampaignParticipant::lockForUpdate()
                    ->where('campaign_id', $campaignId)
                    ->where('user_id', $userId)
                    ->exists();

                CampaignApplication::lockForUpdate()
                    ->where('campaign_id', $campaignId)
                    ->where('user_id', $userId)
                    ->exists();

                if (CampaignParticipant::where('campaign_id', $campaignId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException(__('campaigns.content_you_are_already_a_participant_of_this_campaign'));
                }

                if (CampaignApplication::where('campaign_id', $campaignId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException(__('campaigns.content_you_have_already_applied_to_this_campaign'));
                }

                $isPublic = Campaign::find($campaignId)->visibility === Visibility::Public;

                // Check if campaign is full for bench logic
                $campaign = Campaign::find($campaignId);
                $approvedCount = CampaignParticipant::where('campaign_id', $campaignId)
                    ->where('status', 'approved')
                    ->count();
                $isFull = $campaign->max_players !== null && $approvedCount >= $campaign->max_players;

                // Determine participant status
                $participantStatus = 'pending';
                $participantRole = 'applicant';
                $benchedAt = null;

                if ($isPublic) {
                    if ($isFull) {
                        // Public campaign is full → bench the applicant
                        $participantStatus = 'benched';
                        $participantRole = 'player';
                        $benchedAt = now();
                    } else {
                        $participantStatus = 'approved';
                        $participantRole = 'player';
                    }
                }

                CampaignApplication::create([
                    'campaign_id' => $campaignId,
                    'user_id' => $userId,
                    'status' => $isPublic && ! $isFull ? 'approved' : ($isPublic && $isFull ? 'benched' : 'pending'),
                    'message' => $message ?: null,
                ]);

                CampaignParticipant::create([
                    'campaign_id' => $campaignId,
                    'user_id' => $userId,
                    'role' => $participantRole,
                    'status' => $participantStatus,
                    'benched_at' => $benchedAt,
                    'join_source' => JoinSource::Application,
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('Campaign application race caught by unique constraint', [
                'campaign_id' => $campaignId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('info', __('campaigns.content_you_have_already_applied_to_this_campaign'));
            $this->redirect(route('campaigns.detail', $this->campaign->id), navigate: true);

            return;
        } catch (\RuntimeException $e) {
            $this->addError('message', $e->getMessage());

            return;
        }

        $isPublic = $this->campaign->visibility === Visibility::Public;
        $approvedCount = CampaignParticipant::where('campaign_id', $this->campaign->id)
            ->where('status', 'approved')
            ->count();
        $isFull = $this->campaign->max_players !== null && $approvedCount >= $this->campaign->max_players;

        Log::info('Campaign application submitted', [
            'campaign_id' => $this->campaign->id,
            'user_id' => Auth::id(),
            'auto_approved' => $isPublic && ! $isFull,
            'benched' => $isPublic && $isFull,
        ]);

        // Notify campaign owner of new application (protected campaigns only)
        if (! $isPublic) {
            try {
                $owner = User::find($this->campaign->owner_id);
                if ($owner) {
                    app(NotificationService::class)->send(
                        $owner,
                        new NewApplication(Auth::user(), $this->campaign, 'campaign'),
                        NotificationCategory::NewApplication
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.new_application_dispatch_failed', [
                    'campaign_id' => $this->campaign->id,
                    'applicant_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($isPublic && $isFull) {
            session()->flash('success', __('campaigns.content_you_have_been_placed_on_the_bench'));
        } elseif ($isPublic) {
            session()->flash('success', __('campaigns.content_you_have_joined_the_campaign'));
        } else {
            session()->flash('success', __('campaigns.content_application_submitted_the_campaign_owner'));
        }

        $this->redirect(route('campaigns.detail', $this->campaign->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.campaigns.apply-to-campaign', [
            'campaign' => $this->campaign,
            'hasExistingApplication' => Auth::check() && $this->campaign->applications()
                ->where('user_id', Auth::id())
                ->exists(),
            'isParticipant' => Auth::check() && $this->campaign->participants()
                ->where('user_id', Auth::id())
                ->exists(),
        ]);
    }
}
