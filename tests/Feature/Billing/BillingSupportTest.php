<?php

namespace Tests\Feature;

use App\Http\Controllers\PaddleWebhookController;
use App\Models\User;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Department $billingDept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['paddle_id' => 'test_customer_123']);
        $this->billingDept = Department::factory()->create(['name' => 'Billing']);
        Tag::factory()->create(['name' => 'billing-support', 'color' => '#0891B2']);
        Tag::factory()->create(['name' => 'payment-failure', 'color' => '#DC2626']);
    }

    /** @test */
    public function authenticated_user_can_submit_billing_support_ticket(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/support/billing', [
                'subject' => 'Payment failed',
                'description' => 'My payment was declined.',
                'issueType' => 'payment_issue',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $ticket = Ticket::where('ticket_type', 'billing_support')
            ->where('requester_id', $this->user->id)->first();

        $this->assertNotNull($ticket);
        $this->assertEquals('Payment failed', $ticket->subject);
        $this->assertEquals($this->billingDept->id, $ticket->department_id);
    }

    /** @test */
    public function billing_ticket_includes_metadata(): void
    {
        $this->actingAs($this->user)
            ->post('/support/billing', [
                'subject' => 'Refund request',
                'description' => 'I need a refund.',
                'issueType' => 'refund_request',
            ]);

        $ticket = Ticket::where('ticket_type', 'billing_support')->first();
        $this->assertNotNull($ticket);
        $this->assertEquals('test_customer_123', $ticket->metadata['paddle_customer_id']);
        $this->assertEquals('refund_request', $ticket->metadata['issue_type']);
    }

    /** @test */
    public function billing_ticket_applies_tag(): void
    {
        $this->actingAs($this->user)
            ->post('/support/billing', [
                'subject' => 'Billing issue',
                'description' => 'Need help.',
                'issueType' => 'subscription_change',
            ]);

        $ticket = Ticket::where('ticket_type', 'billing_support')->first();
        $this->assertNotNull($ticket);
        $ticket->load('tags');
        $this->assertTrue($ticket->tags->contains('name', 'billing-support'));
    }

    /** @test */
    public function payment_issue_gets_high_priority(): void
    {
        $this->actingAs($this->user)
            ->post('/support/billing', [
                'subject' => 'Payment issue',
                'description' => 'Card declined.',
                'issueType' => 'payment_issue',
            ]);

        $ticket = Ticket::where('ticket_type', 'billing_support')->first();
        $this->assertNotNull($ticket);
        $this->assertEquals('high', $ticket->getRawOriginal('priority'));
    }

    /** @test */
    public function billing_support_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)->post('/support/billing', []);
        $response->assertSessionHasErrors(['subject', 'description', 'issueType']);
    }

    /** @test */
    public function guest_cannot_access_billing_support_page(): void
    {
        $response = $this->get('/support/billing');
        $response->assertRedirect(route('login'));
    }

    /** @test */
    public function payment_failure_auto_creates_billing_ticket(): void
    {
        $controller = new PaddleWebhookController;
        $method = new \ReflectionMethod($controller, 'createPaymentFailureTicket');
        $method->setAccessible(true);

        $method->invoke($controller, [
            'id' => 'txn_12345',
            'customer_id' => 'test_customer_123',
            'currency_code' => 'USD',
            'details' => ['totals' => ['total' => '29.99']],
            'subscription_id' => 'sub_67890',
        ]);

        $ticket = Ticket::where('ticket_type', 'billing_support')
            ->where('requester_id', $this->user->id)->first();

        $this->assertNotNull($ticket);
        $this->assertEquals('Billing', $ticket->department->name);
        $this->assertStringContainsString('Payment Failed', $ticket->subject);
        $this->assertEquals('payment_failure', $ticket->metadata['issue_type']);
        $this->assertEquals('29.99', $ticket->metadata['amount']);
        $this->assertTrue($ticket->metadata['auto_created']);

        $ticket->load('tags');
        $this->assertTrue($ticket->tags->contains('name', 'billing-support'));
        $this->assertTrue($ticket->tags->contains('name', 'payment-failure'));
    }

    /** @test */
    public function payment_failure_skips_ticket_if_no_customer_id(): void
    {
        $controller = new PaddleWebhookController;
        $method = new \ReflectionMethod($controller, 'createPaymentFailureTicket');
        $method->setAccessible(true);

        $method->invoke($controller, ['id' => 'txn_12345']);
        $this->assertEquals(0, Ticket::where('ticket_type', 'billing_support')->count());
    }

    /** @test */
    public function payment_failure_skips_ticket_if_user_not_found(): void
    {
        $controller = new PaddleWebhookController;
        $method = new \ReflectionMethod($controller, 'createPaymentFailureTicket');
        $method->setAccessible(true);

        $method->invoke($controller, [
            'id' => 'txn_12345',
            'customer_id' => 'nonexistent_customer',
            'currency_code' => 'USD',
            'details' => ['totals' => ['total' => '10.00']],
        ]);

        $this->assertEquals(0, Ticket::where('ticket_type', 'billing_support')->count());
    }
}
