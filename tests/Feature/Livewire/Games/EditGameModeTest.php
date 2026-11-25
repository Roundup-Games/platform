<?php

use App\Enums\ActivityType;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Livewire\Games\EditGame;
use App\Livewire\Games\GameDetail;
use App\Models\ActivityLog;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\EntityUpdated;
use App\Services\OwnerParticipantService;
use App\Support\Edit\FieldTiers;
use Carbon\Carbon;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

//
// Guards the owner edit mode on the game detail page (M084/S03-T02, D315 + D314).
//
// The lock is always the production predicate: an Approved non-owner
// participant row written through the participant model, whose saving()
// observer stamps approved_at. Nothing here stubs isAgreementLocked().
//
// App\Livewire\Games\EditGame shares its short name with the Filament page
// App\Filament\Resources\GameResource\Pages\EditGame — always import by
// fully qualified name (the `use` above is what binds it in this file).
//

// ── Helpers ──────────────────────────────────────────────

function editGameModeOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge(['timezone' => 'Europe/Berlin', 'profile_complete' => true], $overrides));
}

/**
 * A scheduled board game one week out. The stored instants carry NON-ZERO
 * seconds on purpose: storage is second-precise, the datetime-local input is
 * minute-precise, so an untouched date must not be re-converted on save.
 *
 * @param  array<string, mixed>  $overrides
 */
function editGameModeGame(User $owner, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Board night', 'de' => 'Brettspielabend'],
        'description' => ['en' => 'A friendly board night.', 'de' => 'Ein gemütlicher Spieleabend.'],
        'date_time' => now()->addDays(7)->setSecond(37),
        'signup_cutoff_at' => now()->addDays(6)->setSecond(12),
        'expected_duration' => 2.5,
        'price' => 12.5,
        'max_players' => 5,
        'min_players' => 2,
        'visibility' => Visibility::Protected->value,
        'status' => GameStatus::Scheduled->value,
        'language' => 'en',
        'location_instructions' => 'Ring the bell',
        'experience_level' => 'beginner',
        'complexity' => 2.25,
        'vibe_flags' => ['cooperative'],
        'min_reliability_preference' => 85,
    ], $overrides));
}

/** The owner row plus ONE confirmed non-owner: the game is agreement-locked from here on. */
function editGameModeLock(Game $game): GameParticipant
{
    app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

    return GameParticipant::factory()->create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'status' => ParticipantStatus::Approved->value,
    ]);
}

/**
 * A LOCKED board game whose stored document carries comfort notes — the
 * payload a forced ttrpg type would drop. Its expected_duration is the ttrpg
 * type default, so a forced selectType('ttrpg') changes the type and nothing
 * else.
 *
 * @return array{0: User, 1: Game, 2: GameParticipant}
 */
function editGameModeLockedBoardGame(): array
{
    $owner = editGameModeOwner();
    $game = editGameModeGame($owner, [
        'expected_duration' => 3,
        'safety_rules' => ['comfort_notes' => 'Bring snacks'],
    ]);

    return [$owner, $game, editGameModeLock($game)];
}

/** The `changed_fields` of every GameUpdated activity entry for the game, oldest first. */
function editGameModeChangedFields(Game $game): array
{
    return ActivityLog::query()
        ->where('subject_type', $game->getMorphClass())
        ->where('subject_id', $game->getKey())
        ->where('event_type', ActivityType::GameUpdated->value)
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
function editGameModeStoredTranslations(Game $game): array
{
    return [
        'name' => $game->fresh()->getRawOriginal('name'),
        'description' => $game->fresh()->getRawOriginal('description'),
    ];
}

/** The wall-clock string the datetime-local input carries for a stored instant (viewer zone). */
function editGameModeWall(Carbon $instant): string
{
    return $instant->copy()->timezone('Europe/Berlin')->format('Y-m-d\TH:i');
}

/** Whether the rendered HTML shows the input or select with the given id as disabled. */
function editGameModeFieldDisabled(string $html, string $id): bool
{
    return (bool) preg_match('/<(?:input|select)[^>]*\bid="'.preg_quote($id, '/').'"[^>]*\bdisabled/', $html);
}

function editGameModeMounted(Game $game, ?User $as = null)
{
    return Livewire::actingAs($as ?? $game->owner)
        ->test(EditGame::class, ['game' => $game]);
}

/**
 * Swap the public disk — the media library's disk — for one whose writes fail
 * the way a full or unreachable storage fails: every write throws
 * UnableToWriteFile. Reads are untouched, and the Livewire temporary upload
 * lives on another disk.
 */
function editGameModeUnwritablePublicDisk(): void
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

// ═══════════════════════════════════════════════════════════
// (1) MOUNT — PRE-FILL
// ═══════════════════════════════════════════════════════════

describe('EditGame — mount', function () {
    it('pre-fills every editable property from the model', function () {
        Storage::fake('public');
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $game->load('gameSystems');

        editGameModeMounted($game)
            ->assertOk()
            ->assertSet('game_type', 'board_game')
            ->assertSet('step', 'form')
            ->assertSet('name', 'Board night')
            ->assertSet('description', 'A friendly board night.')
            ->assertSet('language', 'en')
            ->assertSet('date_time', editGameModeWall($game->date_time))
            ->assertSet('originalDateTime', editGameModeWall($game->date_time))
            ->assertSet('signup_cutoff_at', editGameModeWall($game->signup_cutoff_at))
            ->assertSet('expected_duration', '2.5')
            ->assertSet('price', '12.5')
            ->assertSet('visibility', Visibility::Protected->value)
            ->assertSet('min_players', 2)
            ->assertSet('max_players', 5)
            ->assertSet('bench_mode', false)
            ->assertSet('experience_level', 'beginner')
            ->assertSet('complexity', '2.25')
            ->assertSet('minimum_requirements', [])
            ->assertSet('location_instructions', 'Ring the bell')
            ->assertSet('location_id', null)
            ->assertSet('host_note', null)
            ->assertSet('min_reliability_preference', '85')
            ->assertSet('game_system_id', $game->fresh()->gameSystems->first()->id)
            ->assertSet('game_systems', [])
            ->assertSet('originalSystemIds', [$game->fresh()->gameSystems->first()->id])
            ->assertSet('vibePreferences', ['cooperative' => 'favorite'])
            ->assertSet('comfort_notes', '')
            ->assertSet('safety_rules', [])
            ->assertSet('cover_image', null)
            ->assertSet('isLocked', false)
            ->assertSet('lockedFields', []);
    });

    it('gives a user who cannot update the game a 403 on mount', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $stranger = editGameModeOwner();

        editGameModeMounted($game, $stranger)
            ->assertForbidden();
    });
});

// ═══════════════════════════════════════════════════════════
// (2) DATETIME ROUND-TRIP
// ═══════════════════════════════════════════════════════════

describe('EditGame — datetime round-trip', function () {
    it('leaves an untouched date_time byte-identical on a LOCKED game whose stored instant has non-zero seconds', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        editGameModeLock($game);

        $storedDateTime = $game->fresh()->date_time->format('Y-m-d H:i:s');
        $storedCutoff = $game->fresh()->signup_cutoff_at->format('Y-m-d H:i:s');
        expect($storedDateTime)->toEndWith(':37');

        editGameModeMounted($game)
            ->assertSet('isLocked', true)
            ->call('save')
            ->assertHasNoErrors();

        // The stored instants are byte-identical: an untouched minute-precision
        // form value was never re-converted into a Tier-1 "change".
        expect($game->fresh()->date_time->format('Y-m-d H:i:s'))->toBe($storedDateTime)
            ->and($game->fresh()->signup_cutoff_at->format('Y-m-d H:i:s'))->toBe($storedCutoff);
    });
});

// ═══════════════════════════════════════════════════════════
// (3) + (4) UNLOCKED SAVES
// ═══════════════════════════════════════════════════════════

describe('EditGame — unlocked game', function () {
    it('syncs the gameSystems pivot when the game system changes', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $newSystem = GameSystem::factory()->create();

        editGameModeMounted($game)
            ->set('game_system_id', $newSystem->id)
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->gameSystems->pluck('id')->all())->toBe([$newSystem->id]);
    });

    it('persists a changed date_time and price through AgreementEditService', function () {
        Notification::fake();
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $wall = now()->addDays(10)->setTime(19, 30);

        editGameModeMounted($game)
            ->set('date_time', $wall->copy()->timezone('Europe/Berlin')->format('Y-m-d\TH:i'))
            ->set('price', '20')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $game->fresh();
        expect($fresh->price)->toBe(20.0)
            ->and($fresh->date_time->getTimestamp())->toBe(wall_time_to_utc($wall->copy()->timezone('Europe/Berlin')->format('Y-m-d\TH:i'))->getTimestamp());
    });

    it('does not rewrite a multi-system pivot when the single picker was left on its first system', function () {
        $owner = editGameModeOwner();
        [$first, $second] = GameSystem::factory()->count(2)->create();
        $game = editGameModeGame($owner, ['owner_id' => $owner->id]);
        $game->gameSystems()->sync([$first->id, $second->id]);

        // The single picker can only show ONE system: it shows the pivot's first row and
        // an untouched form must leave the other row on the pivot.
        $component = editGameModeMounted($game);
        $shown = $component->get('game_system_id');

        expect([$first->id, $second->id])->toContain($shown);

        $component
            ->assertSet('originalSystemIds', [$shown])
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->gameSystems->pluck('id')->sort()->values()->all())
            ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
    });
});

// ═══════════════════════════════════════════════════════════
// (5) + (6) + (12) + (13) LOCKED GAME
// ═══════════════════════════════════════════════════════════

describe('EditGame — locked game', function () {
    it('renders the lock reason with the lock date and disables the Tier-1 inputs', function () {
        app()->setLocale('en');
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $member = editGameModeLock($game);
        $lockDate = $member->fresh()->approved_at;

        $component = editGameModeMounted($game)
            ->assertSet('isLocked', true)
            ->assertSee(__('games.content_agreement_locked', ['date' => format_date($lockDate, 'datetime')]));

        $html = $component->html();

        foreach (['game-date-time', 'game-min-players', 'game-duration', 'game-price', 'game-language'] as $id) {
            expect(editGameModeFieldDisabled($html, $id))->toBeTrue("field [{$id}] should be disabled");
        }

        // Still SHOWS the current values — a vanished field reads as a bug.
        expect($html)->toContain('id="game-date-time"')
            ->toContain('id="game-price"');

        // max_players is direction-dependent: an increase stays available.
        expect(editGameModeFieldDisabled($html, 'game-max-players'))->toBeFalse();

        // The offered system (Tier 1) is read-only: its picker's search input is disabled.
        expect(editGameModeFieldDisabled($html, 'game-system-search'))->toBeTrue();

        // The lock map is exactly FieldTiers' Tier-1 set for the visible, fixed-tier fields.
        $lockedFields = $component->instance()->lockedFields();
        expect(array_keys($lockedFields))->toBe([
            'game_systems', 'game_system_id', 'game_type', 'date_time',
            'expected_duration', 'price', 'min_players', 'bench_mode', 'language',
        ]);
    });

    it('classifies fields through FieldTiers and never a direction-dependent field as locked', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);

        /** @var EditGame $component */
        $component = editGameModeMounted($game)->instance();

        expect($component->tierOf('date_time'))->toBe(FieldTiers::TIER_LOCKED)
            ->and($component->tierOf('name'))->toBe(FieldTiers::TIER_FREE)
            ->and($component->tierOf('location_id'))->toBe(FieldTiers::TIER_NOTIFIED)
            ->and($component->tierOf('max_players'))->toBe(FieldTiers::TIER_FREE)
            ->and($component->tierOf('visibility'))->toBe(FieldTiers::TIER_FREE);
    });

    it('refuses a forged save with a changed date_time and changes nothing', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        editGameModeLock($game);
        $before = $game->fresh()->only(['name', 'date_time', 'price', 'description']);

        editGameModeMounted($game)
            ->set('date_time', now()->addDays(20)->timezone('Europe/Berlin')->format('Y-m-d\TH:i'))
            ->set('price', '99')
            ->call('save')
            ->assertHasErrors(['edit']);

        $after = $game->fresh()->only(['name', 'date_time', 'price', 'description']);
        expect($after)->toEqual($before);
    });

    it('refuses a forged game-system change with the lock exception and leaves the pivot alone', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        editGameModeLock($game);
        $current = $game->fresh()->gameSystems->pluck('id')->all();
        $newSystem = GameSystem::factory()->create();

        editGameModeMounted($game)
            ->set('game_system_id', $newSystem->id)
            ->call('save')
            ->assertHasErrors(['edit']);

        expect($game->fresh()->gameSystems->pluck('id')->all())->toBe($current);
    });

    it('still saves the vibe flags (Tier 3) on a locked game', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        editGameModeLock($game);

        editGameModeMounted($game)
            ->assertSet('vibePreferences', ['cooperative' => 'favorite'])
            ->set('vibePreferences', ['cooperative' => 'favorite', 'competitive' => 'favorite'])
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->vibe_flags)->toBe(['cooperative', 'competitive']);
    });

    it('promotes a waitlisted player to Pending when max_players increases', function () {
        Notification::fake();
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner, ['max_players' => 2]);
        editGameModeLock($game);
        $waitlisted = GameParticipant::factory()->waitlisted()->create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
        ]);

        editGameModeMounted($game)
            ->set('max_players', 3)
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->max_players)->toBe(3)
            ->and($waitlisted->fresh()->status)->toBe(ParticipantStatus::Pending);
    });
});

// ═══════════════════════════════════════════════════════════
// (7) + (9) NAME / DESCRIPTION / COVER ON A LOCKED GAME
// ═══════════════════════════════════════════════════════════

describe('EditGame — locked game, free fields', function () {
    it('still saves name, description and a cover image', function () {
        Storage::fake('public');
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        editGameModeLock($game);

        editGameModeMounted($game)
            ->set('name', 'Renamed night')
            ->set('description', 'Now with snacks.')
            ->set('cover_image', UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $game->fresh();
        expect($fresh->getTranslation('name', 'en'))->toBe('Renamed night')
            ->and($fresh->getTranslation('description', 'en'))->toBe('Now with snacks.')
            ->and($fresh->getFirstMedia('cover'))->not->toBeNull();
    });

    it('uploads a cover on its own through saveCover()', function () {
        Storage::fake('public');
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        editGameModeLock($game);

        editGameModeMounted($game)
            ->set('cover_image', UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->call('saveCover')
            ->assertHasNoErrors();

        expect($game->fresh()->getFirstMedia('cover'))->not->toBeNull()
            ->and($game->fresh()->name)->toBe('Board night');
    });

    it('keeps the form open on the cover error when the upload fails during a full save, and still saves the columns', function () {
        editGameModeUnwritablePublicDisk();
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        editGameModeLock($game);

        $component = editGameModeMounted($game)
            ->set('name', 'Renamed night')
            ->set('cover_image', UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->call('save')
            ->assertHasErrors(['cover_image'])
            ->assertNotDispatched('game-edit-saved');

        // The columns went through the service; only the cover failed. The owner
        // keeps the form (and the picked upload) to retry or drop it — no
        // success flash closes the form over an error nobody would see.
        expect($game->fresh()->getTranslation('name', 'en'))->toBe('Renamed night')
            ->and(editGameModeChangedFields($game))->toBe([['name']])
            ->and(session('success'))->toBeNull();

        // The retry succeeds once the disk is back.
        Storage::fake('public');

        $component->call('saveCover')->assertDispatched('game-edit-saved');

        expect($game->fresh()->getFirstMedia('cover'))->not->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// (10) + (11) TRANSLATABLE PREFILL
// ═══════════════════════════════════════════════════════════

describe('EditGame — translatable fields', function () {
    it('pre-fills every locale tab from the STORED translations and leaves both byte-identical on a price-only save', function () {
        // Price is Tier 1, so this save needs an OPEN agreement — the lock's own
        // refusal of a price change is covered in the locked-game block.
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);

        // A locale tab's inputs are bound with wire:model (no server-rendered value
        // attribute), so "the tab shows the stored translation" is asserted on the
        // model each tab binds to.
        editGameModeMounted($game)
            ->assertSet('isLocked', false)
            ->assertSet('activeLocale', 'en')
            ->assertSet('name', 'Board night')
            ->assertSet('description', 'A friendly board night.')
            ->assertSet('pendingTranslations.de.name', 'Brettspielabend')
            ->assertSet('pendingTranslations.de.description', 'Ein gemütlicher Spieleabend.')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de')
            ->assertSet('pendingTranslations.de.name', 'Brettspielabend')
            ->call('switchLocale', 'en')
            ->assertSet('name', 'Board night')
            ->set('price', '15')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $game->fresh();
        expect($fresh->getTranslations('name'))->toEqual(['en' => 'Board night', 'de' => 'Brettspielabend'])
            ->and($fresh->getTranslations('description'))->toEqual(['en' => 'A friendly board night.', 'de' => 'Ein gemütlicher Spieleabend.'])
            ->and($fresh->price)->toBe(15.0);

        // The one GameUpdated entry reports only the price: name and description
        // were never changed columns (the participant announcement reads the same list).
        expect(editGameModeChangedFields($game))->toBe([['price']]);
    });

    it('keeps the English name when only the German name changes', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);

        editGameModeMounted($game)
            ->call('switchLocale', 'de')
            ->set('pendingTranslations.de.name', 'Neuer Spieleabend')
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->getTranslations('name'))->toEqual(['en' => 'Board night', 'de' => 'Neuer Spieleabend']);
    });
});

// ═══════════════════════════════════════════════════════════
// CONTENT-LANGUAGE CHANGE MOVES THE BASELINE (M084/S07, D324)
// ═══════════════════════════════════════════════════════════

//
// On an EDIT form a content-language change moves the baseline; it never
// re-labels text. Before this, `language` en → de left the English text in
// the fields while getBaselineLocale() already answered 'de', so the save
// stored {"de":"Board night","en":"Board night"} and destroyed the
// German translation. "Byte-identical" below is the RAW JSON of both
// columns, captured before the edit (editGameModeStoredTranslations()).
//

describe('EditGame — content-language change moves the baseline', function () {
    it('keeps both stored translations byte-identical when the content language changes en → de', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $stored = editGameModeStoredTranslations($game);

        editGameModeMounted($game)
            ->assertSet('baselineFieldsLocale', 'en')
            ->set('language', 'de')
            ->assertSet('name', 'Brettspielabend')
            ->assertSet('description', 'Ein gemütlicher Spieleabend.')
            ->assertSet('pendingTranslations.en.name', 'Board night')
            ->assertSet('pendingTranslations.en.description', 'A friendly board night.')
            ->assertSet('activeLocale', 'de')
            ->assertSet('baselineFieldsLocale', 'de')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('game-edit-saved');

        expect($game->fresh()->language)->toBe('de')
            ->and(editGameModeStoredTranslations($game))->toBe($stored)
            ->and(editGameModeChangedFields($game))->toBe([['language']]);
    });

    it('keeps both byte-identical when the change and the save travel in ONE request', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $stored = editGameModeStoredTranslations($game);

        // Livewire applies every property update first and runs the updated*
        // hooks before the method, so the baseline has moved when save() reads
        // the fields — also when `language` rides along in the save request.
        editGameModeMounted($game)
            ->update(calls: [['method' => 'save', 'params' => [], 'path' => '']], updates: ['language' => 'de'])
            ->assertHasNoErrors()
            ->assertDispatched('game-edit-saved');

        expect($game->fresh()->language)->toBe('de')
            ->and(editGameModeStoredTranslations($game))->toBe($stored)
            ->and(editGameModeChangedFields($game))->toBe([['language']]);
    });

    it('moves the baseline back de → en and keeps both byte-identical', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner, ['language' => 'de']);
        $stored = editGameModeStoredTranslations($game);

        editGameModeMounted($game)
            ->assertSet('name', 'Brettspielabend')
            ->set('language', 'en')
            ->assertSet('name', 'Board night')
            ->assertSet('description', 'A friendly board night.')
            ->assertSet('pendingTranslations.de.name', 'Brettspielabend')
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->language)->toBe('en')
            ->and(editGameModeStoredTranslations($game))->toBe($stored)
            ->and(editGameModeChangedFields($game))->toBe([['language']]);
    });

    it('reports the content language next to only the fields the owner touched', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $stored = editGameModeStoredTranslations($game);

        editGameModeMounted($game)
            ->set('price', '15')
            ->set('language', 'de')
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->price)->toBe(15.0)
            ->and($game->fresh()->language)->toBe('de')
            ->and(editGameModeStoredTranslations($game))->toBe($stored)
            ->and(editGameModeChangedFields($game))->toBe([['price', 'language']]);
    });

    it('saves text edited after the change under the NEW locale', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        $stored = editGameModeStoredTranslations($game);

        editGameModeMounted($game)
            ->set('language', 'de')
            ->set('name', 'Neuer Spieleabend')
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->getTranslations('name'))->toEqual(['en' => 'Board night', 'de' => 'Neuer Spieleabend'])
            ->and($game->fresh()->getRawOriginal('description'))->toBe($stored['description'])
            ->and(editGameModeChangedFields($game))->toBe([['name', 'language']]);
    });

    it('moves the baseline STRICTLY: an English-only game switched to German shows empty fields, keeps its English, and is refused until the German name is typed', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner, [
            'name' => ['en' => 'Board night'],
            'description' => ['en' => 'A friendly board night.'],
        ]);
        $stored = editGameModeStoredTranslations($game);

        // Nothing is copied across locale keys: the German fields are EMPTY
        // because the game has no German — the English stays where it was.
        $component = editGameModeMounted($game)
            ->set('language', 'de')
            ->assertSet('name', '')
            ->assertSet('description', '')
            ->assertSet('pendingTranslations.en.name', 'Board night')
            ->assertSet('pendingTranslations.en.description', 'A friendly board night.');

        // name is required: an empty German baseline never reaches the service.
        $component
            ->call('save')
            ->assertHasErrors(['name'])
            ->assertNotDispatched('game-edit-saved');

        expect($game->fresh()->language)->toBe('en')
            ->and(editGameModeStoredTranslations($game))->toBe($stored)
            ->and(editGameModeChangedFields($game))->toBe([]);

        $component
            ->set('name', 'Brettspielabend')
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->language)->toBe('de')
            ->and($game->fresh()->getTranslations('name'))->toEqual(['en' => 'Board night', 'de' => 'Brettspielabend'])
            ->and($game->fresh()->getRawOriginal('description'))->toBe($stored['description'])
            ->and(editGameModeChangedFields($game))->toBe([['name', 'language']]);
    });

    it('restores the exact prior state on en → de → en and fabricates no translation', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner, [
            'name' => ['en' => 'Board night'],
            'description' => ['en' => 'A friendly board night.'],
        ]);
        $stored = editGameModeStoredTranslations($game);

        editGameModeMounted($game)
            ->set('language', 'de')
            ->assertSet('name', '')
            ->set('language', 'en')
            ->assertSet('name', 'Board night')
            ->assertSet('description', 'A friendly board night.')
            ->assertSet('activeLocale', 'en')
            ->call('save')
            ->assertHasNoErrors();

        // No `de` key appears: the detour through the empty German baseline
        // left not a trace in either column.
        expect($game->fresh()->language)->toBe('en')
            ->and(editGameModeStoredTranslations($game))->toBe($stored)
            ->and(editGameModeChangedFields($game))->toBe([]);
    });

    it('refuses a forged language change on a LOCKED game and writes nothing', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);
        editGameModeLock($game);
        $stored = editGameModeStoredTranslations($game);

        // The select is disabled once the agreement is locked, but a request
        // can still send the property: the hook swaps the fields in memory
        // only, and language is Tier 1 — the gate stays with the service.
        editGameModeMounted($game)
            ->set('language', 'de')
            ->call('save')
            ->assertHasErrors(['edit'])
            ->assertNotDispatched('game-edit-saved');

        expect($game->fresh()->language)->toBe('en')
            ->and(editGameModeStoredTranslations($game))->toBe($stored)
            ->and(editGameModeChangedFields($game))->toBe([]);
    });

    it('binds the content-language select LIVE so the fields swap the moment it changes', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);

        // Deferred, the select's value would only travel with the NEXT request
        // — together with text typed into fields that still show the previous
        // locale. The create form keeps the deferred binding (pinned by
        // CreateGameFormRenderTest): its select re-labels the typed text.
        editGameModeMounted($game)->assertSeeHtml('wire:model.live="language"');
    });

    it('keeps the baseline anchor server-authoritative: a request cannot re-point it', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);

        // Unlocked, a request could send baselineFieldsLocale = language and
        // the move would be skipped: the old text, saved under the new key.
        expect(fn () => editGameModeMounted($game)->set('baselineFieldsLocale', 'de'))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

// ═══════════════════════════════════════════════════════════
// (14) LIFECYCLE GUARD
// ═══════════════════════════════════════════════════════════

describe('EditGame — lifecycle guard', function () {
    it('refuses save() on a game that became Completed and changes nothing', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);

        $component = editGameModeMounted($game);
        $game->fresh()->update(['status' => GameStatus::Completed->value]);

        $component
            ->set('name', 'Too late')
            ->call('save')
            ->assertHasErrors(['edit']);

        expect($game->fresh()->getTranslation('name', 'en'))->toBe('Board night');
    });
});

// ═══════════════════════════════════════════════════════════
// (15) LOCKED GATHERING — THE MULTI-SYSTEM BRANCH
// ═══════════════════════════════════════════════════════════

describe('EditGame — locked gathering', function () {
    it('renders the multi-system picker disabled with the offered systems still shown, and leaves the pivot alone on an untouched save', function () {
        $owner = editGameModeOwner();
        $offered = [
            GameSystem::factory()->create(['name' => ['en' => 'Catan Junior']]),
            GameSystem::factory()->create(['name' => ['en' => 'Ticket to Ride Europe']]),
        ];
        $offeredIds = collect($offered)->pluck('id')->map(fn (string $id): string => (string) $id)->sort()->values()->all();
        $game = editGameModeGame($owner, ['game_type' => GameType::Gathering->value]);
        $game->gameSystems()->sync($offeredIds);
        editGameModeLock($game);

        $component = editGameModeMounted($game)
            ->assertSet('game_type', 'gathering')
            ->assertSet('isLocked', true)
            ->assertSet('originalSystemIds', $offeredIds);

        // The `game_systems` branch of the shared _basics partial: the
        // multi-system picker is mounted, the single one is not.
        $html = $component->html();
        expect(collect($component->get('game_systems'))->sort()->values()->all())->toBe($offeredIds)
            ->and($html)->toContain('id="preference-picker-favorite"')
            ->and($html)->not->toContain('id="game-system-search"');

        // Read-only: the search input is disabled and no chip carries a remove
        // button — while the offered systems are still shown (no dropdown
        // exists in this rendering, so the names can only come from the chips).
        expect(editGameModeFieldDisabled($html, 'preference-picker-favorite'))->toBeTrue();
        expect($html)->not->toContain('wire:click="remove(');
        expect($html)->toContain('Catan Junior')->toContain('Ticket to Ride Europe');

        // An untouched form never rewrites the multi-row pivot.
        $component->call('save')->assertHasNoErrors();

        expect($game->fresh()->gameSystems->pluck('id')->sort()->values()->all())->toBe($offeredIds);
    });
});

// ═══════════════════════════════════════════════════════════
// FORGED GAME TYPE — NOT SWITCHABLE IN THE EDIT MODE
// ═══════════════════════════════════════════════════════════

describe('EditGame — game type is not switchable', function () {
    it('refuses a request-supplied game_type outright: the property is locked', function () {
        [, $game] = editGameModeLockedBoardGame();

        expect(fn () => editGameModeMounted($game)->set('game_type', 'ttrpg'))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        expect($game->fresh()->getRawOriginal('game_type'))->toBe('board_game');
    });

    it('composes a save with the STORED type when the inherited selectType action forced another one', function () {
        Notification::fake();
        [, $game, $member] = editGameModeLockedBoardGame();
        $storedRules = $game->fresh()->getRawOriginal('safety_rules');

        editGameModeMounted($game)
            ->assertSet('game_type', 'board_game')
            ->assertSet('comfort_notes', 'Bring snacks')
            ->call('selectType', 'ttrpg')
            ->assertSet('game_type', 'ttrpg')
            ->set('name', 'Renamed night')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('game-edit-saved')
            ->assertSet('game_type', 'board_game');

        // The write was composed as a board game: the comfort notes survive
        // byte-identical and no participant is told about a safety-rules edit
        // the owner never made in the form.
        $fresh = $game->fresh();
        expect($fresh->getRawOriginal('game_type'))->toBe('board_game')
            ->and($fresh->getRawOriginal('safety_rules'))->toBe($storedRules)
            ->and($fresh->getTranslation('name', 'en'))->toBe('Renamed night')
            ->and(editGameModeChangedFields($game))->toBe([['name']]);

        Notification::assertSentTo(
            $member->user,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => $notification->changedColumns === ['name'],
        );
        Notification::assertNotSentTo(
            $member->user,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => in_array('safety_rules', $notification->changedColumns, true),
        );
    });

    it('writes nothing when the inherited changeType action emptied the form: the cleared system fails the cross-field guard', function () {
        Notification::fake();
        [, $game, $member] = editGameModeLockedBoardGame();
        $storedRules = $game->fresh()->getRawOriginal('safety_rules');

        editGameModeMounted($game)
            ->call('changeType', 'ttrpg')
            ->assertSet('game_type', 'ttrpg')
            ->set('name', 'Renamed night')
            ->call('save')
            ->assertHasErrors(['game_system_id'])
            ->assertNotDispatched('game-edit-saved');

        expect($game->fresh()->getTranslation('name', 'en'))->toBe('Board night')
            ->and($game->fresh()->getRawOriginal('safety_rules'))->toBe($storedRules)
            ->and(editGameModeChangedFields($game))->toBe([]);

        Notification::assertNothingSentTo($member->user);
    });
});

// ═══════════════════════════════════════════════════════════
// DETAIL PAGE WIRING
// ═══════════════════════════════════════════════════════════

describe('GameDetail — edit mode', function () {
    it('shows the Edit game button to the owner of a scheduled game and mounts the edit form in place', function () {
        Storage::fake('public');
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSet('editing', false)
            ->assertSet('canEditGame', true)
            ->assertSee(__('games.action_edit_game'))
            ->assertDontSee(__('games.action_save_game_changes'))
            ->call('startEditing')
            ->assertSet('editing', true)
            ->assertSee(__('games.action_save_game_changes'))
            ->assertSeeHtml('wire:click="cancel"');
    });

    it('hides the Edit game button from a user who cannot update the game and refuses startEditing', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner, ['visibility' => Visibility::Public->value]);
        $stranger = editGameModeOwner();

        Livewire::actingAs($stranger)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSet('canEditGame', false)
            ->assertDontSee(__('games.action_edit_game'))
            ->call('startEditing')
            ->assertSet('editing', false);
    });

    it('hides the Edit game button once the game is completed', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner, ['status' => GameStatus::Completed->value]);

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSet('canEditGame', false)
            ->assertDontSee(__('games.action_edit_game'));
    });

    it('returns to the read-only page on the edit form events', function () {
        $owner = editGameModeOwner();
        $game = editGameModeGame($owner);

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
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
