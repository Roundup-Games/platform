@php
    $providers = $this->providerBreakdown();
    $refererDomains = $this->topRefererDomains(5);
    $contentTypes = $this->topSignupContentTypes(5);
    $joinSources = $this->joinSourceBreakdown();

    $totalSignups = array_sum($providers);
    $totalParticipants = array_sum($joinSources);
@endphp

<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 mb-4 md:grid-cols-2 xl:grid-cols-4">
        <x-filament::section>
            <x-slot name="heading">Signups by Provider</x-slot>
            <x-slot name="description">Write-once at signup — respects active filters ({{ number_format($totalSignups) }} shown)</x-slot>

            <div class="space-y-2">
                @foreach($providers as $label => $count)
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{ $label }}</span>
                        <span class="text-sm tabular-nums">{{ number_format($count) }}</span>
                    </div>
                @endforeach

                @if(empty($providers) || $totalSignups === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">No signups match the active filters.</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Top Referer Domains</x-slot>
            <x-slot name="description">First-touch referrer hostnames — respects active filters</x-slot>

            <div class="space-y-2">
                @foreach($refererDomains as $domain => $count)
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium truncate" title="{{ $domain }}">{{ $domain }}</span>
                        <span class="text-sm tabular-nums">{{ number_format($count) }}</span>
                    </div>
                @endforeach

                @if(empty($refererDomains))
                    <p class="text-sm text-gray-500 dark:text-gray-400">No referer domains captured for the active filters.</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Top Signup Content Types</x-slot>
            <x-slot name="description">Detected content landing page — respects active filters</x-slot>

            <div class="space-y-2">
                @foreach($contentTypes as $label => $count)
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{ $label }}</span>
                        <span class="text-sm tabular-nums">{{ number_format($count) }}</span>
                    </div>
                @endforeach

                @if(empty($contentTypes))
                    <p class="text-sm text-gray-500 dark:text-gray-400">No content context captured for the active filters.</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Participants by Join Source</x-slot>
            <x-slot name="description">Game + campaign participants — different grain, unfiltered ({{ number_format($totalParticipants) }} total)</x-slot>

            <div class="space-y-2">
                @foreach($joinSources as $label => $count)
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{ $label }}</span>
                        <span class="text-sm tabular-nums">{{ number_format($count) }}</span>
                    </div>
                @endforeach

                @if(empty($joinSources) || $totalParticipants === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">No participants recorded yet.</p>
                @endif
            </div>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
