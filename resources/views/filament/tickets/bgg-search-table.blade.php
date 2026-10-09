{{-- BGG search results for the "Search BGG" modal (ViewTicket, game-system-request tickets).
     Rows come from third-party BGG XML: every cell is escaped via {{ }} and the Select
     button is a Livewire wire:click (no inline onclick JS, no unescaped interpolation). --}}
<div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-gray-200 bg-gray-50 dark:bg-gray-800">
        <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium text-center">Year</th>
            <th class="px-3 py-2 font-medium">Type</th>
            <th class="px-3 py-2 font-medium text-center">BGG ID</th>
            <th class="px-3 py-2 font-medium text-center">Action</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($results as $index => $result)
            @php($isSelected = $selectedBggId === $result['bgg_id'])
            @php($typeLabel = match ($result['bgg_type']) {
                'boardgame' => 'Board Game',
                'boardgameexpansion' => 'Expansion',
                'boardgameaccessory' => 'Accessory',
                default => $result['bgg_type'],
            })
            <tr class="border-b border-gray-100 dark:border-gray-700">
                <td class="px-3 py-2 text-sm">{{ $result['name'] }}@if ($isSelected) <span class="inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-400">Selected</span>@endif</td>
                <td class="px-3 py-2 text-sm text-center">{{ $result['year_released'] ?? '—' }}</td>
                <td class="px-3 py-2 text-sm">{{ $typeLabel }}</td>
                <td class="px-3 py-2 text-sm text-center font-mono">{{ $result['bgg_id'] }}</td>
                <td class="px-3 py-2 text-sm text-center">
                    @if ($isSelected)
                        <span class="text-primary-600 dark:text-primary-400 text-xs font-medium">✓ Selected</span>
                    @else
                        <button type="button" wire:click="selectBggResult({{ $index }})" class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">Select</button>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
