<x-filament-panels::page>
    <x-filament::section>
        <x-filament::card>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Calls ({{ $totals['window_days'] }}d)</p>
                    <p class="text-2xl font-semibold">{{ number_format($totals['window_calls']) }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Active consumers ({{ $totals['window_days'] }}d)</p>
                    <p class="text-2xl font-semibold">{{ number_format($totals['active_consumers']) }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Tools used ({{ $totals['window_days'] }}d)</p>
                    <p class="text-2xl font-semibold">{{ number_format($totals['distinct_tools']) }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Registered consumers</p>
                    <p class="text-2xl font-semibold">{{ number_format($totals['registered_consumers']) }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Calls (all time)</p>
                    <p class="text-2xl font-semibold">{{ number_format($totals['all_time_calls']) }}</p>
                </div>
            </div>
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Dispatched <code class="text-xs">tools/call</code> messages from the agent surface, aggregated from the
                <code class="text-xs">mcp_calls</code> audit trail. Tool catalog: <code class="text-xs">docs/mcp-tools.md</code>.
            </p>
        </x-filament::card>
    </x-filament::section>

    @if($totals['window_calls'] === 0)
        <x-filament::section>
            <x-filament::card>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No MCP tool calls recorded in the last {{ $totals['window_days'] }} days. Telemetry accumulates
                    automatically from the first dispatched <code class="text-xs">tools/call</code> — every authenticated
                    agent call lands here with its consumer, tool, and latency. Consumers authenticate with an MCP token
                    from user settings; the tool catalog lives in <code class="text-xs">docs/mcp-tools.md</code>.
                </p>
            </x-filament::card>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot:heading>Consumers — last {{ $totals['window_days'] }} days</x-slot:heading>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Consumer</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Owner</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Calls</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Tools</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Avg latency</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Last call</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($consumers as $consumer)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2">{{ $consumer['name'] }}</td>
                                <td class="px-3 py-2">{{ $consumer['owner'] }}</td>
                                <td class="px-3 py-2">
                                    @if($consumer['revoked'])
                                        <span class="text-danger-600 dark:text-danger-400">revoked</span>
                                    @else
                                        <span class="text-success-600 dark:text-success-400">active</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">{{ number_format($consumer['calls']) }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($consumer['tools']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $consumer['avg_latency_ms'] !== null ? number_format($consumer['avg_latency_ms']).'ms' : '—' }}</td>
                                <td class="px-3 py-2">{{ $consumer['last_call']?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Revoked consumers stay listed while audit history remains — revocation kills the credential, not the trail.
            </p>
        </x-filament::section>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section>
                <x-slot:heading>Tool breakdown — last {{ $totals['window_days'] }} days</x-slot:heading>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Tool</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Scope</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Calls</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Consumers</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Avg latency</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tools as $tool)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $tool['tool'] }}</td>
                                    <td class="px-3 py-2">{{ $tool['scope'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($tool['calls']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($tool['consumers']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $tool['avg_latency_ms'] !== null ? number_format($tool['avg_latency_ms']).'ms' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Scope resolves live from the registry — a tool that no longer resolves shows <code class="text-xs">(unregistered)</code>.
                </p>
            </x-filament::section>

            <x-filament::section>
                <x-slot:heading>Consumer × tool — top {{ min(50, count($pairs)) }} pairs</x-slot:heading>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Consumer</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Tool</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Calls</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pairs as $pair)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-3 py-2">{{ $pair['consumer'] }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $pair['tool'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($pair['calls']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    @endif

    {{--
        Rejection observability boundary: denials and throttle events are
        rejected BEFORE dispatch, so by design (pinned in
        RateLimitAndAuditTest) they never write audit rows — this page
        aggregates the dispatch trail only. The honest surface says where
        rejections live instead of pretending to count them.
    --}}
    <x-filament::section>
        <x-slot:heading>Rejection observability</x-slot:heading>
        <div class="space-y-3 text-sm text-gray-500 dark:text-gray-400">
            <p>
                Denials and throttle events are rejected before dispatch and therefore never appear as
                <code class="text-xs">mcp_calls</code> rows — the audit trail counts dispatched calls only. Rejections
                surface as structured log events on the default channel:
            </p>
            <ul class="list-disc space-y-1 pl-5">
                <li><code class="text-xs">mcp.reject</code> — reason (<code class="text-xs">unauthenticated</code>, <code class="text-xs">no_mcp_abilities</code>, <code class="text-xs">ability_denied</code>, <code class="text-xs">ops_requires_admin</code>, <code class="text-xs">tool_disabled</code>, <code class="text-xs">undeclared_arguments</code>, <code class="text-xs">dispatch_exception</code>), consumer id, and tool — argument names at most, never values.</li>
                <li><code class="text-xs">mcp.throttle</code> — consumer id, read/write bucket, retry-after seconds, and the limit that was hit.</li>
            </ul>
            <p>
                The tool kill-switch (<code class="text-xs">McpAuthentication::disableTools</code>) refuses disabled tools
                with a 403 at dispatch time without a release; those refusals land in <code class="text-xs">mcp.reject</code>
                as <code class="text-xs">tool_disabled</code>.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
