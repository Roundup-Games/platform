<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('campaigns.action_add_session_to_campaign') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{{ __('campaigns.content_create_new_session_linked_to', ['campaign' => $campaign->name]) }}</p>
        </div>

        <form wire:submit="save">
            {{-- Session Details (editable) --}}
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('campaigns.content_session_details') }}</h2>

                <div class="space-y-4">
                    <div>
                        <label for="session-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_session_name') }} <span class="text-error">*</span></label>
                        <input type="text" id="session-name" wire:model="name" placeholder="e.g. Session 3 — The Lost Temple"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="session-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_description') }}</label>
                        <textarea id="session-description" wire:model="description" rows="3" placeholder="Describe this session..."
                                  class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                        @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="session-date-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_date_time') }}</label>
                        <input type="datetime-local" id="session-date-time" wire:model="date_time"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('date_time') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="session-location-details" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.content_location') }}</label>
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
                    {{ __('campaigns.content_campaign_settings') }}
                </h2>
                <p class="text-xs text-on-surface-variant mb-4">{{ __('campaigns.content_settings_inherited_from_campaign') }}</p>

                <div class="space-y-3">
                    {{-- Game System --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('games.content_game_system') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->gameSystem ? $campaign->gameSystem?->name : __('campaigns.field_not_set') }}
                        </span>
                    </div>

                    {{-- Visibility --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('campaigns.field_visibility') }}</span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                            {{ $campaign->visibility->value === 'public' ? 'bg-tertiary/10 text-tertiary' : ($campaign->visibility->value === 'protected' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant') }}">
                            {{ $campaign->visibility->label() }}
                        </span>
                    </div>

                    {{-- Language --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('common.content_language') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ strtoupper($campaign->language ?? 'en') }}
                        </span>
                    </div>

                    {{-- Players --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('campaigns.field_players') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            @if($campaign->min_players && $campaign->max_players)
                                {{ $campaign->min_players }}–{{ $campaign->max_players }}
                            @elseif($campaign->max_players)
                                {{ __('campaigns.content_up_to_max', ['max' => $campaign->max_players]) }}
                            @else
                                {{ __('campaigns.field_not_set') }}
                            @endif
                        </span>
                    </div>

                    {{-- Experience Level --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('discovery.content_experience_level') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->experience_level ? __(ucfirst($campaign->experience_level)) : __('discovery.content_any') }}
                        </span>
                    </div>

                    {{-- Complexity --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('games.content_complexity') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->complexity ? $campaign->complexity . ' / 5' : __('campaigns.field_not_set') }}
                        </span>
                    </div>

                    {{-- Vibe Flags --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('common.content_vibe_flags') }}</span>
                        <div class="flex flex-wrap gap-1 justify-end">
                            @if(is_array($campaign->vibe_flags) && count($campaign->vibe_flags))
                                @foreach($campaign->vibe_flags as $flag)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                        {{ __(ucfirst(str_replace('_', ' ', $flag))) }}
                                    </span>
                                @endforeach
                            @else
                                <span class="text-sm text-on-surface-variant">{{ __('campaigns.field_none') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Duration --}}
                    <div class="flex items-center justify-between py-2 border-b border-outline-variant/30">
                        <span class="text-sm text-on-surface-variant">{{ __('campaigns.field_duration') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->session_duration ? $campaign->session_duration . ' ' . __('campaigns.field_hours') : __('campaigns.field_not_set') }}
                        </span>
                    </div>

                    {{-- Price --}}
                    <div class="flex items-center justify-between py-2">
                        <span class="text-sm text-on-surface-variant">{{ __('campaigns.field_price') }}</span>
                        <span class="text-sm font-medium text-on-surface">
                            {{ $campaign->price_per_session ? format_currency($campaign->price_per_session, false) : __('billing.content_free') }}
                        </span>
                    </div>
                </div>
            </section>

            {{-- Actions --}}
            <div class="flex items-center gap-4 mt-8">
                <button type="submit" wire:loading.attr="disabled"
                        class="px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium shadow-ambient whitespace-nowrap">
                        {{-- Stable label so the redirect trigger stays in the DOM (M054). --}}
                        <span class="inline-flex items-center gap-2">
                            <span class="material-symbols-outlined text-base" wire:loading.remove wire:target="save" aria-hidden="true">add_circle</span>
                            <span class="material-symbols-outlined text-base animate-spin" wire:loading wire:target="save" aria-hidden="true" role="status" aria-label="{{ __('common.content_creating') }}">progress_activity</span>
                            {{ __('campaigns.action_create_session') }}
                        </span>
                    </button>
                <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate
                   class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                    {{ __('common.action_cancel') }}
                </a>
            </div>
        </form>
    </div>
</div>
