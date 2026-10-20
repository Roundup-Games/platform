<?php

use App\Enums\GameStatus;
use App\Enums\GoalStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Livewire\Campaigns\CampaignRecruitmentComposer;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\User;
use App\Services\CampaignRecruitmentSummaryService;
use Livewire\Livewire;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

//
// M071/S05-T03 — the GM recruitment composer: entry on the Journal tab,
// default-safe auto-draft, goal/excerpt pickers appending to the editable
// draft, the checklist-gated apply/remove write path, the fail-closed
// manageRecruitmentSummary policy matrix, and the escaped visitor-preview
// replica. Copy assertions are locale-decoupled via __('…', [], 'en')
// lookups (K004); the service re-validated invariants are pinned in
// RecruitmentSummaryServiceTest (T02) — this suite pins the UI contract.
//

beforeEach(function () {
    app()->setLocale('en');
});

/**
 * An owner + campaign pair (canonical fixture; the factory default is a
 * public campaign with seats open — createCampaignWithOwner seats the
 * owner as the only approved participant).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: Campaign}
 */
function composerCampaign(array $attributes = []): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner($attributes);

    return [$owner, $campaign];
}

/**
 * One completed session on the campaign (session zero counts the same).
 */
function composerCompletedSession(Campaign $campaign, User $owner): Game
{
    return Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => GameStatus::Completed->value,
    ]);
}

/**
 * The spec §4.1 spoiler-safety checklist, all confirmed.
 *
 * @return array<string, bool>
 */
function composerChecklist(): array
{
    return [
        'review_excerpts' => true,
        'names_removed' => true,
        'visitor_preview' => true,
    ];
}

/**
 * A seated approved player participant (the member gate passes, the
 * owner gate must not).
 */
function composerApprovedPlayer(Campaign $campaign): User
{
    $player = User::factory()->create(['profile_complete' => true]);

    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $player->id,
        'role' => 'player',
        'status' => 'approved',
    ]);

    return $player;
}

// ── Owner path: entry button + default-safe auto-draft ─────────────

test('the GM sees the recruitment entry on the journal tab and openComposer seeds the default-safe draft', function () {
    [$owner, $campaign] = composerCampaign();

    for ($i = 0; $i < 3; $i++) {
        composerCompletedSession($campaign, $owner);
    }

    // A players-visibility recap whose SPOILER marker sits beyond the
    // picker's 120-char preview window, and an active goal — neither may
    // enter the auto-draft (spec §4.1 default-safe).
    $session = composerCompletedSession($campaign, $owner);

    CampaignJournalEntry::factory()->forCampaignSession($session)->create([
        'kind' => JournalEntryKind::Recap,
        'visibility' => JournalEntryVisibility::Players,
        'body' => str_repeat('harmless lead-up prose. ', 10).'COMPOSERSPOILER the heir betrayed the duchess',
    ]);

    $campaign->goals()->create([
        'title' => 'Rescue the duchess heir',
        'status' => GoalStatus::Active,
        'sort' => 1,
    ]);

    $this->actingAs($owner)
        ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertSee(__('journal.action_recruit_with_campaign', [], 'en'))
        ->assertSee(__('journal.heading_recruitment_composer', [], 'en'));

    Livewire::actingAs($owner)
        ->test(CampaignRecruitmentComposer::class, ['campaign' => $campaign])
        ->call('openComposer')
        ->assertSet('open', true)
        ->assertSet('draft', fn (string $draft): bool => str_contains(
            $draft,
            __('journal.content_recruitment_sessions_played', ['count' => 4], 'en'),
        ) && ! str_contains($draft, 'COMPOSERSPOILER')
            && ! str_contains($draft, 'Rescue the duchess heir'));
});

// ── Goal toggles and the excerpt picker ────────────────────────────

test('toggling a goal appends and removes the exact line', function () {
    [$owner, $campaign] = composerCampaign();

    $goal = $campaign->goals()->create([
        'title' => 'Break the Emberfall siege',
        'status' => GoalStatus::Active,
        'sort' => 1,
    ]);

    $component = Livewire::actingAs($owner)
        ->test(CampaignRecruitmentComposer::class, ['campaign' => $campaign])
        ->call('openComposer');

    $component
        ->set('goalChecked.'.$goal->id, true)
        ->call('toggleGoal', $goal->id)
        ->assertSet('draft', fn (string $draft): bool => str_contains($draft, '- Break the Emberfall siege'));

    $component
        ->set('goalChecked.'.$goal->id, false)
        ->call('toggleGoal', $goal->id)
        ->assertSet('draft', fn (string $draft): bool => ! str_contains($draft, '- Break the Emberfall siege'));
});

test('appendExcerpt adds the labeled body and prep-note or gms-visibility entries are never offered', function () {
    [$owner, $campaign] = composerCampaign();
    $session = composerCompletedSession($campaign, $owner);

    $entry = CampaignJournalEntry::factory()->forCampaignSession($session)->create([
        'kind' => JournalEntryKind::Recap,
        'visibility' => JournalEntryVisibility::Players,
        'body' => 'COMPOSEREXCERPT the vault heist concluded',
    ]);

    // Never offerable: a prep note (same session) and a gms-visibility
    // recap (its own session — the schema's one-recap-per-session
    // constraint means two recaps cannot share a game row).
    CampaignJournalEntry::factory()->forCampaignSession($session)->create([
        'kind' => JournalEntryKind::PrepNote,
        'visibility' => JournalEntryVisibility::Gms,
        'body' => 'PREPMARKER secret weakness of the boss',
    ]);

    $gmsOnlySession = composerCompletedSession($campaign, $owner);

    CampaignJournalEntry::factory()->forCampaignSession($gmsOnlySession)->create([
        'kind' => JournalEntryKind::Recap,
        'visibility' => JournalEntryVisibility::Gms,
        'body' => 'GMSMARKER gms-only recap twist',
    ]);

    $component = Livewire::actingAs($owner)
        ->test(CampaignRecruitmentComposer::class, ['campaign' => $campaign])
        ->call('openComposer')
        ->assertDontSee('PREPMARKER')
        ->assertDontSee('GMSMARKER');

    $label = __('journal.label_recruitment_excerpt', [
        'session' => format_date($entry->game?->date_time),
    ], 'en');

    $component->call('appendExcerpt', $entry->id)
        ->assertSet('draft', fn (string $draft): bool => str_contains($draft, 'COMPOSEREXCERPT the vault heist concluded')
            && str_contains($draft, $label));

    // A foreign id is a no-op: the scoped query never finds it.
    $component->call('appendExcerpt', '00000000-0000-0000-0000-000000000000')
        ->assertSet('draft', fn (string $draft): bool => substr_count($draft, 'COMPOSEREXCERPT') === 1);
});

// ── The checklist gate and the apply/remove write path ─────────────

test('apply with an unchecked spoiler-safety box flashes the error and writes nothing', function () {
    [$owner, $campaign] = composerCampaign();

    Livewire::actingAs($owner)
        ->test(CampaignRecruitmentComposer::class, ['campaign' => $campaign])
        ->set('draft', 'A safe summary.')
        ->set('checklist.review_excerpts', true)
        ->set('checklist.names_removed', true)
        // visitor_preview left unchecked
        ->call('apply')
        ->assertSee(__('journal.error_recruitment_checklist_incomplete', [], 'en'));

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaign->id,
        'recruitment_summary' => null,
        'recruitment_summary_completed_sessions' => null,
        'recruitment_summary_refreshed_at' => null,
    ]);
});

test('apply with the complete checklist writes all three columns, flashes success, and a re-apply refreshes the stamp', function () {
    [$owner, $campaign] = composerCampaign();

    for ($i = 0; $i < 2; $i++) {
        composerCompletedSession($campaign, $owner);
    }

    $component = Livewire::actingAs($owner)
        ->test(CampaignRecruitmentComposer::class, ['campaign' => $campaign])
        ->set('draft', 'A safe summary.')
        ->set('checklist', composerChecklist())
        ->call('apply')
        ->assertSee(__('journal.flash_recruitment_applied', [], 'en'));

    $first = $campaign->fresh();

    expect($first->recruitment_summary)->toBe('A safe summary.')
        ->and($first->recruitment_summary_completed_sessions)->toBe(2)
        ->and($first->recruitment_summary_refreshed_at)->not->toBeNull();

    $this->travel(5)->minutes();

    $component->call('apply');

    $second = $campaign->fresh();

    expect($second->recruitment_summary_refreshed_at->timestamp)
        ->toBeGreaterThan($first->recruitment_summary_refreshed_at->timestamp);
});

test('remove nulls the columns and flashes success', function () {
    [$owner, $campaign] = composerCampaign();

    app(CampaignRecruitmentSummaryService::class)
        ->apply($campaign, $owner, 'Live block.', composerChecklist());

    Livewire::actingAs($owner)
        ->test(CampaignRecruitmentComposer::class, ['campaign' => $campaign->fresh()])
        ->call('openComposer')
        ->assertSee(__('campaigns.content_last_refreshed_after_session', ['count' => 0], 'en'))
        ->call('remove')
        ->assertSee(__('journal.flash_recruitment_removed', [], 'en'));

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaign->id,
        'recruitment_summary' => null,
        'recruitment_summary_completed_sessions' => null,
        'recruitment_summary_refreshed_at' => null,
    ]);
});

// ── Policy matrix: seated players and guests fail closed ───────────

test('a seated approved player sees no entry button and direct component calls fail closed with zero writes', function () {
    [$owner, $campaign] = composerCampaign();
    $player = composerApprovedPlayer($campaign);

    // The member gate (viewJournal) passes — the player reads the
    // journal tab — but neither the GM entry block nor the composer
    // markup may render.
    $this->actingAs($player)
        ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertDontSee(__('journal.action_recruit_with_campaign', [], 'en'))
        ->assertDontSee(__('journal.heading_recruitment_composer', [], 'en'))
        ->assertDontSee(__('journal.section_recruitment_checklist', [], 'en'));

    Livewire::actingAs($player)
        ->test(CampaignRecruitmentComposer::class, ['campaign' => $campaign])
        ->set('draft', 'Hostile takeover.')
        ->set('checklist', composerChecklist())
        ->call('apply')
        ->assertSee(__('journal.error_recruitment_unauthorized', [], 'en'))
        ->assertDontSee(__('journal.heading_recruitment_composer', [], 'en'));

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaign->id,
        'recruitment_summary' => null,
        'recruitment_summary_completed_sessions' => null,
        'recruitment_summary_refreshed_at' => null,
    ]);
});

test('a guest mount renders an empty shell with no composer markup', function () {
    [, $campaign] = composerCampaign();

    Livewire::test(CampaignRecruitmentComposer::class, ['campaign' => $campaign])
        ->assertSet('open', false)
        ->assertSet('draft', '')
        ->assertDontSee(__('journal.heading_recruitment_composer', [], 'en'))
        ->assertDontSee(__('journal.section_recruitment_checklist', [], 'en'))
        ->assertDontSee(__('journal.label_recruitment_draft', [], 'en'));
});

// ── Preview honesty: the replica escapes exactly like the public page ──

test('the visitor preview renders the escaped replica and never a raw tag', function () {
    [$owner, $campaign] = composerCampaign();
    composerCompletedSession($campaign, $owner);

    Livewire::actingAs($owner)
        ->test(CampaignRecruitmentComposer::class, ['campaign' => $campaign])
        ->call('openComposer')
        ->set('draft', 'Pitch <b>bold</b> and a new line')
        ->set('previewing', true)
        ->assertSee(__('journal.action_preview_as_visitor', [], 'en'))
        ->assertSee(__('campaigns.heading_campaign_so_far', [], 'en'))
        ->assertSee(__('campaigns.content_last_refreshed_after_session', ['count' => 1], 'en'))
        // e('<b>bold</b>') renders as entities; assertSee escapes the
        // needle, so this pins the entity form; the raw tag is absent.
        ->assertSee('<b>bold</b>')
        ->assertDontSee('<b>', escape: false);
});

// ── Staleness nudge on the Journal tab ─────────────────────────────

test('the staleness nudge appears for the GM once three sessions accrue since the stamp', function () {
    [$owner, $campaign] = composerCampaign();

    for ($i = 0; $i < 6; $i++) {
        composerCompletedSession($campaign, $owner);
    }

    app(CampaignRecruitmentSummaryService::class)
        ->apply($campaign->fresh(), $owner, 'Stamped at six.', composerChecklist());

    // Two more completed sessions: below the threshold — the nudge copy
    // (with count 2) must not render.
    for ($i = 0; $i < 2; $i++) {
        composerCompletedSession($campaign, $owner);
    }

    $this->actingAs($owner)
        ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertDontSee(__('journal.hint_recruitment_stale', ['count' => 2], 'en'));

    composerCompletedSession($campaign, $owner);

    $this->actingAs($owner)
        ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertSee(__('journal.hint_recruitment_stale', ['count' => 3], 'en'));
});

// ── MEM387 recruiting emphasis: public visibility + open seats ─────

test('the recruiting emphasis is bound to public visibility and open seats', function () {
    // Factory default: public, max_players 4-8, only the owner seated.
    ['owner' => $publicOwner, 'campaign' => $publicCampaign] = test()->createCampaignWithOwner();

    $this->actingAs($publicOwner)
        ->get(route('campaigns.show', ['id' => $publicCampaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertSee(__('journal.hint_recruitment_open_seats', [], 'en'));

    // At capacity: max_players 1 and the owner's own row fills it.
    ['owner' => $fullOwner, 'campaign' => $fullCampaign] = test()->createCampaignWithOwner(['max_players' => 1]);

    $this->actingAs($fullOwner)
        ->get(route('campaigns.show', ['id' => $fullCampaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertDontSee(__('journal.hint_recruitment_open_seats', [], 'en'));

    // Non-public: the emphasis never shows.
    ['owner' => $privateOwner, 'campaign' => $privateCampaign] = test()->createCampaignWithOwner(['visibility' => 'private']);

    $this->actingAs($privateOwner)
        ->get(route('campaigns.show', ['id' => $privateCampaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertDontSee(__('journal.hint_recruitment_open_seats', [], 'en'));
});
