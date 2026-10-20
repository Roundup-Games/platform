<?php

use App\Enums\CharacterStateChangeType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\CharacterStateChange;
use App\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// M071/S02-T01 — character_state_changes: per-character state timeline
// data foundation with enum casts, campaign/participant/session
// relations, the CHECK constraint backstop and the bilingual type
// labels.

// ── Factory & casts ─────────────────────────────────────

test('factory creates and persists a state change with enum casts', function () {
    $change = CharacterStateChange::factory()->create();

    $fresh = CharacterStateChange::find($change->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->toBeString()
        ->and($fresh->delta)->toBeString()
        // Factory default is an interlude note: no session binding
        // (spec §2).
        ->and($fresh->game_id)->toBeNull()
        // Enum cast hydrates the enum instance, not a raw string.
        ->and($fresh->change_type)->toBeInstanceOf(CharacterStateChangeType::class)
        ->and($fresh->change_type)->toBe(CharacterStateChangeType::Note);
});

// ── Relations & factory state ───────────────────────────

test('relations resolve for a session-bound state change', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);
    $participant = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
    ]);

    $change = CharacterStateChange::factory()->forParticipant($participant)->create([
        'game_id' => $session->id,
        'change_type' => CharacterStateChangeType::Progression,
    ]);

    expect($change->campaign->is($campaign))->toBeTrue()
        ->and($change->participant->is($participant))->toBeTrue()
        ->and($change->game->is($session))->toBeTrue();
});

test('forParticipant state derives the campaign from the participant row', function () {
    $participant = CampaignParticipant::factory()->create();

    $change = CharacterStateChange::factory()->forParticipant($participant)->create();

    expect($change->participant_id)->toBe($participant->id)
        ->and($change->campaign_id)->toBe($participant->campaign_id);
});

test('campaign and participant stateChanges relations scope to their owner', function () {
    $campaign = Campaign::factory()->create();
    $other = Campaign::factory()->create();
    $participant = CampaignParticipant::factory()->create(['campaign_id' => $campaign->id]);
    $otherParticipant = CampaignParticipant::factory()->create(['campaign_id' => $other->id]);

    $kept = CharacterStateChange::factory()->count(2)->forParticipant($participant)->create();
    CharacterStateChange::factory()->forParticipant($otherParticipant)->create();

    expect($campaign->stateChanges->pluck('id')->all())
        ->toEqualCanonicalizing($kept->pluck('id')->all())
        ->and($participant->stateChanges->pluck('id')->all())
        ->toEqualCanonicalizing($kept->pluck('id')->all());
});

// ── CHECK constraint ────────────────────────────────────

test('the change_type check constraint rejects unknown values', function () {
    $campaign = Campaign::factory()->create();

    // Raw insert: the Eloquent enum cast would reject the unknown value
    // before it reaches the database, so the CHECK backstop is proven
    // below the model layer.
    $violation = null;
    try {
        // The savepoint (nested DB::transaction) keeps the aborted
        // statement from poisoning the outer test transaction (pattern:
        // CampaignJournalEntryModelTest).
        DB::transaction(fn () => DB::table('character_state_changes')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'change_type' => 'curse',
            'delta' => 'invalid change type',
        ]));
        $this->fail('Expected a check violation from character_state_changes_change_type_check.');
    } catch (QueryException $e) {
        $violation = $e;
    }

    expect($violation->errorInfo[0] ?? null)->toBe('23514');
    expect($violation->getMessage())->toContain('character_state_changes_change_type_check');
});

// ── Delete semantics ────────────────────────────────────

test('deleting a session cascades its state changes but spares interludes', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);
    $participant = CampaignParticipant::factory()->create(['campaign_id' => $campaign->id]);

    $sessionChange = CharacterStateChange::factory()->forParticipant($participant)->create([
        'game_id' => $session->id,
    ]);
    $interlude = CharacterStateChange::factory()->forParticipant($participant)->create();

    $session->delete();
    expect(CharacterStateChange::find($sessionChange->id))->toBeNull()
        // Interlude state notes survive the session delete — they belong
        // to the campaign.
        ->and(CharacterStateChange::find($interlude->id))->not->toBeNull();

    $campaign->delete();
    expect(CharacterStateChange::find($interlude->id))->toBeNull();
});

test('removing a participant nulls the link but never deletes state changes', function () {
    $campaign = Campaign::factory()->create();
    $participant = CampaignParticipant::factory()->create(['campaign_id' => $campaign->id]);

    $change = CharacterStateChange::factory()->forParticipant($participant)->create();

    $participant->delete();

    $fresh = CharacterStateChange::find($change->id);
    expect($fresh)->not->toBeNull()
        // The campaign's state history survives participant removal
        // (nullOnDelete, never cascade — journal author_participant_id
        // precedent).
        ->and($fresh->participant_id)->toBeNull()
        ->and($fresh->campaign->is($campaign))->toBeTrue();
});

// ── Bilingual labels ────────────────────────────────────

test('enum labels are non-empty in both locales', function () {
    foreach (['en', 'de'] as $locale) {
        App::setLocale($locale);

        foreach (CharacterStateChangeType::cases() as $type) {
            expect($type->label())->toBeString()->not->toBe('');
        }
    }

    App::setLocale('en');
});
