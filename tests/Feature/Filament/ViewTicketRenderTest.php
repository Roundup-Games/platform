<?php

use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\User;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// ViewTicket render smoke tests (M058/S04).
//
// Regression guard for the confirmed live bug: ViewTicket.php:34 imported
// Filament\Forms\Components\Section (v3 path, absent in Filament v5.7.1),
// so rendering any ticket with linked entities/metadata threw a class-not-
// found fatal. The 3 Escalated tests used reflection on private methods and
// never rendered the page, so the bug shipped. Fixed by switching to the
// v5 path Filament\Schemas\Components\Section. These tests render the page
// for multiple metadata types to exercise each build*Section() builder.
//

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->department = Department::firstOrCreate(
        ['name' => 'Smoke Test Department'],
        ['slug' => 'smoke-test-department', 'is_active' => true]
    );
    Filament::setCurrentPanel('admin');
});

function createTicketWithMetadata(Department $department, User $user, string $type, array $metadata): Ticket
{
    return Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $user->id,
        'subject' => 'Smoke test ticket',
        'description' => 'Render smoke',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department->id,
        'ticket_type' => $type,
        'channel' => TicketChannel::Web->value,
        'metadata' => $metadata,
    ]);
}

it('renders the ViewTicket page without a fatal for a game_system_request ticket', function () {
    actingAs($this->platformAdmin);

    $ticket = createTicketWithMetadata($this->department, $this->platformAdmin, 'game_system_request', [
        'game_system_request' => true,
        'bgg_url' => null,
        'publisher' => null,
        'designer' => null,
        'game_system_type' => 'boardgame',
        'game_system_id' => null,
    ]);

    get("/admin/tickets/{$ticket->getRouteKey()}")
        ->assertSuccessful();
})->group('smoke');

it('renders the ViewTicket page without a fatal for a content_report ticket', function () {
    actingAs($this->platformAdmin);

    $ticket = createTicketWithMetadata($this->department, $this->platformAdmin, 'content_report', [
        'schema' => 'content_report/v1',
        'actor' => ['type' => 'user', 'id' => $this->platformAdmin->id, 'name' => $this->platformAdmin->name],
        'action' => 'request',
        'entities' => [],
    ]);

    get("/admin/tickets/{$ticket->getRouteKey()}")
        ->assertSuccessful();
})->group('smoke');

it('renders the ViewTicket page without a fatal for a review_report ticket', function () {
    actingAs($this->platformAdmin);

    $ticket = createTicketWithMetadata($this->department, $this->platformAdmin, 'review_report', [
        'schema' => 'review_report/v1',
        'review_id' => null,
        'reason' => 'harassment',
    ]);

    get("/admin/tickets/{$ticket->getRouteKey()}")
        ->assertSuccessful();
})->group('smoke');
