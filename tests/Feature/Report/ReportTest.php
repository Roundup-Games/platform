<?php

use App\Filament\Exports\MembershipExporter;
use App\Filament\Exports\EventAttendanceExporter;
use App\Filament\Pages\Reports\MembershipReport;
use App\Filament\Pages\Reports\EventAttendanceReport;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\MembershipType;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create([]);
    $this->admin->assignRole('Platform Admin');
});

// ── Membership Exporter ───────────────────────────────────────────

describe('MembershipExporter', function () {
    it('defines expected export columns', function () {
        $columns = MembershipExporter::getColumns();
        $names = collect($columns)->map->getName()->toArray();

        expect($names)->toContain('id', 'billable.name', 'billable.email', 'type', 'status', 'trial_ends_at', 'ends_at', 'created_at');
    });

    it('all export columns are ExportColumn instances', function () {
        $columns = MembershipExporter::getColumns();

        foreach ($columns as $column) {
            expect($column)->toBeInstanceOf(ExportColumn::class);
        }
    });

    it('has a model class', function () {
        expect(MembershipExporter::getModel())->toBeString();
    });

    it('generates a filename containing membership-report', function () {
        $export = new \Filament\Actions\Exports\Models\Export();
        $export->id = 99;
        $exporter = new MembershipExporter($export, [], []);

        expect($exporter->getFileName($export))->toContain('membership-report');
    });

    it('returns a completed notification body', function () {
        $method = new ReflectionMethod(MembershipExporter::class, 'getCompletedNotificationBody');
        $method->setAccessible(true);

        $export = Mockery::mock(\Filament\Actions\Exports\Models\Export::class)->makePartial();
        $export->successful_rows = 42;
        $export->shouldReceive('getFailedRowsCount')->andReturn(0);

        $body = $method->invoke(null, $export);
        expect($body)->toContain('42')->toContain('completed');
    });
});

// ── Event Attendance Exporter ─────────────────────────────────────

describe('EventAttendanceExporter', function () {
    it('defines expected export columns', function () {
        $columns = EventAttendanceExporter::getColumns();
        $names = collect($columns)->map->getName()->toArray();

        expect($names)->toContain(
            'id', 'event.name', 'user.name', 'user.email',
            'team.name', 'registration_type', 'status', 'payment_status', 'created_at',
        );
    });

    it('all export columns are ExportColumn instances', function () {
        $columns = EventAttendanceExporter::getColumns();

        foreach ($columns as $column) {
            expect($column)->toBeInstanceOf(ExportColumn::class);
        }
    });

    it('has EventRegistration as model', function () {
        expect(EventAttendanceExporter::getModel())->toBe(EventRegistration::class);
    });

    it('generates a filename containing event-attendance-report', function () {
        $export = new \Filament\Actions\Exports\Models\Export();
        $export->id = 42;
        $exporter = new EventAttendanceExporter($export, [], []);

        expect($exporter->getFileName($export))->toContain('event-attendance-report');
    });

    it('eager-loads relationships in modifyQuery', function () {
        $query = EventRegistration::query();
        $modified = EventAttendanceExporter::modifyQuery($query);

        $eagerLoads = $modified->getEagerLoads();
        expect(array_keys($eagerLoads))->toContain('event', 'user', 'team');
    });
});

// ── MembershipReport Page ─────────────────────────────────────────

describe('MembershipReport Page', function () {
    it('is in the Reports navigation group', function () {
        expect(MembershipReport::getNavigationGroup())->toBe('Reports');
    });

    it('has navigation label', function () {
        expect(MembershipReport::getNavigationLabel())->toBe('Memberships');
    });

    // smoke: admin-only access restriction on membership report
    it('restricts access to admin users only', function () {
        // Admin can access
        expect(MembershipReport::canAccess())->toBeFalse(); // no logged-in user in test

        $this->actingAs($this->admin);
        expect(MembershipReport::canAccess())->toBeTrue();

        $regularUser = User::factory()->create([]);
        $this->actingAs($regularUser);
        expect(MembershipReport::canAccess())->toBeFalse();
    })->group('smoke');

    it('renders the membership report page', function () {
        $this->actingAs($this->admin);
        $this->get('/admin/membership-report')->assertSuccessful();
    });
});

// ── EventAttendanceReport Page ────────────────────────────────────

describe('EventAttendanceReport Page', function () {
    it('is in the Reports navigation group', function () {
        expect(EventAttendanceReport::getNavigationGroup())->toBe('Reports');
    });

    it('has navigation label', function () {
        expect(EventAttendanceReport::getNavigationLabel())->toBe('Event Attendance');
    });

    it('restricts access to admin users only', function () {
        $this->actingAs($this->admin);
        expect(EventAttendanceReport::canAccess())->toBeTrue();

        $regularUser = User::factory()->create([]);
        $this->actingAs($regularUser);
        expect(EventAttendanceReport::canAccess())->toBeFalse();
    });

    it('renders the event attendance report page', function () {
        $this->actingAs($this->admin);
        $this->get('/admin/event-attendance-report')->assertSuccessful();
    });

    it('shows event registrations in the table', function () {
        $user = User::factory()->create(['name' => 'John Doe']);
        $event = Event::factory()->create(['name' => 'Spring Tournament', 'start_date' => '2026-06-01']);
        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'registration_type' => 'individual',
        ]);

        $this->actingAs($this->admin);
        $response = $this->get('/admin/event-attendance-report');
        $response->assertSuccessful();
        $response->assertSee('Spring Tournament');
        $response->assertSee('John Doe');
    });
});

// ── Integration: Exporters produce data ───────────────────────────

describe('Exporter data production', function () {
    it('membership exporter processes subscription records', function () {
        // Create a user with the customer/subscription tables via Paddle
        // This is a structural test — verify the exporter class is constructable
        $export = new \Filament\Actions\Exports\Models\Export();
        $exporter = new MembershipExporter($export, ['id' => 'ID', 'status' => 'Status'], []);

        expect($exporter)->toBeInstanceOf(MembershipExporter::class);
    });

    it('event attendance exporter processes registration records', function () {
        $user = User::factory()->create(['name' => 'Alice']);
        $event = Event::factory()->create(['name' => 'Summer League']);
        $reg = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'registration_type' => 'team',
        ]);

        $export = new \Filament\Actions\Exports\Models\Export();
        $columnMap = [
            'id' => 'ID',
            'event.name' => 'Event',
            'user.name' => 'User',
            'status' => 'Status',
        ];
        $exporter = new EventAttendanceExporter($export, $columnMap, []);
        $result = $exporter($reg);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(4); // 4 columns in columnMap
    });
});
