@props(['event'])

<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition-shadow">
    <a href="{{ route('events.detail', $event->slug) }}" wire:navigate class="block">
        {{-- Card Header --}}
        <div class="bg-gradient-to-br from-[#C12E26] to-[#9A231F] dark:from-gray-700 dark:to-gray-800 px-4 py-3">
            <div class="flex items-center justify-between">
                @if($event->is_featured)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-yellow-300">
                        <svg aria-hidden="true" class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        Featured
                    </span>
                @else
                    <span>&nbsp;</span>
                @endif
                @if($event->status === 'registration_open')
                    <span class="text-xs font-medium text-white/80 bg-white/20 px-2 py-0.5 rounded-full">Registration Open</span>
                @endif
            </div>
        </div>

        {{-- Card Body --}}
        <div class="p-4">
            <h3 class="font-heading font-semibold text-gray-900 dark:text-gray-100 text-lg leading-tight line-clamp-2">{{ $event->name }}</h3>

            @if($event->short_description)
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 line-clamp-2">{{ $event->short_description }}</p>
            @endif

            <div class="mt-3 space-y-1.5">
                {{-- Date --}}
                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                    <svg aria-hidden="true" class="w-4 h-4 text-[#C12E26] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    @if($event->start_date && $event->end_date)
                        {{ $event->start_date->format('M j') }} – {{ $event->end_date->format('M j, Y') }}
                    @elseif($event->start_date)
                        {{ $event->start_date->format('M j, Y') }}
                    @endif
                </div>

                {{-- Location --}}
                @if($event->city)
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <svg aria-hidden="true" class="w-4 h-4 text-[#C12E26] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        {{ $event->city }}
                    </div>
                @endif

                {{-- Type --}}
                @if($event->type)
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <svg aria-hidden="true" class="w-4 h-4 text-[#C12E26] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
                        {{ ucfirst($event->type) }}
                    </div>
                @endif
            </div>

            {{-- Fee / Capacity footer --}}
            <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <span class="text-sm font-medium {{ ($event->individual_registration_fee || $event->team_registration_fee) ? 'text-brand-dark' : 'text-green-600 dark:text-green-400' }}">
                    @if($event->individual_registration_fee || $event->team_registration_fee)
                        {{ '$' . ($event->individual_registration_fee ?: $event->team_registration_fee) }}+ to register
                    @else
                        Free Entry
                    @endif
                </span>
                <span class="text-xs text-brand-dark font-medium hover:underline">View Details →</span>
            </div>
        </div>
    </a>
</div>
