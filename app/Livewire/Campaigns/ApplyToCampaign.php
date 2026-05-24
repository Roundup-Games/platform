<?php

namespace App\Livewire\Campaigns;

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Traits\HandlesApplicationSubmission;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ApplyToCampaign extends Component
{
    use HandlesApplicationSubmission;

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

    protected function getEntity(): \Illuminate\Database\Eloquent\Model
    {
        return $this->campaign;
    }

    protected function getApplicationConfig(): array
    {
        return [
            'foreign_key' => 'campaign_id',
            'application_class' => CampaignApplication::class,
            'participant_class' => CampaignParticipant::class,
            'entity_class' => Campaign::class,
            'show_route' => 'campaigns.show',
            'entity_type' => 'campaign',
            'log_key' => 'campaign_id',
            'application_status_public' => 'approved',
            'translations' => [
                'own_entity_error' => 'campaigns.error_you_cannot_apply_to_your_own_campaign',
                'race_applied' => 'campaigns.content_you_have_already_applied_to_this_campaign',
                'already_participant' => 'campaigns.content_you_are_already_a_participant_of_this_campaign',
                'already_applied' => 'campaigns.content_you_have_already_applied_to_this_campaign',
                'bench_success' => 'campaigns.content_you_have_been_placed_on_the_bench',
                'waitlist_success' => 'campaigns.content_you_have_been_placed_on_the_waitlist',
                'join_success' => 'campaigns.content_you_have_joined_the_campaign',
                'application_submitted' => 'campaigns.content_application_submitted_the_campaign_owner',
            ],
        ];
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
