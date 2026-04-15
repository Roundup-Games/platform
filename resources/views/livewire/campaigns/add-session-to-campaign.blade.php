<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('campaigns.detail', $campaign->id) }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('Add Session to Campaign') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{{ __('Create a new game session linked to :campaign.', ['campaign' => $campaign->name]) }}</p>
        </div>

        <form wire:submit="save">
            {{-- Session Details (editable) --}}
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('Session Details') }}</h2>

                <div class="space-y-4">
                    <div>
                        <label for="session-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('Session Name *') }}</label>
                        <input type="text" id="session-name" wire:model="name" placeholder="e.g. Session 3 — The Lost Temple"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="session-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('Description') }}</label>
                        <textarea id="session-description" wire:model="description" rows="3" placeholder="Describe this session..."
                                  class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                        @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="session-date-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('Date & Time *') }}</label>
                        <input type="datetime-local" id="session-date-time" wire:model="date_time"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('date_time') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="session-location-details" class="block text-sm font-medium text-on-surface mb-1">{{ __('Location') }}</label>
                        <input type="text" id="session-location-details" wire:model="location_details" placeholder="Venue name, address, or meeting details"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('location_details') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Campaign Settings (read-only) --}}
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 mt-8">
                <h2 class="text-lg font-medium text-on-surface mb-4 font-heading flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">campaign</span>
                    {{ __('Campaign Settings') }}
                </h2>
                <p class="text-xs text-on-surface-variant mb-4">{{ __('These settings are inherited from the campaign and cannot be changed per session.') }}</p>

                <div class="space-y-3">
                    {{-- Game System --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('Game System') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->gameSystem ? $campaign->gameSystem->name : __('Not set') }}
                        </span>
                    </div>

                    {{-- Visibility --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('Visibility') }}</span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                            {{ $campaign->visibility === 'public' ? 'bg-tertiary/10 text-tertiary' : ($campaign->visibility === 'protected' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant') }}">
                            {{ __(ucfirst($campaign->visibility ?? 'private')) }}
                        </span>
                    </div>

                    {{-- Language --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('Language') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ strtoupper($campaign->language ?? 'en') }}
                        </span>
                    </div>

                    {{-- Players --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('Players') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            @if($campaign->min_players && $campaign->max_players)
                                {{ $campaign->min_players }}–{{ $campaign->max_players }}
                            @elseif($campaign->max_players)
                                {{ __('Up to :max', ['max' => $campaign->max_players]) }}
                            @else
                                {{ __('Not set') }}
                            @endif
                        </span>
                    </div>

                    {{-- Experience Level --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('Experience Level') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->experience_level ? __(ucfirst($campaign->experience_level)) : __('Any') }}
                        </span>
                    </div>

                    {{-- Complexity --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('Complexity') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->complexity ? $campaign->complexity . ' / 5' : __('Not set') }}
                        </span>
                    </div>

                    {{-- Vibe Flags --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('Vibe Flags') }}</span>
                        <div class="flex flex-wrap gap-1 justify-end">
                            @if(is_array($campaign->vibe_flags) && count($campaign->vibe_flags))
                                @foreach($campaign->vibe_flags as $flag)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                        {{ __(ucfirst(str_replace('_', ' ', $flag))) }}
                                    </span>
                                @endforeach
                            @else
                                <span class="text-sm text-on-surface-variant">{{ __('None') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Duration --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('Duration') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->session_duration ? $campaign->session_duration . ' ' . __('hours') : __('Not set') }}
                        </span>
                    </div>

                    {{-- Price --}}
                    <div class="flex items-center justify-between py-2">
                        <span class="text-sm text-on-surface-variant">{{ __('Price') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->price_per_session ? format_currency($campaign->price_per_session, false) : __('Free') }}
                        </span>
                    </div>
                </div>
            </section>

            {{-- Actions --}}
            <div class="flex items-center gap-4 mt-8">
                <button type="submit" wire:loading.attr="disabled"
                        class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium shadow-ambient">
                    <span wire:loading.remove>{{ __('Create Session') }}</span>
                    <span wire:loading>{{ __('Creating...') }}</span>
                </button>
                <a href="{{ route('campaigns.detail', $campaign->id) }}" wire:navigate
                   class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                    {{ __('Cancel') }}
                </a>
            </div>
        </form>
    </div>
</div>
