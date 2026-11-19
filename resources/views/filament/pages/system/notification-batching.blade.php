<x-filament-panels::page>
    <div class="filament-tables-container space-y-6">
        @if(!$has_volume)
            <x-filament::section>
                <div class="flex items-start gap-3 rounded-xl bg-warning-50 px-4 py-3 dark:bg-warning-900/20">
                    <span class="text-warning-600 dark:text-warning-400">⏳</span>
                    <div>
                        <p class="text-sm font-medium text-warning-800 dark:text-warning-200">No batch-channel traffic recorded yet</p>
                        <p class="text-sm text-warning-700 dark:text-warning-300">
                            The counter columns below read 0 because nothing has flowed through the defer window or its
                            bypass since counters began — this is the honest zero, not a broken query. They populate as
                            volume arrives (the batch-vs-immediate split is the M082 validation criterion).
                        </p>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Batched — defer window (v1)</x-slot>
            <x-slot name="description">
                These categories buffer per (recipient × category × entity) for a {{ $window_minutes }}-minute window and
                land as ONE grouped notification at flush — collapsing a burst into a single interrupt.
                MaxSize {{ $max_items }} flushes early. {{ $open_batches }} batch{{ $open_batches === 1 ? '' : 'es' }} currently open.
                Status and rationale are read live from <code>NotificationCategory::isBatchable()</code> — this table
                cannot drift from the dispatch path.
            </x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Category</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Group</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Rationale</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Buffered</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Immediate</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Flushed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($batched as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900">
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full bg-success-100 dark:bg-success-900/30 px-2 py-0.5 text-xs font-medium text-success-700 dark:text-success-300 mr-2">Batched</span>
                                    {{ $row['label'] }}
                                    <br><span class="font-mono text-xs text-gray-400">{{ $row['value'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row['group'] }}</td>
                                <td class="px-4 py-3 text-xs max-w-md">{{ $row['rationale'] }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs">{{ number_format($row['buffered']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs">{{ number_format($row['immediate']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs">{{ number_format($row['flushed']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Never batched — immediate dispatch</x-slot>
            <x-slot name="description">
                Deadline-, plan- and access-critical categories dispatch the moment they happen, even while an open
                batch exists for the same recipient and entity — a hard guarantee the dispatch path enforces and
                bypass-hardening tests pin. Ambient categories appear here too: they have no deadline cost, but also no
                burst shape worth collapsing in v1 (widening the batched set is data-gated on the counters at left).
            </x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Category</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Group</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Rationale</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Buffered</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Immediate</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Flushed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($immediate as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900">
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full bg-warning-100 dark:bg-warning-900/30 px-2 py-0.5 text-xs font-medium text-warning-700 dark:text-warning-300 mr-2">Immediate</span>
                                    {{ $row['label'] }}
                                    <br><span class="font-mono text-xs text-gray-400">{{ $row['value'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row['group'] }}</td>
                                <td class="px-4 py-3 text-xs max-w-md">{{ $row['rationale'] }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs">{{ number_format($row['buffered']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs">{{ number_format($row['immediate']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs">{{ number_format($row['flushed']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Reading this matrix</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                The verdicts are not curated content — every row renders
                <code>NotificationCategory::isBatchable()</code> and its rationale method, and the window/size numbers
                come from the constants <code>NotificationService</code> enforces, so the page can only lag a deploy,
                never disagree with it. The counter columns are lifetime totals from the D284 engagement aggregate
                (channel <code>batch</code>): <strong>Buffered</strong> = events that entered the window,
                <strong>Immediate</strong> = events dispatched without buffering (every non-batched dispatch counts
                here), <strong>Flushed</strong> = windows that landed as one grouped notification. A healthy policy
                shows Immediate volume only in the never-batched table and Buffered/Flushed volume only here.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
