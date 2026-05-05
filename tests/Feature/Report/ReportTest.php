<?php

use App\Filament\Exports\EventAttendanceExporter;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create([]);
    $this->admin->assignRole('Platform Admin');
});

// ── EventAttendanceExporter ─────────────────────────────────────

// ═══════════════════════════════════════════════════════════
// Integration: Exporters produce data
// ═══════════════════════════════════════════════════════════

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
