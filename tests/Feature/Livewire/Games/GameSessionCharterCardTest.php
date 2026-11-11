<?php

use App\Dto\CharterContent;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Charter\CharterService;
use Livewire\Livewire;

// D238 closure: the session-page half of the charter discovery surface
// (concept spec §4 — "the charter card appears on the campaign page and
// every campaign session page"). The card mirrors the campaign page
// card's gate exactly: the CAMPAIGN owner or an APPROVED campaign
// participant, and only a PUBLISHED charter. Drafts stay owner-only (no
// existence oracle), waitlisted / pending / non-participant viewers and
// one-shot games render nothing. Asserts strings through __() lookups
// (K004 locale decoupling).

beforeEach(function () {
    app()->setLocale('en');
});

// ── Helpers ──────────────────────────────────────────────

/**
 * A campaign with its owner, a PUBLISHED charter, and one scheduled
 * public session game owned by the campaign owner.
 *
 * @return array{0: User, 1: Campaign, 2: SessionZeroSurvey, 3: Game}
 */
function charterSession(): array
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

    $charter = CharterService::start($owner, $campaign, 'quickstart');

    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    CharterService::publish($owner, $charter, 'The charter', $content);

    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => $campaign->id,
    ]);

    return [$owner, $campaign, $charter, $game];
}

/**
 * An approved (or otherwise-statused) campaign participant user.
 */
function campaignMember(Campaign $campaign, string $status): User
{
    $user = User::factory()->create(['profile_complete' => true]);

    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
        'status' => $status,
    ]);

    return $user;
}

// ── Discovery: the card renders ──────────────────────────

describe('charter discovery card', function () {
    it('shows the published charter to an approved campaign participant with a view link', function () {
        [, $campaign, $charter, $game] = charterSession();

        $participant = campaignMember($campaign, 'approved');

        Livewire::actingAs($participant)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('charter.title_entry'))
            ->assertSee(__('charter.description_entry_teaser_participant'))
            ->assertSee(__('charter.action_view_charter'))
            ->assertSee(route('charter.view', ['locale' => app()->getLocale(), 'uuid' => $charter->refresh()->uuid]))
            // Composer surfaces are owner-only — never offered to participants.
            ->assertDontSee(__('charter.action_create_charter'))
            ->assertDontSee(__('charter.action_continue_charter'));
    });

    it('shows the published charter to the campaign owner', function () {
        [$owner, , $charter, $game] = charterSession();

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('charter.title_entry'))
            ->assertSee(__('charter.action_view_charter'))
            ->assertSee(route('charter.view', ['locale' => app()->getLocale(), 'uuid' => $charter->refresh()->uuid]));
    });
});

// ── No existence oracle: the card stays hidden ───────────

describe('hidden audiences', function () {
    it('renders nothing for a guest on the public session page', function () {
        [, , $charter, $game] = charterSession();

        $this->get(route('games.detail', ['id' => $game->id]))
            ->assertOk()
            ->assertDontSee(__('charter.title_entry'))
            ->assertDontSee(__('charter.description_entry_teaser_participant'))
            ->assertDontSee($charter->uuid);
    });

    it('renders nothing for an authenticated non-participant', function () {
        [, , $charter, $game] = charterSession();

        $outsider = User::factory()->create(['profile_complete' => true]);

        Livewire::actingAs($outsider)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('charter.title_entry'))
            ->assertDontSee(__('charter.action_view_charter'))
            ->assertDontSee($charter->uuid);
    });

    it('renders nothing for a waitlisted campaign viewer', function () {
        [, $campaign, $charter, $game] = charterSession();

        $waitlisted = campaignMember($campaign, 'waitlisted');

        Livewire::actingAs($waitlisted)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('charter.title_entry'))
            ->assertDontSee($charter->uuid);
    });

    it('renders nothing for an approved game participant who is not a campaign participant', function () {
        // The gate is campaign-level (the sign panel's member gate): a
        // session-roster player without an approved campaign row cannot
        // sign, so the card must not point them at a dead end.
        [, , $charter, $game] = charterSession();

        $gameOnly = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $gameOnly->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($gameOnly)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('charter.title_entry'))
            ->assertDontSee($charter->uuid);
    });
});

describe('hidden charter states', function () {
    it('renders nothing for a draft charter — no existence leak to participants', function () {
        $draftOwner = User::factory()->create(['profile_complete' => true]);
        $draftCampaign = Campaign::factory()->create([
            'owner_id' => $draftOwner->id,
            'name' => ['en' => 'Draft Charter Campaign'],
            'max_players' => 4,
            'session_duration' => 3,
            'recurrence' => 'weekly',
        ]);
        $draft = CharterService::start($draftOwner, $draftCampaign, 'quickstart');
        $draftGame = Game::factory()->create([
            'owner_id' => $draftOwner->id,
            'campaign_id' => $draftCampaign->id,
        ]);

        $participant = campaignMember($draftCampaign, 'approved');

        Livewire::actingAs($participant)
            ->test(GameDetail::class, ['id' => $draftGame->id])
            ->assertDontSee(__('charter.title_entry'))
            ->assertDontSee(__('charter.action_view_charter'))
            ->assertDontSee($draft->uuid);
    });

    it('offers the draft continue affordance to the campaign owner only', function () {
        $draftOwner = User::factory()->create(['profile_complete' => true]);
        $draftCampaign = Campaign::factory()->create([
            'owner_id' => $draftOwner->id,
            'name' => ['en' => 'Draft Charter Campaign'],
            'max_players' => 4,
            'session_duration' => 3,
            'recurrence' => 'weekly',
        ]);
        CharterService::start($draftOwner, $draftCampaign, 'quickstart');
        $draftGame = Game::factory()->create([
            'owner_id' => $draftOwner->id,
            'campaign_id' => $draftCampaign->id,
        ]);

        Livewire::actingAs($draftOwner)
            ->test(GameDetail::class, ['id' => $draftGame->id])
            ->assertSee(__('charter.action_continue_charter'))
            ->assertDontSee(__('charter.action_view_charter'));

        $participant = campaignMember($draftCampaign, 'approved');

        Livewire::actingAs($participant)
            ->test(GameDetail::class, ['id' => $draftGame->id])
            ->assertDontSee(__('charter.action_continue_charter'))
            ->assertDontSee(__('charter.title_entry'));
    });

    it('renders nothing for a charter-less campaign session participant', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'Charter-less Campaign'],
            'max_players' => 4,
            'session_duration' => 3,
            'recurrence' => 'weekly',
        ]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => $campaign->id,
        ]);

        $participant = campaignMember($campaign, 'approved');

        Livewire::actingAs($participant)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('charter.title_entry'))
            ->assertDontSee(__('charter.action_view_charter'));

        // The owner keeps the create affordance — the composer entry.
        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('charter.action_create_charter'));
    });
});

describe('non-campaign games', function () {
    it('renders nothing on a one-shot game page', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('charter.title_entry'))
            ->assertDontSee(__('charter.action_create_charter'))
            ->assertDontSee(__('charter.action_view_charter'));
    });
});
