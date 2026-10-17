<x-filament-panels::page>
    <div class="filament-tables-container space-y-6">
        <x-filament::section>
            <x-slot name="heading">Worker Topology</x-slot>
            <x-slot name="description">
                Effective Horizon supervisor for the <strong>{{ $supervisor['environment'] }}</strong> environment
                (environment overrides merged over defaults — the same merge Horizon performs). Metrics snapshots retain
                {{ $supervisor['snapshot_retention'] }} samples at 5-minute cadence ({{ round($supervisor['snapshot_retention'] * 5 / 60) }}h of graphs).
            </x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr>
                            <td class="px-4 py-2 font-medium w-1/3">Queues served</td>
                            <td class="px-4 py-2">
                                @foreach($supervisor['queues'] as $q)
                                    <span class="inline-flex items-center rounded-full bg-info-100 dark:bg-info-900/30 px-2 py-0.5 text-xs font-medium text-info-700 dark:text-info-300 mr-1">{{ $q }}</span>
                                @endforeach
                            </td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-medium">Balance / max processes</td>
                            <td class="px-4 py-2">{{ $supervisor['balance'] }} · up to {{ $supervisor['max_processes'] }} workers</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-medium">Job timeout / attempts</td>
                            <td class="px-4 py-2">{{ $supervisor['timeout'] }}s · {{ $supervisor['tries'] }} tries · {{ $supervisor['memory_mb'] }}MB memory limit</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-medium">Redis retry_after</td>
                            <td class="px-4 py-2">{{ $supervisor['retry_after'] }}s (must exceed the longest timeout — it does)</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-medium">Long-wait alerts</td>
                            <td class="px-4 py-2">
                                @foreach($supervisor['waits'] as $queue => $seconds)
                                    <span class="text-xs">{{ $queue }} &gt; {{ $seconds }}s</span>@if(!$loop->last) · @endif
                                @endforeach
                                <span class="text-xs text-gray-500">— routed to the operations mailbox</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @foreach($queues as $queue)
            <x-filament::section>
                <x-slot name="heading">Queue: {{ $queue['queue'] }}</x-slot>
                <x-slot name="description">{{ $queue['count'] }} job class{{ $queue['count'] === 1 ? '' : 'es' }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Job</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Tries</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Timeout</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Backoff</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Flags</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Dispatch</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($queue['jobs'] as $job)
                                <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900">
                                    <td class="px-4 py-3">
                                        <span class="font-mono text-xs">{{ $job['short'] }}</span>
                                        @if($job['queue_inferred'])
                                            <br><span class="text-xs text-gray-400">default queue (inferred — no explicit onQueue)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs">{{ $job['tries'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $job['timeout'] !== null ? $job['timeout'].'s' : '—' }}</td>
                                    <td class="px-4 py-3 text-xs">
                                        @if($job['backoff'] === null)
                                            <span class="text-gray-400">—</span>
                                        @else
                                            {{ is_array($job['backoff']) ? implode(', ', array_map(fn ($s) => $s.'s', $job['backoff'])) : $job['backoff'].'s' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($job['unique'])
                                            <span class="inline-flex items-center rounded-full bg-info-100 dark:bg-info-900/30 px-2 py-0.5 text-xs font-medium text-info-700 dark:text-info-300 mr-1">unique</span>
                                        @endif
                                        @if($job['encrypted'])
                                            <span class="inline-flex items-center rounded-full bg-info-100 dark:bg-info-900/30 px-2 py-0.5 text-xs font-medium text-info-700 dark:text-info-300">encrypted</span>
                                        @endif
                                        @if(!$job['unique'] && !$job['encrypted'])
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($job['scheduled'])
                                            <span class="inline-flex items-center rounded-full bg-success-100 dark:bg-success-900/30 px-2 py-0.5 text-xs font-medium text-success-700 dark:text-success-300">Scheduled</span>
                                        @else
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Event / on-demand</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach

        <x-filament::section>
            <x-slot name="heading">Reading this catalog</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                This is the expected fleet, built by reflecting over <code>app/Jobs</code> — it can never drift from the
                codebase, only lag a deploy (cached 5 minutes). Live execution — throughput, wait times, failures,
                per-job runtime — lives in the
                <a href="{{ url('/horizon') }}" target="_blank" class="text-primary-600 dark:text-primary-400 font-medium">Horizon dashboard</a>,
                and scheduled dispatch outcomes on the <a href="{{ \App\Filament\Pages\System\ScheduledTasksPage::getUrl() }}" class="text-primary-600 dark:text-primary-400 font-medium">Scheduled Tasks</a> page.
                Queued notifications (ShouldQueue mails and database notifications) are intentionally not listed here.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
