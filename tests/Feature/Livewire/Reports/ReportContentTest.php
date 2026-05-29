<?php

use App\Livewire\Reports\ReportContent;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

if (! function_exists('seedSafetyDepartment')) {
    function seedSafetyDepartment(): void
    {
        Department::firstOrCreate(
            ['name' => 'Safety'],
            ['description' => 'Review reports, content moderation, user reports', 'is_active' => true],
        );
    }
}

if (! function_exists('seedReportTags')) {
    function seedReportTags(): void
    {
        $tags = [
            ['name' => 'user-report', 'color' => '#BE185D'],
            ['name' => 'game-report', 'color' => '#DB2777'],
            ['name' => 'campaign-report', 'color' => '#EC4899'],
            ['name' => 'inappropriate-content', 'color' => '#DC2626'],
            ['name' => 'harassment', 'color' => '#B91C1C'],
            ['name' => 'spam', 'color' => '#D97706'],
            ['name' => 'misleading', 'color' => '#EA580C'],
        ];

        foreach ($tags as $tag) {
            Tag::firstOrCreate(['name' => $tag['name']], $tag);
        }
    }
}

function createReportableGame(): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Test Game Session'],
    ]);

    return compact('owner', 'game');
}

function createReportableCampaign(): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Test Campaign'],
    ]);

    return compact('owner', 'campaign');
}

// ── Modal open/close ───────────────────────────────────

it('can open and close the report modal for a game', function () {
    ['game' => $game] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->assertSet('showModal', false)
        ->call('openModal')
        ->assertSet('showModal', true)
        ->call('closeModal')
        ->assertSet('showModal', false);
});

it('can open and close the report modal for a user', function () {
    $reportedUser = User::factory()->create(['profile_complete' => true]);
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'user', 'entityId' => $reportedUser->id])
        ->call('openModal')
        ->assertSet('showModal', true)
        ->call('closeModal')
        ->assertSet('showModal', false);
});

it('can open and close the report modal for a campaign', function () {
    ['campaign' => $campaign] = createReportableCampaign();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'campaign', 'entityId' => $campaign->id])
        ->call('openModal')
        ->assertSet('showModal', true)
        ->call('closeModal')
        ->assertSet('showModal', false);
});

// ── Validation ─────────────────────────────────────────

it('validates reason is required', function () {
    ['game' => $game] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->call('submitReport')
        ->assertHasErrors(['reason' => 'required']);
});

it('validates reason is a valid enum value', function () {
    ['game' => $game] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'invalid_reason')
        ->call('submitReport')
        ->assertHasErrors(['reason' => 'in']);
});

it('validates description max length', function () {
    ['game' => $game] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->set('description', str_repeat('x', 1001))
        ->call('submitReport')
        ->assertHasErrors(['description' => 'max']);
});

it('description is optional', function () {
    seedSafetyDepartment();
    seedReportTags();
    ['game' => $game] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertSet('successMessage', __('reports.flash_report_submitted'))
        ->assertHasNoErrors();
});

it('accepts all valid report reasons', function ($reason) {
    seedSafetyDepartment();
    seedReportTags();
    ['game' => $game] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', $reason)
        ->call('submitReport')
        ->assertSet('successMessage', __('reports.flash_report_submitted'));

    $ticket = Ticket::where('ticket_type', 'content_report')->latest()->first();
    expect($ticket->metadata['report_reason'])->toBe($reason);
})->with(['inappropriate-content', 'harassment', 'spam', 'misleading', 'other']);

it('rejects invalid entity type', function () {
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'invalid', 'entityId' => '123']);
})->throws(Exception::class);

it('handles non-existent entity gracefully', function () {
    seedSafetyDepartment();
    $reporter = User::factory()->create(['profile_complete' => true]);
    $fakeId = (string) \Illuminate\Support\Str::uuid();

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $fakeId])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertHasErrors(['reason']);
});

// ── Ticket creation ────────────────────────────────────

it('creates a safety ticket for a reported game', function () {
    seedSafetyDepartment();
    seedReportTags();
    ['game' => $game, 'owner' => $owner] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'harassment')
        ->call('submitReport')
        ->assertSet('successMessage', __('reports.flash_report_submitted'));

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->subject)->toBe('Game Report: Harassment');
    expect($ticket->priority)->toBe(TicketPriority::High);
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->requester_id)->toBe($reporter->id);
    expect($ticket->requester_type)->toBe(User::class);
    expect($ticket->channel->value)->toBe('web');

    // Verify metadata
    $metadata = $ticket->metadata;
    expect($metadata['entity_type'])->toBe('game');
    expect($metadata['entity_id'])->toBe($game->id);
    expect($metadata['entity_name'])->toBe('Test Game Session');
    expect($metadata['reporter_id'])->toBe($reporter->id);
    expect($metadata['report_reason'])->toBe('harassment');

    // Verify department
    $department = Department::where('name', 'Safety')->first();
    expect($ticket->department_id)->toBe($department->id);

    // Verify tags
    expect($ticket->tags->pluck('name')->toArray())->toContain('game-report');
    expect($ticket->tags->pluck('name')->toArray())->toContain('harassment');
});

it('creates a safety ticket for a reported user', function () {
    seedSafetyDepartment();
    seedReportTags();
    $reportedUser = User::factory()->create(['profile_complete' => true, 'name' => 'Bad Actor']);
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'user', 'entityId' => $reportedUser->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->set('description', 'This user is sending spam messages')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->subject)->toBe('User Report: Spam');
    expect($ticket->metadata['entity_type'])->toBe('user');
    expect($ticket->metadata['entity_id'])->toBe($reportedUser->id);
    expect($ticket->metadata['entity_name'])->toBe('Bad Actor');
    expect($ticket->metadata['details'])->toBe('This user is sending spam messages');

    // Verify tags
    expect($ticket->tags->pluck('name')->toArray())->toContain('user-report');
    expect($ticket->tags->pluck('name')->toArray())->toContain('spam');
});

it('creates a safety ticket for a reported campaign', function () {
    seedSafetyDepartment();
    seedReportTags();
    ['campaign' => $campaign, 'owner' => $owner] = createReportableCampaign();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'campaign', 'entityId' => $campaign->id])
        ->call('openModal')
        ->set('reason', 'misleading')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->subject)->toBe('Campaign Report: Misleading');
    expect($ticket->metadata['entity_type'])->toBe('campaign');
    expect($ticket->metadata['entity_id'])->toBe($campaign->id);
    expect($ticket->metadata['entity_name'])->toBe('Test Campaign');

    // Verify tags
    expect($ticket->tags->pluck('name')->toArray())->toContain('campaign-report');
    expect($ticket->tags->pluck('name')->toArray())->toContain('misleading');
});

it('includes entity owner in ticket description for games', function () {
    seedSafetyDepartment();
    seedReportTags();
    ['game' => $game, 'owner' => $owner] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'inappropriate-content')
        ->set('description', 'Some context')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket->description)->toContain($owner->name);
    expect($ticket->description)->toContain('Inappropriate content');
    expect($ticket->description)->toContain('Some context');
    expect($ticket->description)->toContain('Test Game Session');
});

it('creates ticket without department gracefully', function () {
    // Do NOT seed Safety department
    seedReportTags();
    ['game' => $game] = createReportableGame();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->department_id)->toBeNull();
});

// ── Rate limiting ──────────────────────────────────────

it('rate limits reports to 5 per hour', function () {
    seedSafetyDepartment();
    seedReportTags();
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Submit 5 reports successfully
    for ($i = 0; $i < 5; $i++) {
        $game = Game::factory()->create([
            'owner_id' => User::factory()->create(['profile_complete' => true])->id,
        ]);

        Livewire::actingAs($reporter)
            ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
            ->call('openModal')
            ->set('reason', 'spam')
            ->call('submitReport')
            ->assertSet('successMessage', __('reports.flash_report_submitted'));
    }

    // 6th report should be rate limited
    $game = Game::factory()->create([
        'owner_id' => User::factory()->create(['profile_complete' => true])->id,
    ]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertHasErrors(['reason']);

    // No 6th ticket created
    expect(Ticket::where('ticket_type', 'content_report')->count())->toBe(5);
});

it('rate limiter does not affect different users', function () {
    seedSafetyDepartment();
    seedReportTags();

    $reporter1 = User::factory()->create(['profile_complete' => true]);
    $reporter2 = User::factory()->create(['profile_complete' => true]);

    // Reporter 1 submits a report
    $game1 = Game::factory()->create([
        'owner_id' => User::factory()->create(['profile_complete' => true])->id,
    ]);
    Livewire::actingAs($reporter1)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game1->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertSet('successMessage', __('reports.flash_report_submitted'));

    // Reporter 2 should be able to submit too
    $game2 = Game::factory()->create([
        'owner_id' => User::factory()->create(['profile_complete' => true])->id,
    ]);
    Livewire::actingAs($reporter2)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game2->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertSet('successMessage', __('reports.flash_report_submitted'));

    expect(Ticket::where('ticket_type', 'content_report')->count())->toBe(2);
});
