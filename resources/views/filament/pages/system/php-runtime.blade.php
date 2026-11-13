<x-filament-panels::page>
    <div class="filament-tables-container space-y-6">
        @if($opcache === null)
            <x-filament::section icon="heroicon-o-information-circle" icon-color="gray">
                <x-slot name="heading">Runtime metrics unavailable in this context</x-slot>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    OPcache/FPM status reflects the serving SAPI. This page was rendered outside PHP-FPM
                    (CLI or the test runner), where those counters are not exposed. In production the
                    same page reports the live web runtime. The configured settings below are still shown.
                </p>
            </x-filament::section>
        @else
            @php
                $hitRate = $opcache['opcache_statistics']['opcache_hit_rate'] ?? null;
                $cached = $opcache['opcache_statistics']['num_cached_scripts'] ?? 0;
                $memUsed = $opcache['memory_usage']['used_memory'] ?? 0;
                $memFree = $opcache['memory_usage']['free_memory'] ?? 0;
                $wastedPct = $opcache['memory_usage']['current_wasted_percentage'] ?? 0;
                $memTotal = $memUsed + $memFree;
                $internedUsed = $opcache['interned_strings_usage']['used_memory'] ?? 0;
                $internedTotal = ($opcache['interned_strings_usage']['buffer_size'] ?? 0) / 1024 / 1024;
                $hitRateColor = $hitRate !== null && $hitRate >= 99 ? 'success' : ($hitRate !== null && $hitRate >= 95 ? 'warning' : 'danger');
                $memPct = $memTotal > 0 ? $memUsed / $memTotal * 100 : 0;
                $memColor = $memPct >= 90 ? 'danger' : ($memPct >= 80 ? 'warning' : 'success');
            @endphp
            <x-filament::section icon="heroicon-o-archive-box" icon-color="primary">
                <x-slot name="heading">OPcache</x-slot>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Hit rate</div>
                        <div class="text-lg font-semibold text-{{ $hitRateColor }}-700 dark:text-{{ $hitRateColor }}-300">
                            {{ number_format((float) $hitRate, 2) }}%
                        </div>
                        <div class="text-xs text-gray-400">alert below 99% once warm</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Cached scripts</div>
                        <div class="text-lg font-semibold text-on-surface">
                            {{ number_format((float) $cached) }} /
                            {{ number_format((float) $settings['max_accelerated_files']) }}
                        </div>
                        <div class="text-xs text-gray-400">
                            {{ number_format($cached / max(1, (int) $settings['max_accelerated_files']) * 100, 1) }}% of cap — scripts past the cap silently recompile per request
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Memory</div>
                        <div class="text-lg font-semibold text-{{ $memColor }}-700 dark:text-{{ $memColor }}-300">
                            {{ number_format($memUsed / 1024 / 1024, 1) }} /
                            {{ $settings['memory_consumption'] }} MB
                        </div>
                        <div class="text-xs text-gray-400">{{ number_format($memPct, 1) }}% used, {{ number_format((float) $wastedPct, 1) }}% wasted</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Interned strings</div>
                        <div class="text-lg font-semibold text-on-surface">
                            {{ number_format($internedUsed / 1024 / 1024, 1) }} /
                            {{ number_format((float) $internedTotal, 0) }} MB
                        </div>
                        <div class="text-xs text-gray-400">deduplicated class/method/literal names</div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        @if($fpm !== null)
            @php
                $maxChildrenReached = $fpm['max-children-reached'] ?? 0;
                $listenQueue = $fpm['listen-queue'] ?? 0;
                $slowRequests = $fpm['slow-requests'] ?? 0;
                $poolColor = ($listenQueue > 0 || ($fpm['active-processes'] ?? 0) >= ($pool['max_children'] ?? PHP_INT_MAX))
                    ? 'danger'
                    : ($maxChildrenReached > 0 ? 'warning' : 'success');
            @endphp
            <x-filament::section icon="heroicon-o-server-stack" icon-color="primary">
                <x-slot name="heading">FPM pool</x-slot>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Processes</div>
                        <div class="text-lg font-semibold text-on-surface">
                            {{ $fpm['active-processes'] ?? '?' }} active /
                            {{ $fpm['idle-processes'] ?? '?' }} idle
                        </div>
                        <div class="text-xs text-gray-400">of {{ $pool['max_children'] ?? '?' }} max ({{ $pool['control'] ?? 'image default' }})</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Listen queue</div>
                        <div class="text-lg font-semibold text-{{ $poolColor }}-700 dark:text-{{ $poolColor }}-300">
                            {{ $listenQueue }}
                        </div>
                        <div class="text-xs text-gray-400">requests waiting for a worker — should stay 0</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Max children reached</div>
                        <div class="text-lg font-semibold text-on-surface">{{ $maxChildrenReached }}</div>
                        <div class="text-xs text-gray-400">cumulative — growth means pool saturation</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Slow requests</div>
                        <div class="text-lg font-semibold text-on-surface">{{ $slowRequests }}</div>
                        <div class="text-xs text-gray-400">cumulative</div>
                    </div>
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">Accepted conn</div>
                        <div class="text-lg font-semibold text-on-surface">{{ number_format((float) ($fpm['accepted-conn'] ?? 0)) }}</div>
                        <div class="text-xs text-gray-400">since pool start</div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section icon="heroicon-o-cog-6-tooth" icon-color="gray">
            <x-slot name="heading">Configuration</x-slot>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
                <div>
                    <div class="text-gray-500 dark:text-gray-400">validate_timestamps</div>
                    <div class="font-semibold">{{ $settings['validate_timestamps'] ? 'on' : 'off (immutable deploys)' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">JIT</div>
                    <div class="font-semibold">{{ $settings['jit'] === 'disable' || $settings['jit'] === '0' ? 'disabled' : $settings['jit'] }}</div>
                    <div class="text-xs text-gray-400">deliberate — see Dockerfile incident note</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">memory_consumption</div>
                    <div class="font-semibold">{{ $settings['memory_consumption'] }} MB</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">max_accelerated_files</div>
                    <div class="font-semibold">{{ number_format((float) $settings['max_accelerated_files']) }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">interned_strings_buffer</div>
                    <div class="font-semibold">{{ $settings['interned_strings_buffer'] }} MB</div>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
