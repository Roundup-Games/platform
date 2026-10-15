<x-filament-panels::page>
    @if($latest === null)
        <x-filament::section>
            <x-filament::card>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No Search Console snapshot stored yet. Snapshots are captured weekly
                    (Mondays 06:00, <code class="text-xs">seo:gsc-digest</code>) — run it manually
                    to seed the first one. Data accumulates from the day the property was verified.
                </p>
            </x-filament::card>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-filament::card>
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Clicks (28d)</p>
                        <p class="text-2xl font-semibold">{{ number_format($latest->clicks) }}</p>
                        <p class="text-xs {{ $latest->previous_clicks > 0 && $latest->clicks >= $latest->previous_clicks ? 'text-success-600 dark:text-success-400' : 'text-gray-400' }}">
                            vs {{ number_format($latest->previous_clicks) }} prior
                        </p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Impressions (28d)</p>
                        <p class="text-2xl font-semibold">{{ number_format($latest->impressions) }}</p>
                        <p class="text-xs {{ $latest->previous_impressions > 0 && $latest->impressions >= $latest->previous_impressions ? 'text-success-600 dark:text-success-400' : 'text-gray-400' }}">
                            vs {{ number_format($latest->previous_impressions) }} prior
                        </p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Avg CTR</p>
                        <p class="text-2xl font-semibold">{{ number_format($latest->ctr * 100, 2) }}%</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Avg Position</p>
                        <p class="text-2xl font-semibold">{{ number_format($latest->position, 1) }}</p>
                    </div>
                </div>
                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Window {{ $latest->range_start->format('M j, Y') }} – {{ $latest->range_end->format('M j, Y') }}
                </p>
            </x-filament::card>
        </x-filament::section>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section>
                <x-slot:heading>Top Queries</x-slot:heading>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Query</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Impr.</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Clicks</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Pos.</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(array_slice($latest->top_queries ?? [], 0, 15) as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-3 py-2">{{ $row['key'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['impressions']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['clicks']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['position'], 1) }}</td>
                                </tr>
                            @endforeach
                            @if(empty($latest->top_queries))
                                <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400 text-xs">No query data yet — impressions accumulate from property verification.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot:heading>Top Pages</x-slot:heading>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Page</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Impr.</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Clicks</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Pos.</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(array_slice($latest->top_pages ?? [], 0, 15) as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-3 py-2 truncate max-w-[280px]">{{ str_replace('https://roundup.games', '', $row['key']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['impressions']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['clicks']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['position'], 1) }}</td>
                                </tr>
                            @endforeach
                            @if(empty($latest->top_pages))
                                <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400 text-xs">No page data yet.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    @endif

    @if($trend->count() > 1)
        <x-filament::section>
            <x-slot:heading>Snapshot History</x-slot:heading>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Window</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Clicks</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Impressions</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">CTR</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Position</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($trend->reverse() as $snapshot)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2">{{ $snapshot->range_start->format('M j') }} – {{ $snapshot->range_end->format('M j, Y') }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($snapshot->clicks) }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($snapshot->impressions) }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($snapshot->ctr * 100, 2) }}%</td>
                                <td class="px-3 py-2 text-right">{{ number_format($snapshot->position, 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    @if(count($cwv) > 0)
        <x-filament::section>
            <x-slot:heading>Core Web Vitals — latest per template</x-slot:heading>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Template</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Strategy</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Perf.</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">LCP</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">INP</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">CLS</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Checked</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($cwv as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2">{{ $row['template'] }}</td>
                                <td class="px-3 py-2 capitalize">{{ $row['strategy'] }}</td>
                                <td class="px-3 py-2 text-right {{ ($row['performance'] ?? 100) >= 80 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $row['performance'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['lcp_ms'] ? number_format($row['lcp_ms']).'ms' : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['inp_ms'] ? number_format($row['inp_ms']).'ms' : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['cls'] !== null ? number_format($row['cls'], 3) : '—' }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ $row['fetched_at'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Field metrics (LCP/INP/CLS) appear once CrUX has real-user data for the URL. Weekly check: <code class="text-xs">cwv:check</code>.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
