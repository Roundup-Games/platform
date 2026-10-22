<?php

namespace Tests\Feature\Livewire\Campaigns;

use App\Dto\CharterContent;
use App\Livewire\Campaigns\CampaignDetail;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Charter\CharterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Charter entry-point card on the campaign page (spec §3/§4 discovery):
 * the owner keeps the full composer entry; an APPROVED participant gets a
 * discovery card with a View link ONLY for a published charter — drafts
 * stay owner-only (the capability-link 404 the charter view itself
 * applies), and pending/waitlisted viewers see nothing until approved.
 *
 * PHPUnit-style class: per MEM755, Pest's blanket DatabaseTransactions
 * does NOT apply to PHPUnit classes, so the trait is declared explicitly.
 */
class CampaignCharterEntryCardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    #[Test]
    public function an_approved_participant_discovers_a_published_charter_with_a_view_link(): void
    {
        [$owner, $campaign, $charter] = $this->publishedCharter();

        $participant = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'status' => 'approved',
        ]);

        Livewire::actingAs($participant)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee(__('charter.title_entry'))
            ->assertSee(__('charter.description_entry_teaser_participant'))
            ->assertSee(route('charter.view', $charter->refresh()->uuid))
            // Participant copy never offers the composer surfaces.
            ->assertDontSee(__('charter.action_create_charter'));
    }

    #[Test]
    public function a_draft_charter_is_invisible_to_participants_no_existence_leak(): void
    {
        [$owner, $campaign] = $this->campaignWithOwner();
        $charter = CharterService::start($owner, $campaign, 'quickstart');

        $participant = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'status' => 'approved',
        ]);

        Livewire::actingAs($participant)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee(__('charter.title_entry'))
            ->assertDontSee($charter->uuid);
    }

    #[Test]
    public function pending_participants_and_guests_get_no_charter_card_even_when_published(): void
    {
        [$owner, $campaign, $charter] = $this->publishedCharter();

        $pending = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $pending->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($pending)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee(__('charter.title_entry'));

        $this->get(route('campaigns.show', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertDontSee(__('charter.title_entry'));
    }

    /**
     * @return array{0: User, 1: Campaign, 2: SessionZeroSurvey}
     */
    private function publishedCharter(): array
    {
        [$owner, $campaign] = $this->campaignWithOwner();

        $charter = CharterService::start($owner, $campaign, 'quickstart');

        $content = CharterContent::quickStart($campaign)->toArray();
        $content['overview']['expected_shape'] = 'ongoing';

        CharterService::publish($owner, $charter, 'The charter', $content);

        return [$owner, $campaign, $charter];
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function campaignWithOwner(): array
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'The Threshold Heist'],
            'max_players' => 5,
            'session_duration' => 3,
            'recurrence' => 'weekly',
        ]);

        return [$owner, $campaign];
    }
}
