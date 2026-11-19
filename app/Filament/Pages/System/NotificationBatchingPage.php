<?php

namespace App\Filament\Pages\System;

use App\Enums\NotificationCategory;
use App\Models\NotificationBatch;
use App\Services\NotificationService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Notification Batching policy matrix (M082/S03, D296): every
 * NotificationCategory with its isBatchable() verdict, the semantic
 * rationale for that verdict, and the live batch-vs-immediate counter
 * columns from the D284 engagement aggregate.
 *
 * The page renders policy, it never encodes it: status comes from
 * NotificationCategory::isBatchable(), rationale from
 * batchingRationale(), and the defer-window numbers from the very
 * constants NotificationService enforces — so the visible matrix can
 * never drift from what the dispatch path does. The counter columns are
 * validation instrumentation (M082 context: counters populate as volume
 * arrives; today they read zero and that is the honest empty state).
 */
class NotificationBatchingPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 15;

    protected static ?string $title = 'Notification Batching';

    protected static ?string $navigationLabel = 'Notification Batching';

    protected string $view = 'filament.pages.system.notification-batching';

    public function getViewData(): array
    {
        $counters = $this->batchChannelTotals();

        $rows = function (bool $batchable) use ($counters): array {
            $rows = [];

            foreach (NotificationCategory::cases() as $category) {
                if ($category->isBatchable() !== $batchable) {
                    continue;
                }

                $rows[] = [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'group' => $category->group(),
                    'rationale' => $category->batchingRationale(),
                    // Totals are already int (cast once at the aggregate
                    // boundary in batchChannelTotals()).
                    'buffered' => $counters[$category->value]['event_buffered'] ?? 0,
                    'immediate' => $counters[$category->value]['dispatch_immediate'] ?? 0,
                    'flushed' => $counters[$category->value]['flushed'] ?? 0,
                ];
            }

            return $rows;
        };

        $batched = $rows(true);
        $immediate = $rows(false);

        // Zero-volume honesty (M082 data reality) keys on the DEFER
        // WINDOW's own traffic — events that actually entered a window
        // (event_buffered) or flushed out of one (flushed) — not on
        // dispatch_immediate, which every never-batched category bumps on
        // its first post-deploy dispatch. Keying on all three would retire
        // the empty-state note within minutes while the defer window
        // itself has still never fired, implying "measured and silent".
        $deferWindowVolume = array_sum(array_map(
            fn (array $events): int => ($events['event_buffered'] ?? 0) + ($events['flushed'] ?? 0),
            $counters,
        ));

        return [
            'batched' => $batched,
            'immediate' => $immediate,
            'total_volume' => $deferWindowVolume,
            // Zero-volume honesty (M082 data reality): with no defer-window
            // traffic the columns read 0 and this flag renders the
            // empty-state note instead of implying "measured and silent".
            'has_volume' => $deferWindowVolume > 0,
            // Live defer-window state: batches currently holding events
            // (open = processed_at IS NULL — the same predicate the flush
            // paths race against).
            'open_batches' => NotificationBatch::query()->open()->count(),
            'window_minutes' => NotificationService::BATCH_WINDOW_MINUTES,
            'max_items' => NotificationService::BATCH_MAX_ITEMS,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Lifetime per-category totals for the batch channel (the defer
     * window's lifecycle events in the D284 aggregate): event_buffered
     * (event entered the window), dispatch_immediate (event bypassed the
     * window and dispatched now), flushed (a window flushed into one
     * BatchedNotification).
     *
     * One grouped query, no per-row lookups. Categories in the counter
     * table that are not enum cases (type-only values, 'digest', class
     * basenames) simply match no matrix row — they are not batch policy.
     *
     * @return array<string, array<string, int>>
     */
    private function batchChannelTotals(): array
    {
        return DB::table('notification_engagement_counters')
            ->where('channel', 'batch')
            ->whereIn('event', ['event_buffered', 'dispatch_immediate', 'flushed'])
            ->groupBy('category', 'event')
            ->select('category', 'event', DB::raw('SUM(count) as total'))
            ->get()
            ->reduce(function (array $map, object $row): array {
                $map[$row->category][$row->event] = (int) $row->total;

                return $map;
            }, []);
    }
}
