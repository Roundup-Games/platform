<?php

use App\Dto\EditResult;
use App\Enums\ActivityType;
use App\Enums\CalendarSyncOperation;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Jobs\SyncCalendarEventForUser;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Notifications\EntityUpdated;
use App\Services\Edit\AgreementEditService;
use App\Services\NotificationService;
use App\Services\OwnerParticipantService;
use App\Support\Edit\FieldTiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Traits\CapturesLogRecords;

//
// Guards AgreementEditService — the one gated write path for owner and admin
// field changes on games and campaigns (M084/S02-T01, decisions D313 + D314).
//
// The lock is always the production predicate: an Approved non-owner
// participant row written through the participant model, whose saving()
// observer stamps approved_at. Nothing here stubs isAgreementLocked().
//

uses(CapturesLogRecords::class);

beforeEach(function () {
    Notification::fake();
    $this->captureLogRecords();

    $this->owner = User::factory()->create();
    $this->service = app(AgreementEditService::class);
});

// ── Helpers ──────────────────────────────────────────────

/**
 * A scheduled game one week out with NO participant rows — neither the
 * factory nor the model observers create them, so the agreement is open.
 * Dates stay relative to now() so the feed-window-gated calendar sync fires.
 *
 * @param  array<string, mixed>  $overrides
 */
function agreementEditGame(User $owner, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Board night'],
        'description' => ['en' => 'A friendly board night.'],
        'date_time' => now()->addDays(7)->startOfSecond(),
        'expected_duration' => 2,
        'price' => 0,
        'max_players' => 5,
        'min_players' => 2,
        'visibility' => Visibility::Private->value,
        'status' => GameStatus::Scheduled->value,
        'language' => 'en',
        'location' => ['details' => 'Back room'],
        'location_instructions' => 'Ring the bell',
    ], $overrides));
}

/**
 * An active campaign with NO participant rows — the agreement is open.
 *
 * @param  array<string, mixed>  $overrides
 */
function agreementEditCampaign(User $owner, array $overrides = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Lost Temple'],
        'description' => ['en' => 'A weekly dungeon crawl.'],
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'price_per_session' => 0,
        'max_players' => 5,
        'min_players' => 2,
        'visibility' => Visibility::Private->value,
        'status' => CampaignStatus::Active->value,
        'language' => 'en',
        'location_instructions' => 'Ring the bell',
    ], $overrides));
}

/**
 * The owner's own participant row, created the way production creates it
 * (Approved from creation). The owner row never locks the entity.
 */
function agreementEditAddOwnerRow(Game|Campaign $entity): void
{
    $service = app(OwnerParticipantService::class);

    if ($entity instanceof Game) {
        $service->ensureOwnerParticipant($entity);

        return;
    }

    $service->ensureCampaignOwnerParticipant($entity);
}

/**
 * A non-owner participant written through the participant model, so the
 * saving() observers stamp approved_at on Approved rows — which is what
 * locks the entity. Approved unless $status says otherwise.
 *
 * @return array{user: User, participant: GameParticipant|CampaignParticipant}
 */
function agreementEditAddParticipant(Game|Campaign $entity, ParticipantStatus $status = ParticipantStatus::Approved, ?User $user = null): array
{
    $user ??= User::factory()->create();
    $attributes = [
        $entity instanceof Game ? 'game_id' : 'campaign_id' => $entity->id,
        'user_id' => $user->id,
        'status' => $status->value,
        'waitlisted_at' => $status === ParticipantStatus::Waitlisted ? now() : null,
    ];

    $participant = $entity instanceof Game
        ? GameParticipant::factory()->create($attributes)
        : CampaignParticipant::factory()->create($attributes);

    return ['user' => $user, 'participant' => $participant];
}

/**
 * The owner row plus ONE confirmed non-owner ($member, or a new user): the
 * entity is agreement-locked from here on. Returns the member.
 */
function agreementEditLock(Game|Campaign $entity, ?User $member = null): User
{
    $member ??= User::factory()->create();

    agreementEditAddOwnerRow($entity);
    agreementEditAddParticipant($entity, ParticipantStatus::Approved, $member);

    return $member;
}

/**
 * A change to one game field that DIFFERS from the agreementEditGame()
 * fixture — for probing which fields the gate refuses. Every Tier-1 field of
 * FieldTiers::GAME_TIERS needs an arm: adding one without a probe makes this
 * throw, which is the point.
 *
 * @return array<string, mixed>
 */
function agreementEditGameProbe(string $field): array
{
    return match ($field) {
        'game_systems' => ['game_systems' => [(string) Str::uuid()]],
        'game_system_id' => ['game_system_id' => (string) Str::uuid()],
        'game_type' => ['game_type' => GameType::Gathering->value],
        'date_time' => ['date_time' => now()->addDays(14)->startOfSecond()],
        'expected_duration' => ['expected_duration' => 4],
        'price' => ['price' => 12.5],
        'language' => ['language' => 'de'],
        'min_players' => ['min_players' => 3],
        'bench_mode' => ['bench_mode' => true],
        'max_players' => ['max_players' => 4], // a decrease: Tier 1
        'visibility' => ['visibility' => Visibility::Private->value], // public→private: Tier 1 (the probe fixture is public)
        default => throw new InvalidArgumentException("No game probe is defined for [{$field}]."),
    };
}

/**
 * A Tier-1 change for a campaign, differing from the agreementEditCampaign() fixture.
 *
 * @return array<string, mixed>
 */
function agreementEditCampaignProbe(string $field): array
{
    return match ($field) {
        'session_duration' => ['session_duration' => 4],
        'time_of_day' => ['time_of_day' => '20:00'],
        'price_per_session' => ['price_per_session' => 12.5],
        'language' => ['language' => 'de'],
        'min_players' => ['min_players' => 3],
        'recurrence' => ['recurrence' => 'monthly'],
        default => throw new InvalidArgumentException("No Tier-1 campaign change is defined for [{$field}]."),
    };
}

/**
 * Run an edit that MUST be refused by the lock and hand back its exception.
 * An accepted edit fails the test here, at the call site.
 *
 * @param  array<string, mixed>  $changes
 */
function agreementEditRefusal(AgreementEditService $service, Game|Campaign $entity, array $changes, User $actor): ValidationException
{
    try {
        $service->apply($entity, $changes, $actor);
    } catch (ValidationException $exception) {
        return $exception;
    }

    throw new AssertionError('The edit went through although the entity is agreement-locked.');
}

/**
 * Record every updating() a game or campaign fires from here on. Returns a
 * closure that hands back the recorded dirty fields, so a test can prove
 * that nothing was saved — `updated_at` cannot show that inside one second.
 *
 * @return Closure(): list<string>
 */
function agreementEditRecordWrites(): Closure
{
    $writes = [];

    foreach ([Game::class, Campaign::class] as $modelClass) {
        $modelClass::updating(function (Model $model) use (&$writes): void {
            $writes[] = $model::class.': '.implode(', ', array_keys($model->getDirty()));
        });
    }

    return function () use (&$writes): array {
        return $writes;
    };
}

/**
 * The GameUpdated / CampaignUpdated activity entries recorded for the entity
 * — the only ones this service writes. Participant rows log their own
 * activity types for the same subject, so a bare subject count is not zero.
 */
function agreementEditUpdateLogCount(Game|Campaign $entity): int
{
    return ActivityLog::query()
        ->where('subject_type', $entity->getMorphClass())
        ->where('subject_id', $entity->getKey())
        ->whereIn('event_type', [ActivityType::GameUpdated->value, ActivityType::CampaignUpdated->value])
        ->count();
}

// ═══════════════════════════════════════════════════════════
// Games — an open agreement
// ═══════════════════════════════════════════════════════════

describe('an unlocked game', function () {
    it('applies a date_time change and reports it in applied, whichever storage-ready shape the caller sends', function (string $shape) {
        $game = agreementEditGame($this->owner);
        agreementEditAddOwnerRow($game); // an owner-only roster is still open
        $newDate = now()->addDays(10)->setTime(19, 0, 0);
        $value = $shape === 'carbon'
            ? $newDate
            : $newDate->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $result = $this->service->apply($game, ['date_time' => $value], $this->owner);

        expect($result)->toBeInstanceOf(EditResult::class)
            ->and($result->wasLocked)->toBeFalse()
            ->and($result->applied)->toBe(['date_time' => $value])
            ->and($result->changedFields())->toBe(['date_time'])
            ->and($result->isEmpty())->toBeFalse()
            ->and($game->fresh()->date_time?->equalTo($newDate))->toBeTrue();
    })->with(['carbon' => ['carbon'], 'storage-ready string' => ['string']]);

    it('logs the activity and the update line, and re-syncs the roster’s calendars', function () {
        Queue::fake();
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = agreementEditGame($owner);
        GoogleCalendarConnection::factory()->create(['user_id' => $owner->id]);

        $this->service->apply($game, ['location' => ['details' => 'Front room']], $owner);

        $log = ActivityLog::query()
            ->where('subject_type', (new Game)->getMorphClass())
            ->where('subject_id', $game->id)
            ->where('event_type', ActivityType::GameUpdated->value)
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($owner->id)
            ->and($log->properties)->toBe(['changed_fields' => ['location']]);

        $line = $this->logRecord('Game updated');
        expect($line)->not->toBeNull()
            ->and($line['level_name'])->toBe('INFO')
            ->and($line['context'])->toBe(['game_id' => $game->id, 'owner_id' => $owner->id, 'changed_fields' => ['location']]);

        Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $owner->id
            && $job->entityUuid === $game->id
            && $job->entityType === SyncCalendarEventForUser::ENTITY_TYPE_GAME
            && $job->operation === CalendarSyncOperation::Upsert);
    });
});

// ═══════════════════════════════════════════════════════════
// Games — the agreement is locked
// ═══════════════════════════════════════════════════════════

describe('a locked game', function () {
    it('refuses a Tier-1 change whole: nothing written, nothing announced, the reason under the edit key', function (string $field) {
        $game = agreementEditGame($this->owner);
        agreementEditLock($game);
        $before = $game->fresh()->getAttributes();
        $recordWrites = agreementEditRecordWrites();

        $exception = agreementEditRefusal($this->service, $game, agreementEditGameProbe($field), $this->owner);

        expect($exception->errors())->toBe(['edit' => [__('games.error_agreement_locked')]])
            ->and($game->fresh()->getAttributes())->toBe($before)
            ->and($recordWrites())->toBe([])
            ->and(agreementEditUpdateLogCount($game))->toBe(0)
            ->and($this->logRecord('Game updated'))->toBeNull();

        Notification::assertNothingSent();

        // Structured warning with the field NAMES — and nothing but: an
        // attempted new date or price is not log content.
        $refusal = $this->logRecord('edit.rejected_locked');
        expect($refusal)->not->toBeNull()
            ->and($refusal['level_name'])->toBe('WARNING')
            ->and($refusal['context'])->toBe([
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'actor_id' => $this->owner->id,
                'fields' => [$field],
            ]);
    })->with([
        'date_time',
        'expected_duration',
        'price',
        'language',
        'min_players',
        'bench_mode',
        'game_system_id',
    ]);

    it('refuses a request that mixes a Tier-1 and a Tier-3 change whole — the free part is not applied either', function () {
        $game = agreementEditGame($this->owner);
        agreementEditLock($game);
        $recordWrites = agreementEditRecordWrites();

        agreementEditRefusal($this->service, $game, [
            'name' => 'Board night II',
            'date_time' => now()->addDays(21)->startOfSecond(),
        ], $this->owner);

        expect($game->fresh()->name)->toBe('Board night')
            ->and($recordWrites())->toBe([])
            ->and($this->logRecord('edit.rejected_locked')['context']['fields'])->toBe(['date_time']);

        Notification::assertNothingSent();
    });

    it('applies a Tier-2 change and tells the approved participant what changed', function (string $field, mixed $value) {
        $game = agreementEditGame($this->owner);
        $member = agreementEditLock($game);

        $result = $this->service->apply($game, [$field => $value], $this->owner);

        expect($result->applied)->toBe([$field => $value])
            ->and($result->wasLocked)->toBeTrue()
            ->and($game->fresh()->getAttribute($field))->toBe($value)
            ->and($this->logRecord('Game updated')['context']['changed_fields'])->toBe([$field]);

        Notification::assertSentTo($member, EntityUpdated::class, fn (EntityUpdated $notification) => $notification->changedColumns === [$field]);
    })->with([
        'the freeform location' => ['location', ['details' => 'Front room']],
        'arrival instructions' => ['location_instructions', 'Use the side entrance'],
        'safety rules' => ['safety_rules', ['comfort_notes' => 'No spiders']],
    ]);

    it('applies a Tier-3 change — a name edit goes through', function () {
        $game = agreementEditGame($this->owner);
        agreementEditLock($game);

        $result = $this->service->apply($game, ['name' => 'Board night II'], $this->owner);

        expect($result->applied)->toBe(['name' => 'Board night II'])
            ->and($result->wasLocked)->toBeTrue()
            ->and($game->fresh()->name)->toBe('Board night II');
    });

    it('refuses a capacity decrease and a public→private switch', function (string $field, mixed $value, array $overrides) {
        $game = agreementEditGame($this->owner, $overrides);
        agreementEditLock($game);
        $before = $game->fresh()->getAttributes();

        $exception = agreementEditRefusal($this->service, $game, [$field => $value], $this->owner);

        expect($exception->errors())->toBe(['edit' => [__('games.error_agreement_locked')]])
            ->and($game->fresh()->getAttributes())->toBe($before)
            ->and($this->logRecord('edit.rejected_locked')['context']['fields'])->toBe([$field]);

        Notification::assertNothingSent();
    })->with([
        'max_players decrease' => ['max_players', 4, []],
        'visibility public→private' => ['visibility', Visibility::Private->value, ['visibility' => Visibility::Public->value]],
    ]);

    it('accepts a capacity increase and a private→public switch', function (string $field, mixed $value, mixed $stored) {
        $game = agreementEditGame($this->owner);
        agreementEditLock($game);

        $result = $this->service->apply($game, [$field => $value], $this->owner);

        expect(array_keys($result->applied))->toBe([$field])
            ->and($result->wasLocked)->toBeTrue()
            ->and($game->fresh()->getAttribute($field))->toBe($stored)
            ->and($this->logRecord('edit.rejected_locked'))->toBeNull();
    })->with([
        'max_players increase' => ['max_players', 6, 6],
        'visibility private→public' => ['visibility', Visibility::Public->value, Visibility::Public],
    ]);

    it('treats a request that only re-states the stored values as a no-op — a Tier-1 value included', function () {
        // The edit-mode shape: the form re-submits EVERY field, on a locked
        // game. Restating a Tier-1 value is not an attempt to change it.
        $game = agreementEditGame($this->owner);
        agreementEditLock($game);
        $recordWrites = agreementEditRecordWrites();

        $result = $this->service->apply($game, [
            'date_time' => $game->date_time->format('Y-m-d H:i:s'),
            'expected_duration' => 2,
            'price' => 0,
            'language' => 'en',
            'min_players' => 2,
            'max_players' => '5',
            'visibility' => 'private',
            'game_system_id' => $game->gameSystems()->pluck('id')->first(),
            'name' => 'Board night',
            'location' => ['details' => 'Back room'],
            'location_instructions' => 'Ring the bell',
        ], $this->owner);

        expect($result->applied)->toBe([])
            ->and($result->isEmpty())->toBeTrue()
            ->and($result->wasLocked)->toBeTrue()
            ->and($recordWrites())->toBe([])
            ->and(agreementEditUpdateLogCount($game))->toBe(0)
            ->and($this->logRecord('Game updated'))->toBeNull()
            ->and($this->logRecord('edit.rejected_locked'))->toBeNull();

        Notification::assertNothingSent();
    });

    it('fills the seats a capacity increase opens from the waitlist, exactly like the capacity control', function () {
        $game = agreementEditGame($this->owner, ['max_players' => 3]);
        agreementEditAddOwnerRow($game);
        agreementEditAddParticipant($game);
        agreementEditAddParticipant($game); // full: owner + two confirmed
        $waitlisted = agreementEditAddParticipant($game, ParticipantStatus::Waitlisted);
        expect($game->fresh()->isAtCapacity())->toBeTrue()
            ->and($game->isAgreementLocked())->toBeTrue();

        $result = $this->service->apply($game, ['max_players' => 4], $this->owner);

        expect($result->applied)->toBe(['max_players' => 4])
            ->and($game->fresh()->max_players)->toBe(4)
            ->and($waitlisted['participant']->fresh()->status)->toBe(ParticipantStatus::Pending)
            ->and($waitlisted['participant']->fresh()->confirmation_expires_at)->not->toBeNull();
    });

    it('reads the lock live: a participant who joined after the owner loaded the game still refuses the edit', function () {
        $game = agreementEditGame($this->owner);
        $originalDate = $game->date_time;
        expect($game->isAgreementLocked())->toBeFalse();

        // Someone confirms while the owner's edit form is open.
        agreementEditAddParticipant($game);

        agreementEditRefusal($this->service, $game, ['date_time' => now()->addDays(21)->startOfSecond()], $this->owner);

        expect($game->fresh()->date_time?->equalTo($originalDate))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// Games — recipients, translatables, pivot keys, resilience
// ═══════════════════════════════════════════════════════════

describe('who learns about a game edit', function () {
    it('tells every approved participant except the owner — and nobody who only waits', function () {
        $game = agreementEditGame($this->owner);
        agreementEditAddOwnerRow($game);
        $approved = agreementEditAddParticipant($game);
        $secondApproved = agreementEditAddParticipant($game);
        $waitlisted = agreementEditAddParticipant($game, ParticipantStatus::Waitlisted);
        $pending = agreementEditAddParticipant($game, ParticipantStatus::Pending);

        $this->service->apply($game, ['name' => 'Board night II'], $this->owner);

        Notification::assertSentTo($approved['user'], EntityUpdated::class, 1);
        Notification::assertSentTo($secondApproved['user'], EntityUpdated::class, 1);
        Notification::assertNotSentTo($this->owner, EntityUpdated::class);
        Notification::assertNotSentTo($waitlisted['user'], EntityUpdated::class);
        Notification::assertNotSentTo($pending['user'], EntityUpdated::class);
    });

    it('never fails the edit when the notification fan-out throws', function () {
        $game = agreementEditGame($this->owner);
        agreementEditLock($game);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')->once()->andThrow(new RuntimeException('queue down'));
        $this->app->instance(NotificationService::class, $notifications);
        $service = app(AgreementEditService::class);

        $result = $service->apply($game, ['name' => 'Board night II'], $this->owner);

        expect($result->applied)->toBe(['name' => 'Board night II'])
            ->and($game->fresh()->name)->toBe('Board night II')
            ->and($this->logRecord('notification.game_updated_dispatch_failed')['context'])->toBe(['game_id' => $game->id, 'error' => 'queue down']);
    });
});

describe('translatable fields', function () {
    it('treats an unchanged translation map for name and description as a no-op: nothing saved, logged or sent', function (array $stored, array $submitted) {
        $game = agreementEditGame($this->owner, $stored);
        agreementEditLock($game);
        $recordWrites = agreementEditRecordWrites();

        $result = $this->service->apply($game, $submitted, $this->owner);

        expect($result->applied)->toBe([])
            ->and($recordWrites())->toBe([])
            ->and(agreementEditUpdateLogCount($game))->toBe(0)
            ->and($this->logRecord('Game updated'))->toBeNull();

        Notification::assertNothingSent();
    })->with([
        'both locales restated' => [
            [
                'name' => ['en' => 'Board night', 'de' => 'Brettspielabend'],
                'description' => ['en' => 'A friendly board night.', 'de' => 'Ein freundlicher Spieleabend.'],
            ],
            [
                'name' => ['de' => 'Brettspielabend', 'en' => 'Board night'],
                'description' => ['en' => 'A friendly board night.', 'de' => 'Ein freundlicher Spieleabend.'],
            ],
        ],
        'an empty stored locale, omitted in the map the edit mode builds' => [
            [
                'name' => ['en' => 'Board night', 'de' => ''],
                'description' => ['en' => 'A friendly board night.'],
            ],
            [
                'name' => ['en' => 'Board night'],
                'description' => ['en' => 'A friendly board night.'],
            ],
        ],
    ]);

    it('applies a map that changes one locale, leaves the other byte-identical, and announces the field once', function () {
        $game = agreementEditGame($this->owner, [
            'name' => ['en' => 'Board night', 'de' => 'Brettspielabend'],
            'description' => ['en' => 'A friendly board night.', 'de' => 'Ein freundlicher Spieleabend.'],
        ]);
        $member = agreementEditLock($game);

        $result = $this->service->apply($game, [
            'name' => ['en' => 'Board night', 'de' => 'Neuer Name'],
            'description' => ['en' => 'A friendly board night.', 'de' => 'Ein freundlicher Spieleabend.'],
        ], $this->owner);

        expect(array_keys($result->applied))->toBe(['name'])
            ->and($result->applied)->toBe(['name' => ['en' => 'Board night', 'de' => 'Neuer Name']]);

        // Read the stored column, not the accessor: jsonb re-orders object keys,
        // so the decoded map is the honest "what is stored".
        $storedName = json_decode((string) $game->fresh()->getRawOriginal('name'), true);
        ksort($storedName);
        expect($storedName)->toBe(['de' => 'Neuer Name', 'en' => 'Board night'])
            ->and($game->fresh()->getTranslation('description', 'de'))->toBe('Ein freundlicher Spieleabend.');

        Notification::assertSentTo($member, EntityUpdated::class, fn (EntityUpdated $notification) => $notification->changedColumns === ['name']);
        Notification::assertSentTo($member, EntityUpdated::class, 1);
    });

    it('writes a plain string to the current locale only — the inline edit’s shape', function () {
        $game = agreementEditGame($this->owner, ['name' => ['en' => 'Board night', 'de' => 'Brettspielabend']]);

        $result = $this->service->apply($game, ['name' => 'Game night'], $this->owner);

        expect($result->applied)->toBe(['name' => 'Game night'])
            ->and($game->fresh()->getTranslations('name'))->toEqual(['en' => 'Game night', 'de' => 'Brettspielabend']);
    });

    it('compares a plain string against the current locale with its fallback — never against the whole JSON', function () {
        // A German request on a game that only has an English name: the form
        // showed the fallback text, and re-submitting it is not an edit.
        app()->setLocale('de');
        $game = agreementEditGame($this->owner);
        $recordWrites = agreementEditRecordWrites();

        $result = $this->service->apply($game, ['name' => 'Board night', 'description' => 'A friendly board night.'], $this->owner);

        expect($result->applied)->toBe([])
            ->and($recordWrites())->toBe([]);
    });
});

describe('values a field cannot take', function () {
    it('throws for a key FieldTiers does not classify — never dropped, never treated as free', function (string $field) {
        $game = agreementEditGame($this->owner);
        agreementEditLock($game);
        $recordWrites = agreementEditRecordWrites();

        expect(fn () => $this->service->apply($game, [$field => 'x'], $this->owner))->toThrow(InvalidArgumentException::class);

        expect($recordWrites())->toBe([]);
        Notification::assertNothingSent();
    })->with(['status', 'owner_id', 'session_duration', 'comfort_notes']);

    it('throws for a shape its field cannot take', function (array $change) {
        $game = agreementEditGame($this->owner);
        $recordWrites = agreementEditRecordWrites();

        expect(fn () => $this->service->apply($game, $change, $this->owner))->toThrow(InvalidArgumentException::class);

        expect($recordWrites())->toBe([]);
    })->with([
        'an unknown visibility' => [['visibility' => 'banana']],
        'a non-numeric capacity' => [['max_players' => 'lots']],
        'a name given as a list' => [['name' => ['Board', 'night']]],
        'a name given as a number' => [['name' => 42]],
        'a cover image routed through the columns' => [['cover_image' => 'tmp/upload.png']],
    ]);

    it('reads null and an empty string as the same "not set" for a nullable text column', function (array $stored, mixed $submitted) {
        $game = agreementEditGame($this->owner, $stored);

        $result = $this->service->apply($game, ['location_instructions' => $submitted], $this->owner);

        expect($result->applied)->toBe([]);
    })->with([
        'null stored, empty string submitted' => [['location_instructions' => null], ''],
        'empty string stored, null submitted' => [['location_instructions' => ''], null],
    ]);
});

describe('the game system keys', function () {
    it('gate the system as Tier 1 but never write the pivot', function () {
        $game = agreementEditGame($this->owner);
        $currentSystemId = $game->gameSystems()->pluck('id')->first();
        $otherSystemId = GameSystem::factory()->create()->id;

        // An unchanged selection is a no-op: a form that always re-submits the
        // system must never trip anything.
        expect($this->service->apply($game, ['game_system_id' => $currentSystemId], $this->owner)->applied)->toBe([]);

        // A changed selection on an open agreement passes the gate; the pivot
        // is the calling surface's to sync, so it is neither written nor reported.
        $result = $this->service->apply($game, ['game_systems' => [$otherSystemId], 'name' => 'Board night II'], $this->owner);

        expect($result->applied)->toBe(['name' => 'Board night II'])
            ->and($result->wasLocked)->toBeFalse()
            ->and($game->fresh()->gameSystems()->pluck('id')->all())->toBe([$currentSystemId]);

        // A changed selection on a locked agreement is refused like every Tier-1 change…
        agreementEditAddParticipant($game);
        agreementEditRefusal($this->service, $game, ['game_system_id' => $otherSystemId], $this->owner);
        expect($this->logRecord('edit.rejected_locked')['context']['fields'])->toBe(['game_system_id']);

        // …while the unchanged one still passes and reports the lock.
        expect($this->service->apply($game, ['game_system_id' => $currentSystemId], $this->owner)->wasLocked)->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// Campaign parity
// ═══════════════════════════════════════════════════════════

describe('a campaign', function () {
    it('applies a Tier-1 change on an open agreement and logs it — with no calendar re-sync', function () {
        Queue::fake();
        $campaign = agreementEditCampaign($this->owner);
        agreementEditAddOwnerRow($campaign);

        $result = $this->service->apply($campaign, ['session_duration' => 4], $this->owner);

        expect($result->wasLocked)->toBeFalse()
            ->and($result->applied)->toBe(['session_duration' => 4])
            ->and($campaign->fresh()->session_duration)->toBe(4.0);

        $log = ActivityLog::query()
            ->where('subject_type', (new Campaign)->getMorphClass())
            ->where('subject_id', $campaign->id)
            ->where('event_type', ActivityType::CampaignUpdated->value)
            ->first();
        expect($log?->properties)->toBe(['changed_fields' => ['session_duration']]);

        $line = $this->logRecord('Campaign updated');
        expect($line)->not->toBeNull()
            ->and($line['context'])->toBe(['campaign_id' => $campaign->id, 'owner_id' => $this->owner->id, 'changed_fields' => ['session_duration']]);

        Queue::assertNotPushed(SyncCalendarEventForUser::class);
    });

    it('refuses a Tier-1 change once a member has confirmed, with the campaign’s own reason', function (string $field) {
        $campaign = agreementEditCampaign($this->owner);
        $member = agreementEditLock($campaign);
        $before = $campaign->fresh()->getAttributes();
        $recordWrites = agreementEditRecordWrites();

        $exception = agreementEditRefusal($this->service, $campaign, agreementEditCampaignProbe($field), $this->owner);

        expect($exception->errors())->toBe(['edit' => [__('campaigns.error_agreement_locked')]])
            ->and($campaign->fresh()->getAttributes())->toBe($before)
            ->and($recordWrites())->toBe([])
            ->and($this->logRecord('edit.rejected_locked')['context'])->toBe([
                'entity_type' => 'campaign',
                'entity_id' => $campaign->id,
                'actor_id' => $this->owner->id,
                'fields' => [$field],
            ]);

        Notification::assertNotSentTo($member, EntityUpdated::class);
        Notification::assertNothingSent();
    })->with(['session_duration', 'time_of_day', 'price_per_session', 'language', 'min_players', 'recurrence']);

    it('applies a Tier-2 change on a locked campaign and tells the approved member', function () {
        $campaign = agreementEditCampaign($this->owner);
        $member = agreementEditLock($campaign);

        $result = $this->service->apply($campaign, ['location_instructions' => 'Use the side entrance'], $this->owner);

        expect($result->applied)->toBe(['location_instructions' => 'Use the side entrance'])
            ->and($result->wasLocked)->toBeTrue()
            ->and($campaign->fresh()->location_instructions)->toBe('Use the side entrance')
            ->and($this->logRecord('Campaign updated')['context']['changed_fields'])->toBe(['location_instructions']);

        Notification::assertSentTo($member, EntityUpdated::class, fn (EntityUpdated $notification) => $notification->changedColumns === ['location_instructions']);
    });

    it('throws for a field campaigns do not have', function () {
        $campaign = agreementEditCampaign($this->owner);

        expect(fn () => $this->service->apply($campaign, ['date_time' => now()->addDays(3)], $this->owner))->toThrow(InvalidArgumentException::class);
    });

    it('locks the same two directions as a game', function () {
        $campaign = agreementEditCampaign($this->owner, ['max_players' => 3, 'visibility' => Visibility::Public->value]);
        agreementEditLock($campaign);

        agreementEditRefusal($this->service, $campaign, ['max_players' => 2], $this->owner);
        agreementEditRefusal($this->service, $campaign, ['visibility' => Visibility::Private->value], $this->owner);

        $result = $this->service->apply($campaign, ['max_players' => 4, 'visibility' => Visibility::Protected->value], $this->owner);

        expect(array_keys($result->applied))->toBe(['max_players', 'visibility'])
            ->and($campaign->fresh()->max_players)->toBe(4)
            ->and($campaign->fresh()->visibility)->toBe(Visibility::Protected);
    });
});

// ═══════════════════════════════════════════════════════════
// The tier inventory and the gate stay in step
// ═══════════════════════════════════════════════════════════

describe('the gate and FieldTiers', function () {
    it('refuses every Tier-1 game field on a locked game — read from the FieldTiers inventory, not a hand-kept list', function () {
        // Public on purpose: public→private is the only locked visibility direction.
        $game = agreementEditGame($this->owner, ['visibility' => Visibility::Public->value]);
        agreementEditLock($game);
        $refused = [];

        foreach ([...array_keys(FieldTiers::GAME_TIERS, FieldTiers::TIER_LOCKED, true), 'max_players', 'visibility'] as $field) {
            try {
                $this->service->apply($game, agreementEditGameProbe((string) $field), $this->owner);
            } catch (ValidationException) {
                $refused[] = $field;
            }
        }

        expect($refused)->toBe([
            'game_systems', 'game_system_id', 'game_type', 'date_time', 'expected_duration',
            'price', 'min_players', 'bench_mode', 'language', 'max_players', 'visibility',
        ]);
        expect(agreementEditUpdateLogCount($game))->toBe(0);
    });
});
