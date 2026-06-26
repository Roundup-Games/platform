<?php

namespace Tests\Feature\Listeners;

use App\Models\User;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Notifications\TicketStatusChangedNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Verifies the SuppressAutomatedTicketStatusNotifications listener.
 *
 * System-initiated ticket status changes (no human causer) — e.g. the nightly
 * escalated:close-resolved archival job — must not send customer-facing
 * "Status Updated" notifications. Human-initiated changes still notify.
 */
class SuppressAutomatedTicketStatusNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $this->seed(EscalatedSetupSeeder::class);
        $this->department = Department::where('name', 'Contact')->firstOrFail();
    }

    private function createTicket(): Ticket
    {
        return Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'Help with my account',
            'description' => 'I need help.',
            'status' => TicketStatus::Resolved->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'question',
            'channel' => 'web',
            'metadata' => [],
        ]);
    }

    public function test_system_initiated_close_suppresses_status_notification(): void
    {
        // No causer = system-initiated (escalated:close-resolved archival).
        $ticket = $this->createTicket();
        $ticket->markClosed(null);

        $count = $this->user->notifications()
            ->where('type', TicketStatusChangedNotification::class)
            ->count();

        $this->assertEquals(0, $count, 'System-initiated close must not notify the customer.');
    }

    public function test_human_initiated_close_still_sends_status_notification(): void
    {
        $ticket = $this->createTicket();
        $ticket->markClosed($this->user);

        $count = $this->user->notifications()
            ->where('type', TicketStatusChangedNotification::class)
            ->count();

        $this->assertNotEquals(0, $count, 'Human-initiated close must still notify.');
    }
}
