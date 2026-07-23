<?php

use App\Enums\GameStatus;
use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\GameReminder;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ═══════════════════════════════════════════════════════════
// (a) Add — owner creates a custom reminder
// ═══════════════════════════════════════════════════════════

describe('add', function () {
    it('creates a custom reminder with send_at and a custom message', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $sendAt = now()->addDays(2)->format('Y-m-d\TH:i');

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('reminderSendAt', $sendAt)
            ->set('reminderMessage', 'Bring your character sheets!')
            ->call('saveReminder')
            ->assertHasNoErrors();

        $reminders = $game->fresh()->reminders;
        expect($reminders)->toHaveCount(1)
            ->and($reminders->first()->message)->toBe('Bring your character sheets!')
            ->and($reminders->first()->sent_at)->toBeNull();

        // send_at is stored verbatim from the datetime-local value.
        expect($reminders->first()->send_at->format('Y-m-d\TH:i'))->toBe($sendAt);
    });

    it('creates a reminder with a null message when none is provided', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $sendAt = now()->addDays(2)->format('Y-m-d\TH:i');

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('reminderSendAt', $sendAt)
            ->set('reminderMessage', '')
            ->call('saveReminder')
            ->assertHasNoErrors();

        $reminder = $game->fresh()->reminders->first();
        expect($reminder->message)->toBeNull();
    });

    it('trims a whitespace-only message to null (default copy)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $sendAt = now()->addDays(2)->format('Y-m-d\TH:i');

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('reminderSendAt', $sendAt)
            ->set('reminderMessage', '   ')
            ->call('saveReminder')
            ->assertHasNoErrors();

        expect($game->fresh()->reminders->first()->message)->toBeNull();
    });

    it('requires a send_at value', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('reminderSendAt', '')
            ->call('saveReminder')
            ->assertHasErrors(['reminderSendAt']);

        expect($game->fresh()->reminders)->toBeEmpty();
    });

    it('rejects an invalid datetime-local value', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('reminderSendAt', 'not-a-date')
            ->call('saveReminder')
            ->assertHasErrors(['reminderSendAt']);

        expect($game->fresh()->reminders)->toBeEmpty();
    });

    it('rejects a message longer than 500 characters', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $sendAt = now()->addDays(2)->format('Y-m-d\TH:i');

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('reminderSendAt', $sendAt)
            ->set('reminderMessage', str_repeat('x', 501))
            ->call('saveReminder')
            ->assertHasErrors(['reminderMessage']);

        expect($game->fresh()->reminders)->toBeEmpty();
    });
});

// ═══════════════════════════════════════════════════════════
// (b) 5-reminder cap — enforced on create only
// ═══════════════════════════════════════════════════════════

describe('reminder limit', function () {
    it('blocks a 6th reminder with an error', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        // Seed exactly the cap.
        GameReminder::factory()->count(5)->forGame($game)->create();
        expect($game->fresh()->reminders)->toHaveCount(5);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('reminderSendAt', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->call('saveReminder')
            ->assertHasErrors(['reminderSendAt']);

        // No 6th row.
        expect($game->fresh()->reminders)->toHaveCount(5);
    });

    it('allows editing an existing reminder when already at the cap', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        GameReminder::factory()->count(5)->forGame($game)->create();
        $toEdit = $game->fresh()->reminders->first();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $toEdit->id)
            ->set('reminderMessage', 'Updated copy')
            ->call('saveReminder')
            ->assertHasNoErrors();

        // Still 5 — no new row, the edited one carries the new message.
        expect($game->fresh()->reminders)->toHaveCount(5)
            ->and($toEdit->fresh()->message)->toBe('Updated copy');
    });
});

// ═══════════════════════════════════════════════════════════
// (c) Edit — owner updates an existing reminder
// ═══════════════════════════════════════════════════════════

describe('edit', function () {
    it('loads an existing reminder into the form', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $reminder = GameReminder::factory()->forGame($game)->create([
            'send_at' => now()->addDays(3),
            'message' => 'Original copy',
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $reminder->id)
            ->assertSet('editingReminderId', $reminder->id)
            ->assertSet('reminderMessage', 'Original copy');

        // send_at is reformatted to the datetime-local string.
        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $reminder->id);
        expect($component->get('reminderSendAt'))
            ->toBe($reminder->send_at->format('Y-m-d\TH:i'));
    });

    it('updates the send_at and message of an existing reminder', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $reminder = GameReminder::factory()->forGame($game)->create([
            'message' => 'Original',
        ]);
        $newSendAt = now()->addDays(4)->format('Y-m-d\TH:i');

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $reminder->id)
            ->set('reminderSendAt', $newSendAt)
            ->set('reminderMessage', 'Updated copy')
            ->call('saveReminder')
            ->assertHasNoErrors();

        $reminder->refresh();
        expect($reminder->message)->toBe('Updated copy')
            ->and($reminder->send_at->format('Y-m-d\TH:i'))->toBe($newSendAt);
    });

    it('clears the form after a successful save', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $reminder = GameReminder::factory()->forGame($game)->create();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $reminder->id)
            ->set('reminderMessage', 'Updated')
            ->call('saveReminder')
            ->assertSet('editingReminderId', null)
            ->assertSet('reminderSendAt', null)
            ->assertSet('reminderMessage', null);
    });

    it('cancelReminderForm resets the form state', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $reminder = GameReminder::factory()->forGame($game)->create();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $reminder->id)
            ->call('cancelReminderForm')
            ->assertSet('editingReminderId', null)
            ->assertSet('reminderSendAt', null)
            ->assertSet('reminderMessage', null);
    });

    it('flashes an error when editing a reminder that no longer exists', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $bogusId = (string) Str::uuid();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $bogusId)
            ->assertHasNoErrors()
            ->assertSet('editingReminderId', null);
    });
});

// ═══════════════════════════════════════════════════════════
// (d) Remove — owner deletes a custom reminder
// ═══════════════════════════════════════════════════════════

describe('remove', function () {
    it('removes a custom reminder', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $reminder = GameReminder::factory()->forGame($game)->create();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('removeReminder', $reminder->id)
            ->assertHasNoErrors();

        expect(GameReminder::find($reminder->id))->toBeNull();
    });

    it('clears the edit form if the removed reminder was being edited', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $reminder = GameReminder::factory()->forGame($game)->create();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $reminder->id)
            ->assertSet('editingReminderId', $reminder->id)
            ->call('removeReminder', $reminder->id)
            ->assertSet('editingReminderId', null)
            ->assertSet('reminderSendAt', null);
    });

    it('only removes a reminder belonging to this game (no cross-game leak)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $otherGame = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $foreignReminder = GameReminder::factory()->forGame($otherGame)->create();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('removeReminder', $foreignReminder->id)
            ->assertHasNoErrors();

        // The foreign reminder survives — whereKey scopes to $game->reminders().
        expect(GameReminder::find($foreignReminder->id))->not->toBeNull();
    });

    it('does not change the count when removing a foreign reminder', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        GameReminder::factory()->forGame($game)->create();
        $otherGame = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $foreignReminder = GameReminder::factory()->forGame($otherGame)->create();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('removeReminder', $foreignReminder->id);

        expect($game->fresh()->reminders)->toHaveCount(1);
    });
});

// ═══════════════════════════════════════════════════════════
// (e) Authorization — non-host gets 403
// ═══════════════════════════════════════════════════════════

describe('authorization', function () {
    it('rejects saveReminder by a non-host with 403', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $nonHost = User::factory()->create();

        Livewire::actingAs($nonHost)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('reminderSendAt', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->call('saveReminder')
            ->assertStatus(403);

        expect($game->fresh()->reminders)->toBeEmpty();
    });

    it('rejects removeReminder by a non-host with 403', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $reminder = GameReminder::factory()->forGame($game)->create();
        $nonHost = User::factory()->create();

        Livewire::actingAs($nonHost)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('removeReminder', $reminder->id)
            ->assertStatus(403);

        expect(GameReminder::find($reminder->id))->not->toBeNull();
    });

    it('rejects editReminder by a non-host with 403', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $reminder = GameReminder::factory()->forGame($game)->create();
        $nonHost = User::factory()->create();

        Livewire::actingAs($nonHost)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('editReminder', $reminder->id)
            ->assertStatus(403);
    });
});

// ═══════════════════════════════════════════════════════════
// (f) Terminal-state guard — the section hides on non-scheduled games
//     (mirrors the capacity-editor host-affordance gate). A Completed game
//     does not render the section, so no reminder write is possible.
// ═══════════════════════════════════════════════════════════

describe('terminal-state guard', function () {
    it('does not surface reminders to a Completed game owner (section hidden)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3, overrides: [
            'status' => GameStatus::Completed->value,
        ]);
        GameReminder::factory()->forGame($game)->create(['message' => 'Existing copy']);

        // Even the owner of a Completed game does not see the section title.
        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('games.title_custom_reminders'));
    });

    it('surfaces the section for a scheduled game owner', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.title_custom_reminders'))
            ->assertSee(__('games.action_add_reminder'));
    });
});
