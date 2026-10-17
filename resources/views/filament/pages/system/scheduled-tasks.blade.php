<x-filament-panels::page>
    <div class="filament-tables-container">
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
                                        @if($task['missed'])
                                            <span class="inline-flex items-center rounded-full bg-danger-100 dark:bg-danger-900/30 px-2 py-0.5 text-xs font-medium text-danger-700 dark:text-danger-300">
                                                Missed occurrence
                                            </span>
                                        @endif
                                    </div>
                                @elseif($task['missed'])
                                    <span class="inline-flex items-center rounded-full bg-danger-100 dark:bg-danger-900/30 px-2 py-0.5 text-xs font-medium text-danger-700 dark:text-danger-300">
                                        Never ran
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
