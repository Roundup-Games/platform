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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportTesting\Testable;
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
 * Swap the public disk — the media library's disk — for one whose writes fail
 * the way a full or unreachable storage fails: every write throws
 * UnableToWriteFile. Reads are untouched, and the Livewire temporary upload
 * lives on another disk.
 *
 * The twin of editGameModeUnwritablePublicDisk() in EditGameModeTest.php —
 * copied under its own name because both files declare global helpers.
 */
function editCampaignModeUnwritablePublicDisk(): void
{
    Storage::fake('public');
    $fake = Storage::disk('public');

    $adapter = new class($fake->path('')) extends LocalFilesystemAdapter
    {
        public function writeStream(string $path, $contents, Config $config): void
        {
            throw UnableToWriteFile::atLocation($path, 'public disk is unwritable in this test');
        }
    };

    Storage::set('public', new FilesystemAdapter(new Flysystem($adapter), $adapter, $fake->getConfig()));
}

/**
 * Turn the cover picked on a mounted edit form into what a Livewire temporary
 * upload is in the browser: a file whose metadata is GONE once it is stored.
 *
 * ->set('cover_image', <file>) drives the real upload round trip
 * (_startUpload → validateAndStore → _finishUpload), so the form holds a real
 * TemporaryUploadedFile on the temporary disk. The test harness stores a
 * `<hash>.json` sidecar next to it, and TemporaryUploadedFile::getSize() and
 * ::getMimeType() answer from that sidecar only under runningUnitTests() — the
 * branch that hides the production failure this guards. The helper strips
 * `size` and `type` from the sidecar (the name stays: spatie derives the
 * stored file name from it), so both reads take their production branch and
 * read the temporary file itself.
 *
 * Storing the upload then behaves exactly as in production: spatie copies the
 * file into the media collection and unlinks the temporary file, so every read
 * of the upload AFTER it was stored fails with
 * League\Flysystem\UnableToRetrieveMetadata.
 *
 * The twin of editGameModeConsumableCover() in EditGameModeTest.php.
 */
function editCampaignModeConsumableCover(Testable $form): void
{
    $upload = $form->get('cover_image');

    if (! $upload instanceof TemporaryUploadedFile) {
        throw new RuntimeException('Pick a cover image on the edit form before making it consumable.');
    }

    $disk = FileUploadConfiguration::storage();
    $sidecar = FileUploadConfiguration::path($upload->getFilename().'.json');
    $meta = json_decode((string) $disk->get($sidecar), true);

    if (! is_array($meta) || ! isset($meta['size'], $meta['type'])) {
        throw new RuntimeException('The metadata sidecar of the picked upload was not found: the Livewire upload harness changed.');
    }

    $disk->put($sidecar, json_encode(Arr::except($meta, ['size', 'type'])));
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

/**
 * The RAW JSON of the two translatable columns — the bytes the database
 * holds, not the locale-resolved text. Captured before an edit, it is what a
 * "keeps both translations" case compares the row against afterwards: a
 * translation re-labelled under another locale key shows up here even when
 * the text itself is unchanged.
 *
 * @return array{name: mixed, description: mixed}
 */
function editCampaignModeStoredTranslations(Campaign $campaign): array
{
    return [
        'name' => $campaign->fresh()->getRawOriginal('name'),
        'description' => $campaign->fresh()->getRawOriginal('description'),
    ];
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
// COVER IMAGE — SAVED WITH THE FORM, FAILURES STAY VISIBLE (M084/S08-T01)
// ═══════════════════════════════════════════════════════════

describe('EditCampaign — cover image', function () {
    it('keeps the form open on the cover error when the upload fails during a full save, and still saves the columns', function () {
        editCampaignModeUnwritablePublicDisk();
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        editCampaignModeLock($campaign);

        $component = editCampaignModeMounted($campaign)
            ->set('name', 'Shadows of Baldur’s Gate')
            ->set('cover_image', UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->call('save')
            ->assertHasErrors(['cover_image'])
            ->assertNotDispatched('campaign-edit-saved');

        // The columns went through the service; only the cover failed. The owner
        // keeps the form (and the picked upload) to retry or drop it — no
        // success flash closes the form over an error nobody would see.
        expect($campaign->fresh()->getTranslation('name', 'en'))->toBe('Shadows of Baldur’s Gate')
            ->and(editCampaignModeChangedFields($campaign))->toBe([['name']])
            ->and(session('success'))->toBeNull();

        // The retry succeeds once the disk is back.
        Storage::fake('public');

        $component->call('saveCover')->assertDispatched('campaign-edit-saved');

        expect($campaign->fresh()->getFirstMedia('cover'))->not->toBeNull();
    });

    it('uploads a cover through a full save and through saveCover() on an unlocked and on a locked campaign', function (bool $locked, string $action) {
        Storage::fake('public');
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);

        if ($locked) {
            editCampaignModeLock($campaign);
        }

        editCampaignModeMounted($campaign)
            ->set('cover_image', UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->call($action)
            ->assertHasNoErrors()
            ->assertDispatched('campaign-edit-saved')
            ->assertSet('cover_image', null);

        expect($campaign->fresh()->getFirstMedia('cover'))->not->toBeNull()
            ->and($campaign->fresh()->name)->toBe('Shadows of Waterdeep');
    })->with([
        'unlocked campaign, full save' => [false, 'save'],
        'locked campaign, full save' => [true, 'save'],
        'unlocked campaign, saveCover()' => [false, 'saveCover'],
        'locked campaign, saveCover()' => [true, 'saveCover'],
    ]);

    //
    // In the browser a cover pick followed by Save answered HTTP 500 on every
    // run (S03 UAT, "Check 5 + 10"). The upload WAS stored — then storeCover()
    // logged the upload's mime type and size, and those reads hit the temporary
    // file spatie had just unlinked. The error left the form open with no message,
    // and the hero kept the old cover until a reload. A plain ->set('cover_image',
    // <file>) cannot see it: Livewire's test harness answers both reads from a
    // metadata sidecar instead of the file (editCampaignModeConsumableCover()).
    //
    it('does not read the uploaded file after it was stored', function (bool $locked, string $action) {
        Storage::fake('public');
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);

        if ($locked) {
            editCampaignModeLock($campaign);
        }

        $form = editCampaignModeMounted($campaign)
            ->set('cover_image', UploadedFile::fake()->image('cover.jpg', 800, 600));

        editCampaignModeConsumableCover($form);

        $form->call($action)
            ->assertHasNoErrors()
            ->assertDispatched('campaign-edit-saved')
            ->assertSet('cover_image', null);

        expect($campaign->fresh()->getFirstMedia('cover'))->not->toBeNull()
            ->and($campaign->fresh()->name)->toBe('Shadows of Waterdeep');
    })->with([
        'unlocked campaign, full save' => [false, 'save'],
        'locked campaign, full save' => [true, 'save'],
        'locked campaign, saveCover()' => [true, 'saveCover'],
    ]);
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
// CONTENT-LANGUAGE CHANGE MOVES THE BASELINE (M084/S07, D324)
// ═══════════════════════════════════════════════════════════

//
// On an EDIT form a content-language change moves the baseline; it never
// re-labels text. Before this, `language` en → de left the English text in
// the fields while getBaselineLocale() already answered 'de', so the save
// stored {"de":"Shadows of Waterdeep","en":"Shadows of Waterdeep"} and
// destroyed the German translation. "Byte-identical" below is the RAW JSON of
// both columns, captured before the edit (editCampaignModeStoredTranslations()).
//

describe('EditCampaign — content-language change moves the baseline', function () {
    it('keeps both stored translations byte-identical when the content language changes en → de', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        $stored = editCampaignModeStoredTranslations($campaign);

        editCampaignModeMounted($campaign)
            ->assertSet('baselineFieldsLocale', 'en')
            ->set('language', 'de')
            ->assertSet('name', 'Schatten von Tiefwasser')
            ->assertSet('description', 'Ein wöchentliches Intrigen-Abenteuer.')
            ->assertSet('pendingTranslations.en.name', 'Shadows of Waterdeep')
            ->assertSet('pendingTranslations.en.description', 'A weekly intrigue campaign.')
            ->assertSet('activeLocale', 'de')
            ->assertSet('baselineFieldsLocale', 'de')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('campaign-edit-saved');

        expect($campaign->fresh()->language)->toBe('de')
            ->and(editCampaignModeStoredTranslations($campaign))->toBe($stored)
            ->and(editCampaignModeChangedFields($campaign))->toBe([['language']]);
    });

    it('keeps both byte-identical when the change and the save travel in ONE request', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        $stored = editCampaignModeStoredTranslations($campaign);

        // Livewire applies every property update first and runs the updated*
        // hooks before the method, so the baseline has moved when save() reads
        // the fields — also when `language` rides along in the save request.
        editCampaignModeMounted($campaign)
            ->update(calls: [['method' => 'save', 'params' => [], 'path' => '']], updates: ['language' => 'de'])
            ->assertHasNoErrors()
            ->assertDispatched('campaign-edit-saved');

        expect($campaign->fresh()->language)->toBe('de')
            ->and(editCampaignModeStoredTranslations($campaign))->toBe($stored)
            ->and(editCampaignModeChangedFields($campaign))->toBe([['language']]);
    });

    it('moves the baseline back de → en and keeps both byte-identical', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner, ['language' => 'de']);
        $stored = editCampaignModeStoredTranslations($campaign);

        editCampaignModeMounted($campaign)
            ->assertSet('name', 'Schatten von Tiefwasser')
            ->set('language', 'en')
            ->assertSet('name', 'Shadows of Waterdeep')
            ->assertSet('description', 'A weekly intrigue campaign.')
            ->assertSet('pendingTranslations.de.name', 'Schatten von Tiefwasser')
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->language)->toBe('en')
            ->and(editCampaignModeStoredTranslations($campaign))->toBe($stored)
            ->and(editCampaignModeChangedFields($campaign))->toBe([['language']]);
    });

    it('reports the content language next to only the fields the owner touched', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        $stored = editCampaignModeStoredTranslations($campaign);

        editCampaignModeMounted($campaign)
            ->set('recurrence', 'monthly')
            ->set('language', 'de')
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->recurrence)->toBe('monthly')
            ->and($campaign->fresh()->language)->toBe('de')
            ->and(editCampaignModeStoredTranslations($campaign))->toBe($stored)
            ->and(editCampaignModeChangedFields($campaign))->toBe([['recurrence', 'language']]);
    });

    it('saves text edited after the change under the NEW locale', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        $stored = editCampaignModeStoredTranslations($campaign);

        editCampaignModeMounted($campaign)
            ->set('language', 'de')
            ->set('name', 'Neue Kampagne')
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->getTranslations('name'))->toEqual(['en' => 'Shadows of Waterdeep', 'de' => 'Neue Kampagne'])
            ->and($campaign->fresh()->getRawOriginal('description'))->toBe($stored['description'])
            ->and(editCampaignModeChangedFields($campaign))->toBe([['name', 'language']]);
    });

    it('moves the baseline STRICTLY: an English-only campaign switched to German shows empty fields, keeps its English, and is refused until the German name is typed', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner, [
            'name' => ['en' => 'Shadows of Waterdeep'],
            'description' => ['en' => 'A weekly intrigue campaign.'],
        ]);
        $stored = editCampaignModeStoredTranslations($campaign);

        // Nothing is copied across locale keys: the German fields are EMPTY
        // because the campaign has no German — the English stays where it was.
        $component = editCampaignModeMounted($campaign)
            ->set('language', 'de')
            ->assertSet('name', '')
            ->assertSet('description', '')
            ->assertSet('pendingTranslations.en.name', 'Shadows of Waterdeep')
            ->assertSet('pendingTranslations.en.description', 'A weekly intrigue campaign.');

        // name is required: an empty German baseline never reaches the service.
        $component
            ->call('save')
            ->assertHasErrors(['name'])
            ->assertNotDispatched('campaign-edit-saved');

        expect($campaign->fresh()->language)->toBe('en')
            ->and(editCampaignModeStoredTranslations($campaign))->toBe($stored)
            ->and(editCampaignModeChangedFields($campaign))->toBe([]);

        $component
            ->set('name', 'Schatten von Tiefwasser')
            ->call('save')
            ->assertHasNoErrors();

        expect($campaign->fresh()->language)->toBe('de')
            ->and($campaign->fresh()->getTranslations('name'))->toEqual(['en' => 'Shadows of Waterdeep', 'de' => 'Schatten von Tiefwasser'])
            ->and($campaign->fresh()->getRawOriginal('description'))->toBe($stored['description'])
            ->and(editCampaignModeChangedFields($campaign))->toBe([['name', 'language']]);
    });

    it('restores the exact prior state on en → de → en and fabricates no translation', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner, [
            'name' => ['en' => 'Shadows of Waterdeep'],
            'description' => ['en' => 'A weekly intrigue campaign.'],
        ]);
        $stored = editCampaignModeStoredTranslations($campaign);

        editCampaignModeMounted($campaign)
            ->set('language', 'de')
            ->assertSet('name', '')
            ->set('language', 'en')
            ->assertSet('name', 'Shadows of Waterdeep')
            ->assertSet('description', 'A weekly intrigue campaign.')
            ->assertSet('activeLocale', 'en')
            ->call('save')
            ->assertHasNoErrors();

        // No `de` key appears: the detour through the empty German baseline
        // left not a trace in either column.
        expect($campaign->fresh()->language)->toBe('en')
            ->and(editCampaignModeStoredTranslations($campaign))->toBe($stored)
            ->and(editCampaignModeChangedFields($campaign))->toBe([]);
    });

    it('refuses a forged language change on a LOCKED campaign and writes nothing', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);
        editCampaignModeLock($campaign);
        $stored = editCampaignModeStoredTranslations($campaign);

        // The select is disabled once the agreement is locked, but a request
        // can still send the property: the hook swaps the fields in memory
        // only, and language is Tier 1 — the gate stays with the service.
        editCampaignModeMounted($campaign)
            ->set('language', 'de')
            ->call('save')
            ->assertHasErrors(['edit'])
            ->assertNotDispatched('campaign-edit-saved');

        expect($campaign->fresh()->language)->toBe('en')
            ->and(editCampaignModeStoredTranslations($campaign))->toBe($stored)
            ->and(editCampaignModeChangedFields($campaign))->toBe([]);
    });

    it('binds the content-language select LIVE so the fields swap the moment it changes', function () {
        $owner = editCampaignModeOwner();
        $campaign = editCampaignModeCampaign($owner);

        // Deferred, the select's value would only travel with the NEXT request
        // — together with text typed into fields that still show the previous
        // locale. The create form keeps the deferred binding (pinned by
        // CreateCampaignFormRenderTest): its select re-labels the typed text.
        editCampaignModeMounted($campaign)->assertSeeHtml('wire:model.live="language"');
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
