<?php

use App\Enums\ActivityType;
use App\Enums\CampaignStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Campaigns\EditCampaign;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Support\Edit\FieldTiers;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

//
// Guards the owner edit mode on the campaign detail page (M084/S03-T03, D317 + D314).
//
// The lock is always the production predicate: an Approved non-owner participant
// row (HasAgreementLock). Nothing here stubs isAgreementLocked().
//

beforeEach(function () {
    Notification::fake();
});

// ── Helpers ──────────────────────────────────────────────

function editCampaignModeOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge(['timezone' => 'Europe/Berlin', 'profile_complete' => true], $overrides));
}

/**
 * A weekly TTRPG campaign with every inventory field set to a known value.
 *
 * @param  array<string, mixed>  $overrides
 */
function editCampaignModeCampaign(User $owner, array $overrides = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Shadows of Waterdeep', 'de' => 'Schatten von Tiefwasser'],
        'description' => ['en' => 'A weekly intrigue campaign.', 'de' => 'Ein wöchentliches Intrigen-Abenteuer.'],
        'game_type' => 'ttrpg',
        'status' => CampaignStatus::Active->value,
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'price_per_session' => 12.5,
        'min_players' => 2,
        'max_players' => 5,
        'bench_mode' => false,
        'language' => 'en',
        'visibility' => Visibility::Protected->value,
        'experience_level' => 'beginner',
        'complexity' => 2.25,
        'vibe_flags' => ['cooperative'],
        'host_note' => 'Bring dice',
        'location_instructions' => 'Ring the bell',
        'safety_rules' => ['tools' => ['x-card'], 'lines_and_veils_text' => 'No spiders', 'custom_note' => ''],
    ], $overrides));
}

/** ONE confirmed non-owner: the campaign is agreement-locked from here on. */
function editCampaignModeLock(Campaign $campaign): CampaignParticipant
{
    return CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create()->id,
        'status' => ParticipantStatus::Approved->value,
    ]);
}

/** Whether the rendered HTML shows the input or select with the given id as disabled. */
function editCampaignModeFieldDisabled(string $html, string $id): bool
{
    return (bool) preg_match('/<(?:input|select)[^>]*\bid="'.preg_quote($id, '/').'"[^>]*\bdisabled/', $html);
}

function editCampaignModeMounted(Campaign $campaign, ?User $as = null)
{
    return Livewire::actingAs($as ?? $campaign->owner)
        ->test(EditCampaign::class, ['campaign' => $campaign]);
}

/**
 * The changed_fields of every CampaignUpdated activity entry for the campaign, oldest first.
 *
 * @return list<list<string>>
 */
function editCampaignModeChangedFields(Campaign $campaign): array
{
    return ActivityLog::query()
        ->where('subject_type', $campaign->getMorphClass())
        ->where('subject_id', $campaign->getKey())
        ->where('event_type', ActivityType::CampaignUpdated->value)
        ->orderBy('created_at')
        ->get()
        ->map(function (ActivityLog $log): array {
            $properties = is_string($log->properties) ? json_decode($log->properties, true) : $log->properties;

            return $properties['changed_fields'] ?? [];
        })
        ->all();
}

// ═══════════════════════════════════════════════════════════
// (1) MOUNT — PRE-FILL
// ═══════════════════════════════════════════════════════════

describe('EditCampaign — mount', function () {
    it('pre-fills every editable property from the inventory', function () {
        $owner = editCampaignModeOwner();
        $venue = createVerifiedVenue();
        $campaign = editCampaignModeCampaign($owner, ['location_id' => $venue->id]);
        $systemId = $campaign->fresh()->gameSystems->first()->id;

        editCampaignModeMounted($campaign)
            ->assertOk()
            ->assertSet('game_type', 'ttrpg')
            ->assertSet('step', 'form')
            ->assertSet('name', 'Shadows of Waterdeep')
            ->assertSet('description', 'A weekly intrigue campaign.')
            ->assertSet('pendingTranslations.de.name', 'Schatten von Tiefwasser')
            ->assertSet('pendingTranslations.de.description', 'Ein wöchentliches Intrigen-Abenteuer.')
            ->assertSet('recurrence', 'weekly')
            ->assertSet('time_of_day', '19:00')
            ->assertSet('session_duration', '3')
            ->assertSet('price_per_session', '12.5')
            ->assertSet('min_players', 2)
            ->assertSet('max_players', 5)
            ->assertSet('bench_mode', false)
            ->assertSet('language', 'en')
            ->assertSet('visibility', Visibility::Protected->value)
            ->assertSet('location_id', $venue->id)
            ->assertSet('location_instructions', 'Ring the bell')
            ->assertSet('safety_rules', ['tools' => ['x-card'], 'lines_and_veils_text' => 'No spiders', 'custom_note' => ''])
            ->assertSet('experience_level', 'beginner')
            ->assertSet('complexity', '2.25')
            ->assertSet('host_note', 'Bring dice')
            ->assertSet('minimum_requirements', [])
            ->assertSet('vibePreferences', ['cooperative' => 'favorite'])
            ->assertSet('game_system_id', $systemId)
            ->assertSet('game_systems', [])
            ->assertSet('originalSystemIds', [$systemId])
            ->assertSet('cover_image', null)
            ->assertSet('isLocked', false)
            ->assertSet('lockedFields', []);
    });

    it('gives a user who cannot update the campaign a 403 on mount', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        $stranger = editCampaignModeOwner();

        editCampaignModeMounted($campaign, $stranger)
            ->assertForbidden();
    });
});

// ═══════════════════════════════════════════════════════════
// (2) UNLOCKED SAVE
// ═══════════════════════════════════════════════════════════

describe('EditCampaign — unlocked campaign', function () {
    it('saves a changed recurrence and time of day through AgreementEditService, and nothing else', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);

        editCampaignModeMounted($campaign)
            ->set('recurrence', 'bi-weekly')
            ->set('time_of_day', '20:30')
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->recurrence)->toBe('bi-weekly')
            ->and($campaign->fresh()->time_of_day)->toBe('20:30');

        // An untouched form reports ONLY the two edited fields: every other
        // pre-filled value was a no-op for the service.
        expect(editCampaignModeChangedFields($campaign))->toBe([['recurrence', 'time_of_day']]);
    });
});

// ═══════════════════════════════════════════════════════════
// (3) + (4) LOCKED CAMPAIGN — READ-ONLY UI AND THE FORGED SAVE
// ═══════════════════════════════════════════════════════════

describe('EditCampaign — locked campaign', function () {
    it('renders the lock reason with the lock date and disables the Tier-1 inputs', function () {
        app()->setLocale('en');
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        $member = editCampaignModeLock($campaign);
        $lockDate = $member->fresh()->approved_at;

        $component = editCampaignModeMounted($campaign)
            ->assertSet('isLocked', true)
            ->assertSee(__('campaigns.content_agreement_locked', ['date' => format_date($lockDate, 'datetime')]));

        $html = $component->html();

        foreach (['campaign-recurrence', 'campaign-time', 'campaign-duration', 'campaign-price', 'campaign-min-players', 'campaign-language'] as $id) {
            expect(editCampaignModeFieldDisabled($html, $id))->toBeTrue("field [{$id}] should be disabled");
        }

        // The offered system (Tier 1) is read-only: its picker's search input is disabled.
        expect(editCampaignModeFieldDisabled($html, 'campaign-system-search'))->toBeTrue();

        // Still SHOWS the fields — a vanished field reads as a bug.
        expect($html)->toContain('id="campaign-time"')
            ->toContain('id="campaign-recurrence"')
            ->toContain('id="campaign-price"');

        // max_players is direction-dependent: an increase stays available.
        expect(editCampaignModeFieldDisabled($html, 'campaign-max-players'))->toBeFalse();

        // The lock map is exactly FieldTiers' Tier-1 set for the campaign.
        expect(array_keys($component->instance()->lockedFields()))->toBe([
            'game_systems', 'game_system_id', 'game_type', 'recurrence', 'time_of_day',
            'session_duration', 'price_per_session', 'min_players', 'bench_mode', 'language',
        ]);
    });

    it('classifies fields through FieldTiers and never a direction-dependent field as locked', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);

        /** @var EditCampaign $component */
        $component = editCampaignModeMounted($campaign)->instance();

        expect($component->tierOf('recurrence'))->toBe(FieldTiers::TIER_LOCKED)
            ->and($component->tierOf('time_of_day'))->toBe(FieldTiers::TIER_LOCKED)
            ->and($component->tierOf('name'))->toBe(FieldTiers::TIER_FREE)
            ->and($component->tierOf('location_id'))->toBe(FieldTiers::TIER_NOTIFIED)
            ->and($component->tierOf('max_players'))->toBe(FieldTiers::TIER_FREE)
            ->and($component->tierOf('visibility'))->toBe(FieldTiers::TIER_FREE);
    });

    it('refuses a forged save with a changed recurrence and changes nothing', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        editCampaignModeLock($campaign);
        $before = $campaign->fresh()->only(['name', 'recurrence', 'time_of_day', 'price_per_session', 'description']);

        editCampaignModeMounted($campaign)
            ->set('recurrence', 'monthly')
            ->set('time_of_day', '21:00')
            ->set('name', 'Not applied either')
            ->call('save')
            ->assertHasErrors(['edit']);

        expect($campaign->fresh()->only(['name', 'recurrence', 'time_of_day', 'price_per_session', 'description']))->toEqual($before)
            ->and(editCampaignModeChangedFields($campaign))->toBe([]);
    });

    it('refuses a forged game-system change with the lock exception and leaves the pivot alone', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        editCampaignModeLock($campaign);
        $current = $campaign->fresh()->gameSystems->pluck('id')->all();

        editCampaignModeMounted($campaign)
            ->set('game_system_id', GameSystem::factory()->create()->id)
            ->call('save')
            ->assertHasErrors(['edit']);

        expect($campaign->fresh()->gameSystems->pluck('id')->all())->toBe($current);
    });
});

// ═══════════════════════════════════════════════════════════
// (5) + (9) FREE FIELDS ON A LOCKED CAMPAIGN
// ═══════════════════════════════════════════════════════════

describe('EditCampaign — locked campaign, free fields', function () {
    it('still saves name and description', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        editCampaignModeLock($campaign);

        editCampaignModeMounted($campaign)
            ->set('name', 'Shadows of Baldur’s Gate')
            ->set('description', 'Now with dragons.')
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->getTranslation('name', 'en'))->toBe('Shadows of Baldur’s Gate')
            ->and($campaign->fresh()->getTranslation('description', 'en'))->toBe('Now with dragons.');
    });

    it('pre-fills the vibe flags and saves a changed set', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        editCampaignModeLock($campaign);

        editCampaignModeMounted($campaign)
            ->assertSet('vibePreferences', ['cooperative' => 'favorite'])
            ->set('vibePreferences', ['cooperative' => 'favorite', 'competitive' => 'favorite'])
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->vibe_flags)->toBe(['cooperative', 'competitive'])
            ->and(editCampaignModeChangedFields($campaign))->toBe([['vibe_flags']]);
    });
});

// ═══════════════════════════════════════════════════════════
// (7) VISIBILITY IS DIRECTION-DEPENDENT
// ═══════════════════════════════════════════════════════════

describe('EditCampaign — visibility on a locked campaign', function () {
    it('saves private → public and leaves every option enabled', function () {
        $owner = editCampaignModeOwner(['can_create_public_entries' => true]);
        $campaign = editCampaignModeCampaign($owner, ['visibility' => Visibility::Private->value]);
        editCampaignModeLock($campaign);

        editCampaignModeMounted($campaign)
            ->set('visibility', 'public')
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->visibility)->toBe(Visibility::Public)
            ->and(editCampaignModeChangedFields($campaign))->toBe([['visibility']]);
    });

    it('refuses public → private and disables only the private option', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner, ['visibility' => Visibility::Public->value]);
        editCampaignModeLock($campaign);

        $component = editCampaignModeMounted($campaign);
        $html = $component->html();

        // Only the locked direction is disabled: the private option. The select
        // itself — and with it protected, and staying public — stays available.
        expect($html)->toContain('<option value="private" disabled>')
            ->and(editCampaignModeFieldDisabled($html, 'campaign-visibility'))->toBeFalse();

        $component
            ->set('visibility', 'private')
            ->call('save')
            ->assertHasErrors(['edit']);

        expect($campaign->fresh()->visibility)->toBe(Visibility::Public);
    });
});

// ═══════════════════════════════════════════════════════════
// (8) TRANSLATABLE PREFILL
// ═══════════════════════════════════════════════════════════

describe('EditCampaign — translatable fields', function () {
    it('pre-fills from the STORED translations and leaves both byte-identical on a save that changes only another field', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        editCampaignModeLock($campaign);

        editCampaignModeMounted($campaign)
            ->assertSet('activeLocale', 'en')
            ->assertSet('pendingTranslations.de.name', 'Schatten von Tiefwasser')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de')
            ->assertSet('pendingTranslations.de.name', 'Schatten von Tiefwasser')
            ->call('switchLocale', 'en')
            ->assertSet('name', 'Shadows of Waterdeep')
            ->set('host_note', 'Bring snacks too')
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->getTranslations('name'))->toEqual(['en' => 'Shadows of Waterdeep', 'de' => 'Schatten von Tiefwasser'])
            ->and($campaign->fresh()->getTranslations('description'))->toEqual(['en' => 'A weekly intrigue campaign.', 'de' => 'Ein wöchentliches Intrigen-Abenteuer.'])
            ->and(editCampaignModeChangedFields($campaign))->toBe([['host_note']]);
    });
});

// ═══════════════════════════════════════════════════════════
// (10) LIFECYCLE GUARD
// ═══════════════════════════════════════════════════════════

describe('EditCampaign — lifecycle guard', function () {
    it('refuses save() on a campaign that became Completed and changes nothing', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);

        $component = editCampaignModeMounted($campaign);
        $campaign->fresh()->update(['status' => CampaignStatus::Completed->value]);

        $component
            ->set('name', 'Too late')
            ->call('save')
            ->assertHasErrors(['edit']);

        expect($campaign->fresh()->getTranslation('name', 'en'))->toBe('Shadows of Waterdeep')
            ->and(editCampaignModeChangedFields($campaign))->toBe([]);
    });
});

// ═══════════════════════════════════════════════════════════
// DETAIL PAGE WIRING
// ═══════════════════════════════════════════════════════════

describe('CampaignDetail — edit mode', function () {
    it('shows the Edit campaign button to the owner of an active campaign and mounts the edit form in place', function () {
        Storage::fake('public');
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);

        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSet('editing', false)
            ->assertSet('canEditCampaign', true)
            ->assertSee(__('campaigns.action_edit_campaign'))
            ->assertDontSee(__('games.action_save_game_changes'))
            ->call('startEditing')
            ->assertSet('editing', true)
            ->assertSee(__('games.action_save_game_changes'))
            ->assertSeeHtml('wire:click="cancel"');
    });

    it('hides the Edit campaign button from a user who cannot update the campaign and refuses startEditing', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner, ['visibility' => Visibility::Public->value]);
        $stranger = editCampaignModeOwner();

        Livewire::actingAs($stranger)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSet('canEditCampaign', false)
            ->assertDontSee(__('campaigns.action_edit_campaign'))
            ->call('startEditing')
            ->assertSet('editing', false);
    });

    it('hides the Edit campaign button once the campaign is completed', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner, ['status' => CampaignStatus::Completed->value]);

        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSet('canEditCampaign', false)
            ->assertDontSee(__('campaigns.action_edit_campaign'));
    });

    it('returns to the read-only page on the edit form events', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);

        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('startEditing')
            ->assertSet('editing', true)
            ->call('stopEditing')
            ->assertSet('editing', false)
            ->call('startEditing')
            ->assertSet('editing', true)
            ->call('cancelEditing')
            ->assertSet('editing', false);
    });
});
