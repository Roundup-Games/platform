<?php

use App\Enums\NotificationCategory;
use App\Filament\Pages\System\NotificationBatchingPage;
use App\Models\User;
use App\Services\NotificationEngagementCounters;
use App\Services\NotificationService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// Notification Batching admin page: the D296 policy matrix — every
// category with its isBatchable() verdict, semantic rationale, and the
// live batch-vs-immediate counter columns from the D284 aggregate.
//
// The page renders policy rather than encoding it, so the strongest
// tests here pin the derivation (rows == enum verdicts, numbers ==
// enforced constants) instead of snapshotting copy.
beforeEach(function () {
    seedRoles();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    actingAs($this->platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
    Cache::flush();
});

it('renders the policy matrix with verdicts, rationale, and enforced window numbers for admins', function () {
    Livewire\Livewire::test(NotificationBatchingPage::class)
        ->assertOk()
        ->assertSee('Batched — defer window (v1)')
        ->assertSee('Never batched — immediate dispatch')
        ->assertSeeText('Batched')
        ->assertSeeText('Immediate')
        // One known row from each table (mono category values)
        ->assertSee('participant_joined')
        ->assertSee('new_application')
        ->assertSee('session_added_to_campaign')
        ->assertSee('waitlist_promoted')
        // Rationale copy renders per row (enum strings, not lang values)
        ->assertSee('five joins land as one notification')
        ->assertSee('Hard never-batch guarantee')
        // Window numbers come from the constants NotificationService enforces
        ->assertSee(NotificationService::BATCH_WINDOW_MINUTES.'-minute window')
        ->assertSee('MaxSize '.NotificationService::BATCH_MAX_ITEMS);
});

it('derives every row from isBatchable() so the matrix cannot drift from the dispatch policy', function () {
    $data = Livewire\Livewire::test(NotificationBatchingPage::class)->instance()->getViewData();

    $batchedValues = array_column($data['batched'], 'value');
    $immediateValues = array_column($data['immediate'], 'value');

    // Every enum case appears exactly once across the two tables, in the
    // table its isBatchable() verdict dictates.
    // (array_merge, not + — the union operator would keep the left list's
    // keys and silently drop the first rows of the second table.)
    $allValues = array_merge($batchedValues, $immediateValues);

    expect($allValues)->toHaveCount(count(NotificationCategory::cases()));

    // No category may appear twice across the two tables (Pest v3 arrays
    // have no toHaveDuplicates — unique-count comparison instead).
    expect(count(array_unique($allValues)))
        ->toBe(count($allValues), 'A category appears in both tables');

    foreach (NotificationCategory::cases() as $category) {
        $inBatched = in_array($category->value, $batchedValues, true);
        expect($inBatched)->toBe($category->isBatchable(), "Category [{$category->value}] is in the wrong table");

        $row = collect($inBatched ? $data['batched'] : $data['immediate'])
            ->firstWhere('value', $category->value);

        // batchingRationale() must be exhaustive: a missing arm throws
        // UnhandledMatchError, which would 500 the page.
        expect($row['rationale'])->toBeString()->not->toBeEmpty("Rationale missing for [{$category->value}]");
    }

    // The quoted window/size numbers ARE the constants the dispatch path
    // uses — the page cannot display a policy it does not enforce.
    expect($data['window_minutes'])->toBe(NotificationService::BATCH_WINDOW_MINUTES)
        ->and($data['max_items'])->toBe(NotificationService::BATCH_MAX_ITEMS)
        ->and($data['open_batches'])->toBeInt();
});

it('shows the honest zero-volume empty state when no batch-channel counters exist', function () {
    $data = Livewire\Livewire::test(NotificationBatchingPage::class)
        ->assertSee('No batch-channel traffic recorded yet')
        ->assertSeeText('0')
        ->instance()->getViewData();

    expect($data['has_volume'])->toBeFalse()
        ->and($data['total_volume'])->toBe(0);
});

it('joins live lifetime totals from the D284 aggregate and filters to the batch channel', function () {
    $counters = app(NotificationEngagementCounters::class);

    // Batch-channel lifecycle events for one batched and one never-batched
    // category — the exact events the live columns read.
    $counters->incrementFor('participant_joined', 'batch', 'event_buffered');
    $counters->incrementFor('participant_joined', 'batch', 'event_buffered');
    $counters->incrementFor('participant_joined', 'batch', 'event_buffered');
    $counters->incrementFor('participant_joined', 'batch', 'flushed');
    $counters->incrementFor('waitlist_promoted', 'batch', 'dispatch_immediate');
    $counters->incrementFor('waitlist_promoted', 'batch', 'dispatch_immediate');

    // Non-batch-channel traffic for the same category must NOT leak into
    // the matrix columns (mail attribution lives on its own channel).
    $counters->incrementFor('participant_joined', 'mail', 'delivered');

    $data = Livewire\Livewire::test(NotificationBatchingPage::class)
        ->assertDontSee('No batch-channel traffic recorded yet')
        ->assertSeeText('3')
        ->instance()->getViewData();

    $joined = collect($data['batched'])->firstWhere('value', 'participant_joined');
    $promoted = collect($data['immediate'])->firstWhere('value', 'waitlist_promoted');

    // Defer-window volume (event_buffered + flushed) drives has_volume —
    // NOT dispatch_immediate, which every never-batched category bumps on
    // its first dispatch and would retire the empty-state note while the
    // window itself has never fired (M082 review: 4, not 6).
    expect($joined['buffered'])->toBe(3)
        ->and($joined['flushed'])->toBe(1)
        ->and($joined['immediate'])->toBe(0)
        ->and($promoted['immediate'])->toBe(2)
        ->and($promoted['buffered'])->toBe(0)
        ->and($data['has_volume'])->toBeTrue()
        ->and($data['total_volume'])->toBe(4);
});

it('is admin-only', function () {
    $member = User::factory()->create();
    actingAs($member);

    // The route stays registered; canAccess()===false yields 403 for
    // authenticated non-admins (MEM1252 pattern).
    get('/admin/notification-batching-page')->assertForbidden();
});

it('denies guests', function () {
    auth()->logout();

    // Guests hit the panel auth middleware before the page renders:
    // 302 redirect to the login route.
    get('/admin/notification-batching-page')->assertRedirect();
});
