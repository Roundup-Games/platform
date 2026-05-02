<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventStatusTransitionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\URL::defaults(['locale' => 'en']);
    }

    private function createOrganizer(): User
    {
        return User::factory()->create();
    }

    private function createEvent(string $status = 'draft'): Event
    {
        $organizer = $this->createOrganizer();

        return Event::factory()->create([
            'organizer_id' => $organizer->id,
            'status' => $status,
        ]);
    }

    // ── Unit: Event model isValidStatusTransition ───────

    public static function validTransitions(): array
    {
        return [
            'draft → published' => ['draft', 'published'],
            'published → registration_open' => ['published', 'registration_open'],
            'published → cancelled' => ['published', 'cancelled'],
            'registration_open → registration_closed' => ['registration_open', 'registration_closed'],
            'registration_open → cancelled' => ['registration_open', 'cancelled'],
            'registration_closed → in_progress' => ['registration_closed', 'in_progress'],
            'registration_closed → cancelled' => ['registration_closed', 'cancelled'],
            'in_progress → completed' => ['in_progress', 'completed'],
            'in_progress → cancelled' => ['in_progress', 'cancelled'],
            'cancelled → draft' => ['cancelled', 'draft'],
        ];
    }

    public static function invalidTransitions(): array
    {
        return [
            'completed → draft' => ['completed', 'draft'],
            'completed → published' => ['completed', 'published'],
            'cancelled → published' => ['cancelled', 'published'],
            'cancelled → registration_open' => ['cancelled', 'registration_open'],
            'published → draft' => ['published', 'draft'],
            'draft → registration_open' => ['draft', 'registration_open'],
            'draft → cancelled' => ['draft', 'cancelled'],
            'registration_open → draft' => ['registration_open', 'draft'],
            'registration_open → published' => ['registration_open', 'published'],
            'completed → completed' => ['completed', 'completed'],
            'draft → draft' => ['draft', 'draft'],
        ];
    }

    #[DataProvider('validTransitions')]
    #[\PHPUnit\Framework\Attributes\Group('smoke')]
    public function test_valid_event_status_transitions(string $from, string $to): void
    {
        $this->assertTrue(
            Event::isValidStatusTransition($from, $to),
            "Transition from '{$from}' to '{$to}' should be valid."
        );
    }

    #[DataProvider('invalidTransitions')]
    #[\PHPUnit\Framework\Attributes\Group('smoke')]
    public function test_invalid_event_status_transitions(string $from, string $to): void
    {
        $this->assertFalse(
            Event::isValidStatusTransition($from, $to),
            "Transition from '{$from}' to '{$to}' should be invalid."
        );
    }

    // ── Specific scenario tests ─────────────────────────

    public function test_cancelled_event_can_reopen_as_draft(): void
    {
        $event = $this->createEvent('cancelled');
        $organizer = $event->organizer;

        $response = $this->actingAs($organizer)
            ->from(route('events.manage', ['slug' => $event->slug]))
            ->patch("/livewire/update", [
                // Simulate the save() flow — change status to draft
            ]);

        // Verify the model method agrees
        $this->assertTrue(Event::isValidStatusTransition('cancelled', 'draft'));

        // Verify via Livewire component
        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('status', 'draft')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals('draft', $event->fresh()->status);
    }

    public function test_published_event_cannot_revert_to_draft(): void
    {
        $event = $this->createEvent('published');
        $organizer = $event->organizer;

        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('status', 'draft')
            ->call('save')
            ->assertHasErrors(['status']);

        $this->assertEquals('published', $event->fresh()->status);
    }

    public function test_completed_event_cannot_change_status(): void
    {
        $event = $this->createEvent('completed');
        $organizer = $event->organizer;

        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('status', 'draft')
            ->call('save')
            ->assertHasErrors(['status']);

        $this->assertEquals('completed', $event->fresh()->status);
    }

    // ── Dedicated action method tests ───────────────────

    public function test_publish_event_only_from_draft(): void
    {
        $event = $this->createEvent('draft');
        $organizer = $event->organizer;

        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('publishEvent')
            ->assertHasNoErrors();

        $this->assertEquals('published', $event->fresh()->status);
    }

    public function test_publish_event_rejects_from_published(): void
    {
        $event = $this->createEvent('published');
        $organizer = $event->organizer;

        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('publishEvent')
            ->assertHasErrors(['status']);

        $this->assertEquals('published', $event->fresh()->status);
    }

    public function test_open_registration_only_from_published(): void
    {
        $event = $this->createEvent('registration_closed');
        $organizer = $event->organizer;

        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('openRegistration')
            ->assertHasErrors(['status']);

        $this->assertEquals('registration_closed', $event->fresh()->status);
    }

    public function test_cancel_event_from_various_states(): void
    {
        $cancellableStates = ['published', 'registration_open', 'registration_closed', 'in_progress'];

        foreach ($cancellableStates as $status) {
            $event = $this->createEvent($status);
            $organizer = $event->organizer;

            \Livewire\Livewire::actingAs($organizer)
                ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
                ->call('cancelEvent')
                ->assertHasNoErrors();

            $this->assertEquals('cancelled', $event->fresh()->status, "Failed to cancel from '{$status}'.");
        }
    }

    public function test_cancel_event_rejects_from_completed(): void
    {
        $event = $this->createEvent('completed');
        $organizer = $event->organizer;

        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('cancelEvent')
            ->assertHasErrors(['status']);

        $this->assertEquals('completed', $event->fresh()->status);
    }

    public function test_cancel_event_rejects_from_draft(): void
    {
        $event = $this->createEvent('draft');
        $organizer = $event->organizer;

        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('cancelEvent')
            ->assertHasErrors(['status']);

        $this->assertEquals('draft', $event->fresh()->status);
    }

    public function test_invalid_transition_logs_warning(): void
    {
        $event = $this->createEvent('completed');
        $organizer = $event->organizer;

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            return str_contains($message, 'Invalid event status transition')
                && ($context['from'] ?? null) === 'completed'
                && ($context['to'] ?? null) === 'draft';
        });

        Log::shouldReceive('info')->zeroOrMoreTimes();

        \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('status', 'draft')
            ->call('save');
    }

    public function test_full_happy_path_lifecycle(): void
    {
        $event = $this->createEvent('draft');
        $organizer = $event->organizer;

        $component = \Livewire\Livewire::actingAs($organizer)
            ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug]);

        // draft → published
        $component->call('publishEvent')->assertHasNoErrors();
        $this->assertEquals('published', $event->fresh()->status);

        // published → registration_open
        $component->call('openRegistration')->assertHasNoErrors();
        $this->assertEquals('registration_open', $event->fresh()->status);

        // registration_open → registration_closed
        $component->call('closeRegistration')->assertHasNoErrors();
        $this->assertEquals('registration_closed', $event->fresh()->status);

        // registration_closed → in_progress (via save)
        $component->set('status', 'in_progress')->call('save')->assertHasNoErrors();
        $this->assertEquals('in_progress', $event->fresh()->status);

        // in_progress → completed
        $component->set('status', 'completed')->call('save')->assertHasNoErrors();
        $this->assertEquals('completed', $event->fresh()->status);
    }
}
