<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\ContentRemoved;
use App\Traits\ResolvesCoverImage;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;

// ═══════════════════════════════════════════════════════════
// T03 (S07): cover-image reporting/takedown via the EXISTING reactive
// ReportContent -> Safety ticket -> admin Clear Cover Image flow.
//
// The project's trust model is reactive: anyone flags the carrying
// Game/Campaign via ReportContent (already wired in _game-sidebar and
// campaign-detail); a Safety content_report ticket is filed; an admin
// resolves it with the proportionate "Clear Cover Image" action (instead
// of nuking the whole entity via "Remove Content"). resolveCoverUrl()'s
// on-disk file_exists guard then falls through to the representative/
// default rung, and the owner is notified via ContentRemoved with
// scope='cover_image'.
//
// These tests pin:
//  - ResolvesCoverImage::hasCover() / clearCoverImage() (the trait helpers)
//  - performClearCover() (the admin moderation action)
//  - resolveCoverUrl() falling through after a cover clear
//  - ContentRemoved scoped messaging (cover_image_removed db payload)
//  - No pre-publish gate was introduced (the grep guard)
// ═══════════════════════════════════════════════════════════

beforeEach(function () {
    seedRoles();

    // Safety department must exist for content_report tickets.
    Department::firstOrCreate(['name' => 'Safety'], ['description' => 'Safety and moderation department']);

    $this->admin = User::factory()->create(['profile_complete' => true]);
    $this->admin->assignRole('Service Admin');
});

/**
 * Upload a real cover image to a Game via the Spatie cover collection,
 * returning the freshly reloaded model. Mirrors the T02 CreateGame upload
 * path but is called directly on the model so the moderation flow can be
 * exercised without booting Livewire.
 */
function attachCoverToGame(Game $game): Game
{
    Storage::fake('public');
    $game->addMedia(UploadedFile::fake()->image('cover.jpg', 800, 600))
        ->toMediaCollection('cover');

    return $game->fresh();
}

function attachCoverToCampaign(Campaign $campaign): Campaign
{
    Storage::fake('public');
    $campaign->addMedia(UploadedFile::fake()->image('camp-cover.png', 1200, 630))
        ->toMediaCollection('cover');

    return $campaign->fresh();
}

/**
 * Build a Safety content_report ticket whose reported subject is the given
 * Game/Campaign. Mirrors what ReportContent::createSafetyTicket produces
 * (entity_type/entity_id/entity_name + report_reason in metadata).
 */
function coverReportTicket(Game|Campaign $entity, string $type, User $reporter): Ticket
{
    $department = Department::where('name', 'Safety')->first();

    $ticket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $reporter->id,
        'subject' => ucfirst($type).' Report: Inappropriate Content',
        'description' => 'Offensive cover image.',
        'status' => TicketStatus::Open->value,
        'priority' => 'high',
        'department_id' => $department?->id,
        'ticket_type' => 'content_report',
        'metadata' => [
            'entity_type' => $type,
            'entity_id' => $entity->id,
            'entity_name' => $entity->name,
            'report_reason' => 'inappropriate-content',
            'description' => 'The cover image violates community guidelines.',
        ],
    ]);
    $ticket->attachSubject($entity, 'reported');

    return $ticket;
}

/**
 * Invoke a protected perform* method on the ViewTicket page via reflection —
 * same pattern as ContentModerationTest::invokeModerationAction(). The caller
 * must actingAs() an admin first.
 *
 * Guarded with function_exists() so this file can coexist with
 * ContentModerationTest.php (which defines the same helper) in a single
 * Pest suite run — matches the project's shared-helper convention
 * (see ReportContentTest.php, ReportReviewTest.php). Whichever file loads
 * first defines the function; the other sees it exists and skips.
 */
if (! function_exists('invokeModerationAction')) {
    function invokeModerationAction(string $method, mixed ...$args): void
    {
        $page = new ViewTicket;
        $reflection = new ReflectionMethod($page, $method);
        $reflection->setAccessible(true);
        $reflection->invoke($page, ...$args);
    }
}

// ── Trait helpers (ResolvesCoverImage) ─────────────────────────────────

it('hasCover() reports the host-uploaded cover state', function () {
    Storage::fake('public');
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $owner->id]);

    // No cover yet -> false.
    expect($game->hasCover())->toBeFalse();

    // After an upload -> true.
    attachCoverToGame($game);
    expect($game->fresh()->hasCover())->toBeTrue();
});

it('clearCoverImage() removes the host cover and returns true when one existed', function () {
    Storage::fake('public');
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $owner->id]);
    attachCoverToGame($game);
    $game = $game->fresh();

    $cleared = $game->clearCoverImage();

    expect($cleared)->toBeTrue();
    expect($game->fresh()->getFirstMedia('cover'))->toBeNull();
    expect($game->fresh()->hasCover())->toBeFalse();
});

it('clearCoverImage() is a safe no-op (returns false) when no host cover exists', function () {
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $owner->id]);

    expect($game->clearCoverImage())->toBeFalse();
    expect($game->hasCover())->toBeFalse();
});

// ── resolveCoverUrl() fallback after a takedown ─────────────────────────

it('resolveCoverUrl() falls back to the representative system cover after clearCoverImage()', function () {
    Storage::fake('public');

    // Representative system with a thumbnail_url (rung 2).
    $system = GameSystem::factory()->create([
        'thumbnail_url' => 'https://example.com/rep-system.jpg',
    ]);
    $owner = User::factory()->create(['profile_complete' => true]);

    // Create a game offering the system, then upload a host cover (rung 1).
    $game = Game::factory()->create(['owner_id' => $owner->id]);
    $game->gameSystems()->sync([$system->id]);
    attachCoverToGame($game);
    $game = $game->fresh()->load('gameSystems');

    // Host cover wins initially.
    $hostUrl = $game->resolveCoverUrl();
    expect($hostUrl)->not->toBe('https://example.com/rep-system.jpg');

    // Clear the cover via the moderation helper -> representative rung fires.
    $game->clearCoverImage();
    $game = $game->fresh()->load('gameSystems');

    expect($game->resolveCoverUrl())->toBe('https://example.com/rep-system.jpg');
});

it('resolveCoverUrl() falls back to the default asset when no host cover and no representative exist', function () {
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $owner->id]);

    // No host cover, no systems -> default asset rung.
    expect($game->resolveCoverUrl())
        ->toBe(asset('images/og-default.jpg'));
});

// ── Admin moderation action (performClearCover) ────────────────────────

it('performClearCover clears the cover, closes the ticket, and notifies the owner with scope=cover_image', function () {
    NotificationFacade::fake();
    Storage::fake('public');

    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $owner->id]);
    attachCoverToGame($game);
    $game = $game->fresh();

    $reporter = User::factory()->create(['profile_complete' => true]);
    $ticket = coverReportTicket($game, 'game', $reporter);

    $this->actingAs($this->admin);
    invokeModerationAction('performClearCover', $ticket, 'game', $game->name);

    $ticket->refresh();
    $game->refresh();

    // Ticket closed.
    expect($ticket->status)->toBe(TicketStatus::Closed);

    // Host cover gone; resolveCoverUrl() now returns a fallback (no longer the host media URL).
    expect($game->getFirstMedia('cover'))->toBeNull();
    $hostCoverUrl = Storage::disk('public')->url($game->getFirstMedia('cover')?->id.'/conversions/'.pathinfo('cover.jpg', PATHINFO_FILENAME).'-thumb.jpg');
    expect($game->resolveCoverUrl())->not->toBe($hostCoverUrl);

    // Owner notified with the cover-scoped ContentRemoved notification.
    NotificationFacade::assertSentTo($owner, ContentRemoved::class, function (ContentRemoved $n) use ($game) {
        return $n->entityType === 'game'
            && $n->entityName === $game->name
            && $n->reason === 'inappropriate-content'
            && $n->scope === 'cover_image';
    });
});

it('performClearCover does not cancel the carrying game (entity stays published)', function () {
    NotificationFacade::fake();
    Storage::fake('public');

    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'status' => GameStatus::Scheduled,
    ]);
    attachCoverToGame($game);
    $game = $game->fresh();

    $reporter = User::factory()->create(['profile_complete' => true]);
    $ticket = coverReportTicket($game, 'game', $reporter);

    $this->actingAs($this->admin);
    invokeModerationAction('performClearCover', $ticket, 'game', $game->name);

    $game->refresh();

    // The game itself is untouched — only the cover media was removed.
    // (performRemoveContent would have flipped this to Canceled.)
    expect($game->status)->toBe(GameStatus::Scheduled);
});

it('performClearCover handles a missing entity gracefully (no crash, no notification)', function () {
    NotificationFacade::fake();

    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $owner->id]);

    $reporter = User::factory()->create(['profile_complete' => true]);
    $ticket = coverReportTicket($game, 'game', $reporter);

    // Delete the game so resolveReportedEntity returns null.
    $game->delete();

    $this->actingAs($this->admin);
    invokeModerationAction('performClearCover', $ticket, 'game', $game->name);

    $ticket->refresh();
    // Ticket stays open — the action bailed before close() because the entity
    // couldn't be resolved (mirrors performWarnUser's missing-user contract).
    expect($ticket->status)->toBe(TicketStatus::Open);
    // The owner must NOT receive a ContentRemoved notification (the action
    // bailed before notifying). Ticket-creation notifications to the
    // requester are legitimate and excluded from this assertion.
    NotificationFacade::assertNotSentTo($owner, ContentRemoved::class);
});

it('performClearCover works for a reported campaign carrying a cover', function () {
    NotificationFacade::fake();
    Storage::fake('public');

    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'status' => CampaignStatus::Active,
    ]);
    attachCoverToCampaign($campaign);
    $campaign = $campaign->fresh();

    $reporter = User::factory()->create(['profile_complete' => true]);
    $ticket = coverReportTicket($campaign, 'campaign', $reporter);

    $this->actingAs($this->admin);
    invokeModerationAction('performClearCover', $ticket, 'campaign', $campaign->name);

    $campaign->refresh();
    expect($campaign->getFirstMedia('cover'))->toBeNull();
    expect($campaign->status)->toBe(CampaignStatus::Active); // entity untouched

    NotificationFacade::assertSentTo($owner, ContentRemoved::class, function (ContentRemoved $n) {
        return $n->entityType === 'campaign'
            && $n->scope === 'cover_image';
    });
});

// ── ContentRemoved scoped messaging ────────────────────────────────────

it('ContentRemoved with scope=cover_image emits a cover_image_removed database payload', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $notification = new ContentRemoved('game', 'Test Game', 'inappropriate-content', 'cover_image');
    $payload = $notification->toDatabase($user);

    expect($payload['type'])->toBe('cover_image_removed');
    expect($payload['scope'])->toBe('cover_image');
    expect($payload['entity_type'])->toBe('game');
});

it('ContentRemoved without scope stays backwards-compatible (content_removed payload)', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $notification = new ContentRemoved('campaign', 'Test Campaign', 'spam');
    $payload = $notification->toDatabase($user);

    expect($payload['type'])->toBe('content_removed');
    expect($payload['scope'])->toBeNull();
});

// ── Reactive-model guard: no pre-publish gate was introduced ───────────

it('does not introduce a pre-publish moderation gate (reactive trust model)', function () {
    // The trust model is reactive (report -> review -> remove). There must be
    // NO moderation_status / media_review / pre-publish gating columns or
    // checks on Game/Campaign. This grep guard pins that contract.
    $forbidden = [
        'moderation_status',
        'media_review',
        'is_pending_review',
        'requires_approval',
    ];

    foreach ($forbidden as $term) {
        $hits = collect([
            'app/Models/Game.php',
            'app/Models/Campaign.php',
            'app/Traits/ResolvesCoverImage.php',
            'app/Livewire/Games/CreateGame.php',
            'app/Livewire/Campaigns/CreateCampaign.php',
        ])
            ->filter(fn ($file) => file_exists($file))
            ->filter(fn ($file) => str_contains((string) file_get_contents($file), $term));

        expect($hits)->toBeEmpty("Pre-publish gate term '{$term}' must not appear in cover-upload code.");
    }
});
