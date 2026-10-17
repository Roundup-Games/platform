<x-filament-panels::page>
    <div class="filament-tables-container space-y-6">
        @if($health['missed'] > 0 || $health['failed_last'] > 0)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <x-slot name="heading">Scheduled work needs attention</x-slot>
                <ul class="list-disc pl-5 space-y-1 text-sm text-danger-700 dark:text-danger-300">
                    @if($health['missed'] > 0)
                        <li>
                            <strong>{{ $health['missed'] }} task{{ $health['missed'] === 1 ? '' : 's' }}</strong> missed
                            {{ $health['missed'] === 1 ? 'its' : 'their' }} most recent due time — a due occurrence passed with no recorded run.
                            Causes: scheduler downtime, the entry erroring before dispatch, or a stuck <code>withoutOverlapping</code> lock.
                            Check the "Missed occurrence" rows below and the worker container's health.
                        </li>
                    @endif
                    @if($health['failed_last'] > 0)
                        <li>
                            <strong>{{ $health['failed_last'] }} task{{ $health['failed_last'] === 1 ? '' : 's' }}</strong> whose latest run
                            <strong>failed</strong> — the exception message is shown in red on the row below; retried jobs that exhaust their
                            attempts land in <a href="{{ url('/horizon/failed') }}" target="_blank" class="underline">Horizon's failed view</a>.
                        </li>
                    @endif
                </ul>
            </x-filament::section>
        @elseif($health['awaiting_first'] > 0)
            <x-filament::section icon="heroicon-o-information-circle" icon-color="gray">
                <x-slot name="heading">Run tracking warming up — nothing is wrong</x-slot>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Run recording started {{ $recording_since?->diffForHumans() ?? 'just now' }}.
                    <strong>{{ $health['awaiting_first'] }} of {{ $total_tasks }}</strong> tasks haven't reached their first occurrence since —
                    nightly tasks light up in their next window, weekly ones (e.g. the Monday 03:00 BGG sync) at their next slot.
                    Until then they show a neutral "Awaiting first run" badge; only a due time that passes <em>after</em> recording started
                    counts as missed.
                </p>
            </x-filament::section>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Task</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Schedule</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Last Run</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Next Run</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Overlap Guard</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Single Server</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tasks as $task)
                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900">
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ $task['command'] }}</span>
                                @if($task['description'] && $task['description'] !== $task['command'])
                                    <br><span class="text-xs text-gray-500 dark:text-gray-400">{{ $task['description'] }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full bg-info-100 dark:bg-info-900/30 px-2 py-0.5 text-xs font-medium text-info-700 dark:text-info-300">
                                    {{ $task['human_expression'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if($task['last_run'])
                                    <div class="flex flex-col gap-0.5">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                                {{ $task['last_run_color'] === 'success' ? 'bg-success-100 dark:bg-success-900/30 text-success-700 dark:text-success-300' : ($task['last_run_color'] === 'danger' ? 'bg-danger-100 dark:bg-danger-900/30 text-danger-700 dark:text-danger-300' : ($task['last_run_color'] === 'warning' ? 'bg-warning-100 dark:bg-warning-900/30 text-warning-700 dark:text-warning-300' : 'bg-gray-100 dark:bg-gray-900/30 text-gray-600 dark:text-gray-400')) }}">
                                                {{ ucfirst($task['last_run']->status->value) }}
                                            </span>
                                            @if($task['last_run_duration'])
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $task['last_run_duration'] }}</span>
                                            @endif
                                        </span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $task['last_run_ago'] }}</span>
                                        @if($task['last_run']->error)
                                            <span class="text-xs text-danger-600 dark:text-danger-400 truncate max-w-xs" title="{{ $task['last_run']->error }}">{{ $task['last_run']->error }}</span>
                                        @endif
                                        @if($task['health'] === \App\Enums\ScheduledTaskHealth::Missed)
                                            <span class="inline-flex items-center rounded-full bg-danger-100 dark:bg-danger-900/30 px-2 py-0.5 text-xs font-medium text-danger-700 dark:text-danger-300">
                                                Missed occurrence
                                            </span>
                                        @endif
                                    </div>
                                @elseif($task['health'] === \App\Enums\ScheduledTaskHealth::Missed)
                                    <span class="inline-flex items-center rounded-full bg-danger-100 dark:bg-danger-900/30 px-2 py-0.5 text-xs font-medium text-danger-700 dark:text-danger-300">
                                        Never ran — past due
                                    </span>
                                @elseif($task['health'] === \App\Enums\ScheduledTaskHealth::AwaitingFirstRun)
                                    <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-900/30 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                                        Awaiting first run
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs {{ $task['next_run']->isPast() ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                                    {{ $task['next_run']->format('M j, H:i') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if($task['without_overlapping'])
                                    <span class="inline-flex items-center rounded-full bg-warning-100 dark:bg-warning-900/30 px-2 py-0.5 text-xs font-medium text-warning-700 dark:text-warning-300">
                                        {{ $task['without_overlapping'] }}m
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($task['on_one_server'])
                                    <span class="inline-flex items-center rounded-full bg-success-100 dark:bg-success-900/30 px-2 py-0.5 text-xs font-medium text-success-700 dark:text-success-300">
                                        Yes
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    @if(empty($tasks))
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">No scheduled tasks found.</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center justify-between">
            <a href="{{ url('/horizon') }}" target="_blank"
               class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">
                Open Horizon Dashboard
                <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
            </a>
            <span class="text-xs text-gray-400">Run outcomes are written by the scheduler; live queue depth lives in Horizon (recent window: 1h).</span>
        </div>
    </div>
</x-filament-panels::page>
