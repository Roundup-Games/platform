<?php

use App\Filament\Exports\MembershipExporter;
use App\Filament\Exports\EventAttendanceExporter;
use App\Filament\Pages\Reports\MembershipReport;
use App\Filament\Pages\Reports\EventAttendanceReport;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create([]);
    $this->admin->assignRole('Platform Admin');
});

// ── EventAttendanceExporter ─────────────────────────────────────

describe('EventAttendanceExporter', function () {
    it('has EventRegistration as model', function () {
        expect(EventAttendanceExporter::getModel())->toBe(EventRegistration::class);
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
        $event = Event::factory()->create(['name' => 'Spring Tournament', 'start_date' => now()->addMonth()->format('Y-m-d')]);
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
