<?php

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\BadgeEventsRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\BadgesRelationManager;
use App\Models\BadgeDefinition;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\BadgeService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

//
// Per-user badge ops on UserResource (D186, spec §2.3): the Badges
// relation manager's shelf, manual grant and revoke with a required
// reason, and the full audit trail. Every mutation flows through
// BadgeService — the manager itself never writes badge rows.
//
// Shared Postgres testcontainer: bootstrap migrates once, transactions
// isolate — no RefreshDatabase.
//

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->member = User::factory()->create();

    Filament::setCurrentPanel('admin');

    $this->artisan('badges:sync')->assertSuccessful();
});

// ── Authorization (comp idiom) ────────────────────────

describe('UserResource badge admin gating', function () {
    test('hides grant and revoke from a Service Admin — panel admission is not badge-granting power', function () {
        $serviceAdmin = User::factory()->create();
        $serviceAdmin->assignRole('Service Admin');
        $serviceAdmin->unsetRelations();

        actingAs($serviceAdmin);

        expect($serviceAdmin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
            ->and($serviceAdmin->isAdmin())->toBeFalse();

        app(BadgeService::class)->award($this->member, 'debut');

        Livewire\Livewire::test(BadgesRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->assertOk()
            ->assertActionHidden(TestAction::make('grantBadge')->table())
            ->assertActionHidden(TestAction::make('revoke')->table(BadgeUser::query()->first()));
    });

    test('refuses a direct grant invocation from a Service Admin — the in-action gate creates nothing', function () {
        $serviceAdmin = User::factory()->create();
        $serviceAdmin->assignRole('Service Admin');
        $serviceAdmin->unsetRelations();

        actingAs($serviceAdmin);

        // Livewire can invoke an action without re-running visible() —
        // the in-action server-side gate is the defense (comp pattern:
        // resolve the closure and execute it, the forged-request path).
        $page = Livewire\Livewire::test(BadgesRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ]);
        $closure = $page->instance()->getTable()->getAction('grantBadge')->getActionFunction();

        expect($closure)->not->toBeNull();

        $closure($page->instance(), ['badge' => 'debut', 'tier' => null, 'reason' => 'should never land']);

        expect(BadgeUser::query()->count())->toBe(0)
            ->and(BadgeEvent::query()->count())->toBe(0);
    });
});

// ── Grant ─────────────────────────────────────────────

describe('UserResource badge grant', function () {
    test('refuses a grant without a reason', function () {
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgesRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->callAction(TestAction::make('grantBadge')->table(), ['badge' => 'debut'])
            ->assertHasActionErrors(['reason']);

        expect(BadgeUser::query()->count())->toBe(0)
            ->and(BadgeEvent::query()->count())->toBe(0);
    });

    test('grants a badge with source manual and a full audit row — the slice demo', function () {
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgesRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->callAction(TestAction::make('grantBadge')->table(), [
                'badge' => 'debut',
                'reason' => 'Community recognition: ran the city launch table',
            ])
            ->assertHasNoActionErrors();

        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        $hold = BadgeUser::query()
            ->whereBelongsTo($this->member)
            ->whereBelongsTo($debut)
            ->firstOrFail();

        $event = BadgeEvent::query()->firstOrFail();

        expect($hold->source)->toBe(BadgeSource::Manual)
            ->and($event->badge_user_id)->toBe($hold->id)
            ->and($event->action)->toBe(BadgeEventAction::Awarded)
            ->and($event->source)->toBe(BadgeSource::Manual)
            ->and($event->actor_id)->toBe($this->platformAdmin->id)
            ->and($event->reason)->toBe('Community recognition: ran the city launch table');
    });

    test('grants a tiered badge at the selected tier', function () {
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgesRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->callAction(TestAction::make('grantBadge')->table(), [
                'badge' => 'supporting_member',
                'tier' => 'year_1',
                'reason' => 'Manual tenure correction',
            ])
            ->assertHasNoActionErrors();

        $supporting = BadgeDefinition::query()->where('key', 'supporting_member')->firstOrFail();

        $hold = BadgeUser::query()
            ->whereBelongsTo($this->member)
            ->whereBelongsTo($supporting)
            ->firstOrFail();

        expect($hold->tier)->toBe('year_1')
            ->and($hold->source)->toBe(BadgeSource::Manual);
    });

    test('refuses a stray tier on a tierless badge', function () {
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgesRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->callAction(TestAction::make('grantBadge')->table(), [
                'badge' => 'debut',
                'tier' => 'year_1',
                'reason' => 'Stray tier attempt',
            ])
            ->assertHasActionErrors(['badge']);

        expect(BadgeUser::query()->count())->toBe(0)
            ->and(BadgeEvent::query()->count())->toBe(0);
    });
});

// ── Revoke ────────────────────────────────────────────

describe('UserResource badge revoke', function () {
    test('revokes a held badge with a reason and stamps the audit trail', function () {
        app(BadgeService::class)->award($this->member, 'debut');

        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        $hold = BadgeUser::query()
            ->whereBelongsTo($this->member)
            ->whereBelongsTo($debut)
            ->firstOrFail();

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgesRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('revoke', $hold, ['reason' => 'Ineligible — eligibility error'])
            ->assertHasNoTableActionErrors();

        $audit = BadgeEvent::query()
            ->where('action', BadgeEventAction::Revoked)
            ->firstOrFail();

        expect($hold->fresh()->revoked_at)->not->toBeNull()
            ->and($hold->fresh()->revoked_reason)->toBe('Ineligible — eligibility error')
            ->and($audit->badge_user_id)->toBe($hold->id)
            ->and($audit->source)->toBe(BadgeSource::Manual)
            ->and($audit->actor_id)->toBe($this->platformAdmin->id)
            ->and($audit->reason)->toBe('Ineligible — eligibility error');
    });

    test('a repeat revoke on the same hold writes no second audit row', function () {
        $service = app(BadgeService::class);
        $service->award($this->member, 'debut');
        $service->revoke($this->member, 'debut', 'First revocation', $this->platformAdmin);

        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        $hold = BadgeUser::query()
            ->whereBelongsTo($this->member)
            ->whereBelongsTo($debut)
            ->firstOrFail();

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgesRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('revoke', $hold, ['reason' => 'Second attempt'])
            ->assertHasNoTableActionErrors();

        expect(BadgeEvent::query()->where('action', BadgeEventAction::Revoked)->count())->toBe(1);
    });

    test('revoking an unheld badge is a quiet no-op', function () {
        $revoked = app(BadgeService::class)->revoke(
            $this->member,
            'debut',
            'Never held',
            $this->platformAdmin,
        );

        expect($revoked)->toBeFalse()
            ->and(BadgeUser::query()->count())->toBe(0)
            ->and(BadgeEvent::query()->count())->toBe(0);
    });
});

// ── Audit trail ───────────────────────────────────────

describe('UserResource badge audit trail', function () {
    test('lists the full trail with actor and reason for the user', function () {
        $service = app(BadgeService::class);
        $service->award($this->member, 'debut');
        $service->revoke($this->member, 'debut', 'Audit trail check', $this->platformAdmin);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgeEventsRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords(BadgeEvent::query()->get())
            ->assertSee('Revoked');
    });

    test('keeps other users events out of the trail', function () {
        $other = User::factory()->create();
        app(BadgeService::class)->award($other, 'debut');

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgeEventsRelationManager::class, [
            'ownerRecord' => $this->member,
            'pageClass' => EditUser::class,
        ])
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords(BadgeEvent::query()->get());
    });
});
