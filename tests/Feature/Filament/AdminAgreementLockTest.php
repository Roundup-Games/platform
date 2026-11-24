<?php

use App\Enums\ActivityType;
use App\Enums\GameType;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Filament\Resources\CampaignResource\Pages\EditCampaign;
use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\EntityUpdated;
use App\Services\NotificationService;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Helpers\TestablePostHogClient;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

//
// M084/S04 — staff are bound by the agreement lock on the IDENTITY fields only,
// and the admin edit path notifies participants (decision D316).
//
// The Filament "disabled" state is a client-side courtesy: every refusal here
// is a FORGED submit (Livewire sets the field directly), so each case proves
// the server-side guard, not the greyed-out control.
//

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    $this->owner = User::factory()->create();
    $this->participant = User::factory()->create();

    Filament::setCurrentPanel('admin');
});

/** A single-system game owned by $owner, saving clean in the admin form. */
function adminLockGame(User $owner, GameSystem $system, GameType $type = GameType::BoardGame): Game
{
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'game_type' => $type->value,
        'name' => ['en' => 'Agreement lock fixture'],
        'date_time' => now()->addDays(7),
        // Multiples of the admin form's step(): the fixture must save clean,
        // so a refusal can only ever come from the agreement lock.
        'expected_duration' => 2,
        'price' => 0,
    ]);

    $game->gameSystems()->sync([$system->id]);

    return $game;
}

/**
 * A campaign owned by $owner, saving clean in the admin form. $attributes
 * override the fixture (a multi-select can only be CLEARED by a record that
 * holds a value).
 *
 * @param  array<string, mixed>  $attributes
 */
function adminLockCampaign(User $owner, GameSystem $system, array $attributes = []): Campaign
{
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Agreement lock campaign'],
        'session_duration' => 2,
        'price_per_session' => 0,
        ...$attributes,
    ]);

    $campaign->gameSystems()->sync([$system->id]);

    return $campaign;
}

/**
 * Give the entity its first confirmed non-owner participant — the agreement
 * locks for good (the saving observer stamps approved_at).
 */
function adminLockConfirmParticipant(Game|Campaign $entity, User $participant): void
{
    $attributes = [
        'user_id' => $participant->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ];

    if ($entity instanceof Game) {
        GameParticipant::create(['game_id' => $entity->id, ...$attributes]);

        return;
    }

    CampaignParticipant::create(['campaign_id' => $entity->id, ...$attributes]);
}

/** The system ids currently on the entity↔system pivot, sorted. @return list<string> */
function adminLockSystemIds(Game|Campaign $entity): array
{
    return collect($entity->gameSystems()->get()->modelKeys())
        ->map(fn ($id): string => (string) $id)
        ->sort()
        ->values()
        ->all();
}

/**
 * Run $callback and return every INSERT/UPDATE/DELETE it issued against the
 * entity↔system pivot tables.
 *
 * The suite wraps each test in a transaction, so "the pivot is unchanged"
 * alone would also pass for a write that was merely rolled back. The admin
 * panel runs WITHOUT a database transaction, so a forged identity must never
 * issue a pivot write in the first place — this is the assertion for that.
 *
 * @param  callable(): mixed  $callback
 * @return list<string>
 */
function adminLockPivotWrites(callable $callback): array
{
    $writes = [];

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^(insert|update|delete)\b/i', $query->sql) === 1
            && (str_contains($query->sql, 'game_game_system') || str_contains($query->sql, 'campaign_game_system'))) {
            $writes[] = $query->sql;
        }
    });

    $callback();

    return $writes;
}

describe('admin edits of an agreement-locked game', function () {
    test('the game system and game type controls are disabled, everything else stays editable', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormFieldDisabled('game_system_id')
            ->assertFormFieldDisabled('game_type')
            ->assertFormFieldEnabled('date_time')
            ->assertFormFieldEnabled('price')
            ->assertFormFieldEnabled('visibility');
    });

    test('a locked Gathering has its multi-system picker disabled', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create(), GameType::Gathering);
        adminLockConfirmParticipant($game, $this->participant);

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormFieldDisabled('gameSystems');
    });

    test('an unlocked game keeps every identity field editable', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormFieldEnabled('game_system_id')
            ->assertFormFieldEnabled('game_type');
    });

    test('a forged game system change is refused and the pivot is untouched', function () {
        [$system, $intruder] = GameSystem::factory()->count(2)->create();
        $game = adminLockGame($this->owner, $system);
        adminLockConfirmParticipant($game, $this->participant);

        Notification::fake();
        $this->captureLogRecords();

        $writes = adminLockPivotWrites(fn () => Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.game_system_id', $intruder->id)
            // A legitimate correction riding along with the forgery.
            ->set('data.price', '42.00')
            ->call('save')
            ->assertHasErrors(['data.game_system_id' => __('games.error_agreement_locked')]));

        expect($writes)->toBe([])
            ->and(adminLockSystemIds($game))->toBe([$system->id]);
        Notification::assertNothingSent();

        // The refusal is for the whole save: none of it is written or logged.
        expect((float) $game->fresh()->price)->toBe((float) $game->price);
        expect(adminLockActivityLogs($game, ActivityType::GameUpdated))->toBeEmpty();

        // Logged like every owner-path refusal, with the surface named.
        expect($this->logRecord('edit.rejected_locked')['context'])->toMatchArray([
            'surface' => 'filament_admin',
            'actor_id' => $this->admin->id,
            'fields' => ['game_system_id'],
        ]);
    });

    test('a forged game type change is refused', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.game_type', GameType::Ttrpg->value)
            ->call('save')
            ->assertHasErrors(['data.game_type' => __('games.error_agreement_locked')]);

        expect($game->fresh()->game_type)->toBe(GameType::BoardGame);
        Notification::assertNothingSent();
    });

    test('a forged game systems change on a locked Gathering is refused and never reaches the pivot', function () {
        [$first, $second, $intruder] = GameSystem::factory()->count(3)->create();
        $game = adminLockGame($this->owner, $first, GameType::Gathering);
        $game->gameSystems()->sync([$first->id, $second->id]);
        adminLockConfirmParticipant($game, $this->participant);

        Notification::fake();

        $writes = adminLockPivotWrites(fn () => Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.gameSystems', [$first->id, $intruder->id])
            ->call('save')
            ->assertHasErrors(['data.gameSystems' => __('games.error_agreement_locked')]));

        expect($writes)->toBe([])
            ->and(adminLockSystemIds($game))->toBe(collect([$first->id, $second->id])->sort()->values()->all());
        Notification::assertNothingSent();
    });

    test('a date correction on a locked game is saved and the approved participant is told what changed', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.date_time', '2035-03-04 19:30:00')
            ->call('save')
            ->assertHasNoErrors();

        expect(adminLockWallClock($game))->toBe('2035-03-04 19:30:00');

        // Exactly the one column: the fixture is English-only, so this also
        // pins that the empty `de` keys the admin form writes are not
        // announced as a name/description change.
        Notification::assertSentTo(
            $this->participant,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => $notification->changedColumns === ['date_time'],
        );
        Notification::assertNotSentTo($this->owner, EntityUpdated::class);
        Notification::assertNotSentTo($this->admin, EntityUpdated::class);
    });

    test('saving a locked game without touching its identity succeeds', function () {
        $system = GameSystem::factory()->create();
        $game = adminLockGame($this->owner, $system);
        adminLockConfirmParticipant($game, $this->participant);

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->call('save')
            ->assertHasNoErrors();

        expect($game->fresh()->game_type)->toBe(GameType::BoardGame)
            ->and(adminLockSystemIds($game))->toBe([$system->id]);
    });

    test('saving a locked Gathering without touching its systems succeeds', function () {
        $system = GameSystem::factory()->create();
        $game = adminLockGame($this->owner, $system, GameType::Gathering);
        adminLockConfirmParticipant($game, $this->participant);

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->call('save')
            ->assertHasNoErrors();

        expect(adminLockSystemIds($game))->toBe([$system->id]);
    });

    test('an unlocked game still accepts a game system change', function () {
        [$system, $replacement] = GameSystem::factory()->count(2)->create();
        $game = adminLockGame($this->owner, $system);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.game_system_id', $replacement->id)
            ->set('data.price', '5.00')
            ->call('save')
            ->assertHasNoErrors();

        expect(adminLockSystemIds($game))->toBe([$replacement->id]);
        Notification::assertNothingSent();

        // The pivot is not a row column: of the two edits only the price is logged.
        adminLockAssertLoggedOnce($game, ActivityType::GameUpdated, $this->admin, ['price']);
    });

    test('each non-identity correction on a locked game is stored, announced once and logged with the admin as actor', function (string $column, mixed $value, callable $isStored) {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set("data.{$column}", $value)
            ->call('save')
            ->assertHasNoErrors();

        expect($isStored($game->fresh()))->toBeTrue();

        // Exactly the one column: a real text edit is announced, the empty `de`
        // key the admin form adds beside it is not.
        expect(adminLockAnnouncedColumns($this->participant))->toBe([[$column]]);
        Notification::assertNotSentTo($this->owner, EntityUpdated::class);

        adminLockAssertLoggedOnce($game, ActivityType::GameUpdated, $this->admin, [$column]);
    })->with(adminLockGameCorrections());

    test('a combined correction on a locked game is announced once, naming every column that changed', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.price', '9.99')
            ->set('data.language', 'de')
            ->set('data.visibility', 'protected')
            ->set('data.date_time', '2035-05-06 18:00:00')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $game->fresh();

        expect((float) $fresh->price)->toBe(9.99)
            ->and($fresh->language)->toBe('de')
            ->and($fresh->visibility?->value)->toBe('protected');

        $expected = ['date_time', 'language', 'price', 'visibility'];

        expect(adminLockAnnouncedColumns($this->participant))->toBe([$expected]);
        Notification::assertNotSentTo($this->owner, EntityUpdated::class);

        adminLockAssertLoggedOnce($game, ActivityType::GameUpdated, $this->admin, $expected);
    });

    test('a save that changes nothing notifies nobody and logs nothing', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertNothingSent();
        expect(adminLockActivityLogs($game, ActivityType::GameUpdated))->toBeEmpty();
    });

    test('an admin edit is logged with the admin as actor and names the changed column', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.date_time', '2035-03-04 19:30:00')
            ->call('save')
            ->assertHasNoErrors();

        $logs = adminLockActivityLogs($game, ActivityType::GameUpdated);

        expect($logs)->toHaveCount(1)
            ->and((string) $logs->first()->user_id)->toBe($this->admin->id)
            ->and($logs->first()->properties['changed_fields'])->toBe(['date_time']);
    });

    test('a notification dispatch failure never fails the admin save', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        // The fan-out is the owner path's: it logs and stops, the edit stands.
        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->andThrow(new RuntimeException('queue down'));

        $this->captureLogRecords();

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.date_time', '2035-03-04 19:30:00')
            ->call('save')
            ->assertHasNoErrors();

        expect(adminLockWallClock($game))->toBe('2035-03-04 19:30:00');

        expect($this->logRecord('notification.game_updated_dispatch_failed')['context'])
            ->toBe(['game_id' => $game->id, 'error' => 'queue down']);
    });

    test('the admin edit is forwarded to PostHog as game.updated with the admin as the distinct id', function () {
        $game = adminLockGame($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($game, $this->participant);

        Config::set('posthog.enabled', true);
        Config::set('posthog.api_key', 'phc_test_key');
        $client = new TestablePostHogClient;
        $this->app->instance(PostHogClient::class, $client);

        $consent = $this->mock(PostHogConsentChecker::class);
        $consent->shouldReceive('hasAnalyticsConsent')->andReturn(true);
        $this->app->instance(PostHogConsentChecker::class, $consent);

        Livewire::actingAs($this->admin)
            ->test(EditGame::class, ['record' => $game->getRouteKey()])
            ->set('data.date_time', '2035-03-04 19:30:00')
            ->call('save')
            ->assertHasNoErrors();

        $events = array_values(array_filter(
            $client->capturedCalls,
            fn (array $call) => $call['event'] === 'game.updated',
        ));

        expect($events)->toHaveCount(1)
            ->and($events[0]['distinctId'])->toBe($this->admin->id)
            ->and($events[0]['properties']['changed_fields'])->toBe(['date_time']);
    });
});

describe('admin edits of an agreement-locked campaign', function () {
    test('the game systems picker is disabled once locked and stays enabled before', function () {
        $system = GameSystem::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(EditCampaign::class, ['record' => adminLockCampaign($this->owner, $system)->getRouteKey()])
            ->assertFormFieldEnabled('gameSystems');

        $locked = adminLockCampaign($this->owner, $system);
        adminLockConfirmParticipant($locked, $this->participant);

        Livewire::actingAs($this->admin)
            ->test(EditCampaign::class, ['record' => $locked->getRouteKey()])
            ->assertFormFieldDisabled('gameSystems');
    });

    test('a forged game systems change is refused and never reaches the pivot', function () {
        [$system, $intruder] = GameSystem::factory()->count(2)->create();
        $campaign = adminLockCampaign($this->owner, $system);
        adminLockConfirmParticipant($campaign, $this->participant);

        Notification::fake();
        $this->captureLogRecords();

        $writes = adminLockPivotWrites(fn () => Livewire::actingAs($this->admin)
            ->test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->set('data.gameSystems', [$system->id, $intruder->id])
            ->call('save')
            ->assertHasErrors(['data.gameSystems' => __('campaigns.error_agreement_locked')]));

        expect($writes)->toBe([])
            ->and(adminLockSystemIds($campaign))->toBe([$system->id]);
        Notification::assertNothingSent();

        expect($this->logRecord('edit.rejected_locked')['context'])->toMatchArray([
            'surface' => 'filament_admin',
            'actor_id' => $this->admin->id,
            'fields' => ['game_systems'],
        ]);
    });

    test('a session-duration correction on a locked campaign is saved, logged and announced', function () {
        $campaign = adminLockCampaign($this->owner, GameSystem::factory()->create());
        adminLockConfirmParticipant($campaign, $this->participant);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->set('data.session_duration', '8.5')
            ->call('save')
            ->assertHasNoErrors();

        expect((float) $campaign->fresh()->session_duration)->toBe(8.5);

        Notification::assertSentTo(
            $this->participant,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => $notification->changedColumns === ['session_duration'],
        );
        Notification::assertNotSentTo($this->owner, EntityUpdated::class);

        $logs = adminLockActivityLogs($campaign, ActivityType::CampaignUpdated);

        expect($logs)->toHaveCount(1)
            ->and((string) $logs->first()->user_id)->toBe($this->admin->id)
            ->and($logs->first()->properties['changed_fields'])->toBe(['session_duration']);
    });

    test('each non-identity correction on a locked campaign is stored, announced once and logged with the admin as actor', function (string $column, mixed $value, array $fixture, callable $isStored) {
        $campaign = adminLockCampaign($this->owner, GameSystem::factory()->create(), $fixture);
        adminLockConfirmParticipant($campaign, $this->participant);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->set("data.{$column}", $value)
            ->call('save')
            ->assertHasNoErrors();

        expect($isStored($campaign->fresh()))->toBeTrue();

        // Exactly the one column: clearing flags the campaign held is an edit,
        // and a real text edit is announced while the empty `de` key the admin
        // form writes beside it is not.
        expect(adminLockAnnouncedColumns($this->participant))->toBe([[$column]]);
        Notification::assertNotSentTo($this->owner, EntityUpdated::class);

        adminLockAssertLoggedOnce($campaign, ActivityType::CampaignUpdated, $this->admin, [$column]);
    })->with(adminLockCampaignCorrections());

    test('saving a locked campaign without touching its systems succeeds and announces nothing', function () {
        $system = GameSystem::factory()->create();
        $campaign = adminLockCampaign($this->owner, $system);
        adminLockConfirmParticipant($campaign, $this->participant);

        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->call('save')
            ->assertHasNoErrors();

        expect(adminLockSystemIds($campaign))->toBe([$system->id]);
        Notification::assertNothingSent();
        expect(adminLockActivityLogs($campaign, ActivityType::CampaignUpdated))->toBeEmpty();
    });

    test('an unlocked campaign still accepts a game systems change', function () {
        [$system, $replacement] = GameSystem::factory()->count(2)->create();
        $campaign = adminLockCampaign($this->owner, $system);

        Livewire::actingAs($this->admin)
            ->test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->set('data.gameSystems', [$replacement->id])
            ->call('save')
            ->assertHasNoErrors();

        expect(adminLockSystemIds($campaign))->toBe([$replacement->id]);
    });
});

/** The stored start rendered on the admin's wall clock — the way the form shows it. */
function adminLockWallClock(Game $game): string
{
    return $game->fresh()->date_time->copy()->setTimezone(community_timezone())->format('Y-m-d H:i:s');
}

/** Every activity log row of $type written about $subject. @return \Illuminate\Support\Collection<int, ActivityLog> */
function adminLockActivityLogs(Game|Campaign $subject, ActivityType $type): Collection
{
    return ActivityLog::query()
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->where('event_type', $type->value)
        ->get();
}

/**
 * Assert exactly one activity row of $type was written about $subject, with
 * $actor as its actor and naming exactly $changedColumns.
 *
 * @param  list<string>  $changedColumns
 */
function adminLockAssertLoggedOnce(Game|Campaign $subject, ActivityType $type, User $actor, array $changedColumns): void
{
    $logs = adminLockActivityLogs($subject, $type);

    expect($logs)->toHaveCount(1)
        ->and((string) $logs->first()->user_id)->toBe($actor->id)
        ->and(adminLockSorted($logs->first()->properties['changed_fields']))
        ->toBe(adminLockSorted($changedColumns));
}

/**
 * What was announced to the approved participant: one column list per
 * EntityUpdated sent to them. WHICH columns were named is the contract, their
 * order is not, so each list is sorted.
 *
 * @return list<list<string>>
 */
function adminLockAnnouncedColumns(User $participant): array
{
    return Notification::sent($participant, EntityUpdated::class)
        ->map(fn (EntityUpdated $notification): array => adminLockSorted($notification->changedColumns))
        ->all();
}

/** @param  list<string>  $columns  @return list<string> */
function adminLockSorted(array $columns): array
{
    return collect($columns)->sort()->values()->all();
}

/**
 * The non-identity columns an admin may still correct on a LOCKED game, each
 * with the value the admin form submits and the way the stored row proves it.
 * `date_time` has its own tests (the wall-clock case).
 *
 * `name` and `description` are the translatable columns: the admin form
 * writes each once per locale, so these pin that a REAL text edit is
 * announced while the empty `de` key written beside it is not.
 *
 * @return array<string, array{string, mixed, callable(Game): bool}>
 */
function adminLockGameCorrections(): array
{
    return [
        'price' => ['price', '12.50', fn (Game $game): bool => (float) $game->price === 12.50],
        'language' => ['language', 'de', fn (Game $game): bool => $game->language === 'de'],
        'visibility' => ['visibility', 'protected', fn (Game $game): bool => $game->visibility?->value === 'protected'],
        'expected duration' => ['expected_duration', '3', fn (Game $game): bool => (float) $game->expected_duration === 3.0],
        'description' => ['description', 'Staff corrected the blurb.', fn (Game $game): bool => $game->getTranslation('description', 'en') === 'Staff corrected the blurb.'],
        'name' => ['name', 'Renamed by staff', fn (Game $game): bool => $game->getTranslation('name', 'en') === 'Renamed by staff'],
    ];
}

/**
 * The non-identity columns an admin may still correct on a LOCKED campaign,
 * each as [column, submitted value, fixture attributes, proof]. The fixture
 * attributes give the column something to change FROM: a multi-select can
 * only be cleared by a campaign that holds flags. `session_duration` has its
 * own test.
 *
 * @return array<string, array{string, mixed, array<string, mixed>, callable(Campaign): bool}>
 */
function adminLockCampaignCorrections(): array
{
    $blurb = 'Staff corrected the blurb.';

    return [
        'max players' => ['max_players', '10', ['min_players' => 2, 'max_players' => 6], fn (Campaign $campaign): bool => (int) $campaign->max_players === 10],
        'price per session' => ['price_per_session', '7.50', [], fn (Campaign $campaign): bool => (float) $campaign->price_per_session === 7.50],
        'language' => ['language', 'de', [], fn (Campaign $campaign): bool => $campaign->language === 'de'],
        'visibility' => ['visibility', 'protected', [], fn (Campaign $campaign): bool => $campaign->visibility?->value === 'protected'],
        'vibe flags replaced' => ['vibe_flags', ['horror'], ['vibe_flags' => ['serious']], fn (Campaign $campaign): bool => $campaign->vibe_flags === ['horror']],
        'vibe flags cleared' => ['vibe_flags', [], ['vibe_flags' => ['serious']], fn (Campaign $campaign): bool => in_array($campaign->vibe_flags, [[], null], true)],
        'description' => ['description', $blurb, [], fn (Campaign $campaign): bool => $campaign->getTranslation('description', 'en') === $blurb],
        'name' => ['name', 'Renamed by staff', [], fn (Campaign $campaign): bool => $campaign->getTranslation('name', 'en') === 'Renamed by staff'],
    ];
}
