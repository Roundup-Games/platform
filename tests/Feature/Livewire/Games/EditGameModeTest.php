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
use App\Services\OwnerParticipantService;
use App\Support\Edit\FieldTiers;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
        $changedFields = ActivityLog::query()
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

        expect($changedFields)->toBe([['price']]);
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
