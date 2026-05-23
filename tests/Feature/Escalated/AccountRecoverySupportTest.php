<?php

namespace Tests\Feature;

use App\Models\User;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccountRecoverySupportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Department $accountSupportDept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->accountSupportDept = Department::factory()->create(['name' => 'Account Support']);
        Department::factory()->create(['name' => 'Contact']);
        Department::factory()->create(['name' => 'Billing']);
        Tag::factory()->create(['name' => 'account-recovery', 'color' => '#2563EB']);
    }

    /** @test */
    public function authenticated_user_can_submit_account_support_ticket(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/support/account', [
                'subject' => 'Cannot access my account',
                'description' => 'I am locked out and need help.',
                'issueType' => 'account_access',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $ticket = Ticket::where('ticket_type', 'account_recovery')
            ->where('requester_id', $this->user->id)->first();

        $this->assertNotNull($ticket);
        $this->assertEquals('Cannot access my account', $ticket->subject);
        $this->assertEquals($this->accountSupportDept->id, $ticket->department_id);
        $this->assertEquals('account_access', $ticket->metadata['issue_type']);
    }

    /** @test */
    public function account_support_ticket_applies_tag(): void
    {
        $this->actingAs($this->user)
            ->post('/support/account', [
                'subject' => 'Need help',
                'description' => 'Please help me.',
                'issueType' => 'other',
            ]);

        $ticket = Ticket::where('ticket_type', 'account_recovery')->first();
        $this->assertNotNull($ticket);
        $ticket->load('tags');
        $this->assertTrue($ticket->tags->contains('name', 'account-recovery'));
    }

    /** @test */
    public function account_support_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)->post('/support/account', []);
        $response->assertSessionHasErrors(['subject', 'description', 'issueType']);
    }

    /** @test */
    public function guest_cannot_access_account_support_page(): void
    {
        $response = $this->get('/support/account');
        $response->assertRedirect(route('login'));
    }

    /** @test */
    public function contact_form_with_account_recovery_category_routes_to_account_support(): void
    {
        $response = $this->post('/contact', [
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'category' => 'account_recovery',
            'message' => 'I lost access to my account.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $ticket = Ticket::where('ticket_type', 'account_recovery')->first();
        $this->assertNotNull($ticket);
        $this->assertEquals($this->accountSupportDept->id, $ticket->department_id);
        $this->assertEquals('guest@example.com', $ticket->guest_email);
    }

    /** @test */
    public function contact_form_without_category_routes_to_contact_department(): void
    {
        $contactDept = Department::where('name', 'Contact')->first();
        $this->post('/contact', [
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'message' => 'General question.',
        ]);

        $ticket = Ticket::whereNull('ticket_type')->first();
        $this->assertNotNull($ticket);
        $this->assertEquals($contactDept->id, $ticket->department_id);
    }
}
