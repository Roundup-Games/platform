<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('events.action_back_to_event', ['event' => $event->name]) }}
            </a>
        </div>
    </div>

    {{-- Header --}}
    <section class="bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight">{{ __('events.action_register_for_event') }}</h1>
            <p class="mt-2 text-on-primary/80">{{ $event->name }}</p>
        </div>
    </section>

    {{-- Form --}}
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8 bg-surface">
        {{-- Flash messages --}}
        @if(session()->has('error'))
            <div class="mb-6 bg-error-container border border-error/20 rounded-lg p-4 text-sm text-on-error-container" role="alert" aria-live="polite">
                {{ session('error') }}
            </div>
        @endif

        <form wire:submit="register" class="space-y-6">
            {{-- Registration Mode --}}
            @if($event->registration_type === 'both')
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('events.content_registration_type') }}</h2>
                    <div class="grid grid-cols-2 gap-4">
                        <button type="button"
                            wire:click="$set('registrationMode', 'individual')"
                            class="p-4 rounded-lg border-2 text-center transition-colors {{
                                $registrationMode === 'individual'
                                    ? 'border-primary bg-primary/5'
                                    : 'border-outline-variant hover:border-outline'
                            }}">
                            <span class="material-symbols-outlined text-2xl mb-2 {{ $registrationMode === 'individual' ? 'text-primary' : 'text-on-surface-variant' }}" aria-hidden="true">person</span>
                            <span class="block text-sm font-medium {{ $registrationMode === 'individual' ? 'text-primary' : 'text-on-surface-variant' }}">{{ __('common.content_individual') }}</span>
                        </button>
                        <button type="button"
                            wire:click="$set('registrationMode', 'team')"
                            class="p-4 rounded-lg border-2 text-center transition-colors {{
                                $registrationMode === 'team'
                                    ? 'border-primary bg-primary/5'
                                    : 'border-outline-variant hover:border-outline'
                            }}">
                            <span class="material-symbols-outlined text-2xl mb-2 {{ $registrationMode === 'team' ? 'text-primary' : 'text-on-surface-variant' }}" aria-hidden="true">groups</span>
                            <span class="block text-sm font-medium {{ $registrationMode === 'team' ? 'text-primary' : 'text-on-surface-variant' }}">{{ __('events.content_team') }}</span>
                        </button>
                    </div>
                    @error('registrationMode')
                        <p class="mt-2 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            {{-- Team Selection (team mode only) --}}
            @if($registrationMode === 'team')
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('teams.action_select_team') }}</h2>

                    @if($this->userTeams->isEmpty())
                        <div class="text-center py-6">
                            <p class="text-on-surface-variant text-sm">{{ __('teams.error_you_are_not_a_member_of_any_teams') }}</p>
                            <a href="{{ route('teams.create') }}" wire:navigate class="mt-3 inline-block text-sm text-primary hover:underline">{{ __('teams.action_create_a_team') }}</a>
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach($this->userTeams as $team)
                                <label class="flex items-center gap-3 p-3 rounded-lg border {{ $selectedTeamId === (string) $team->id ? 'border-primary bg-primary/5' : 'border-outline-variant' }} cursor-pointer hover:border-outline transition-colors">
                                    <input type="radio" wire:model.live="selectedTeamId" value="{{ $team->id }}" class="text-primary focus:ring-primary/20" />
                                    <div>
                                        <p class="font-medium text-on-surface">{{ $team->name }}</p>
                                        @if($team->city)
                                            <p class="text-sm text-on-surface-variant">{{ $team->city }}</p>
                                        @endif
                                    </div>
                                    @if(!$team->isCaptain(auth()->user()))
                                        <span class="ml-auto text-xs text-tertiary bg-tertiary/10 px-2 py-0.5 rounded">{{ __('teams.content_non_captain') }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        @error('selectedTeamId')
                            <p class="mt-2 text-sm text-error">{{ $message }}</p>
                        @enderror

                        {{-- Roster preview --}}
                        @if($this->selectedTeam)
                            <div class="mt-4 pt-4 border-t border-outline-variant">
                                <h3 class="text-sm font-medium text-on-surface mb-2">{{ __('teams.content_team_roster_count_members', ['count' => $this->selectedTeam->activeMembers->count()]) }}</h3>
                                <div class="max-h-48 overflow-y-auto space-y-1">
                                    @foreach($this->selectedTeam->activeMembers as $member)
                                        <div class="flex items-center justify-between py-1 text-sm">
                                            <x-user-link :user="$member->user" :show-avatar="false" />
                                            <span class="text-xs text-on-surface-variant/60 uppercase">{{ $member->role }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                @if($event->min_players_per_team || $event->max_players_per_team)
                                    <p class="mt-2 text-xs text-on-surface-variant">
                                        {{ __('events.field_team_size_min_max_players', ['min' => $event->min_players_per_team ?? 0, 'max' => $event->max_players_per_team ?? '∞']) }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            {{-- Division (if applicable) --}}
            @if($event->divisions && count($event->divisions) > 0)
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('events.content_division') }}</h2>
                    <div class="space-y-2">
                        @foreach($event->divisions as $div)
                            @php $divName = is_array($div) ? ($div['name'] ?? '') : $div @endphp
                            <label class="flex items-center gap-3 p-3 rounded-lg border {{ $division === $divName ? 'border-primary bg-primary/5' : 'border-outline-variant' }} cursor-pointer hover:border-outline transition-colors">
                                <input type="radio" wire:model="division" value="{{ $divName }}" class="text-primary focus:ring-primary/20" />
                                <div>
                                    <p class="font-medium text-on-surface">{{ $divName }}</p>
                                    @if(is_array($div) && isset($div['description']))
                                        <p class="text-sm text-on-surface-variant">{{ $div['description'] }}</p>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Notes --}}
            <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('common.field_additional_notes') }}</h2>
                <textarea id="registration-notes" wire:model="notes" rows="3" placeholder="{{ __('common.content_any_special_requests_dietary_requirements') }}"
                    class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm"></textarea>
                @error('notes')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Fee Summary --}}
            <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('billing.field_fee_summary') }}</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">
                            {{ __(ucfirst($registrationMode)) . ' ' . __('events.content_registration') }}
                        </span>
                        <span class="text-on-surface">
                            @php
                                $baseFee = $registrationMode === 'team' ? ($event->team_registration_fee ?? 0) : ($event->individual_registration_fee ?? 0);
                            @endphp
                            {{ $baseFee > 0 ? format_currency($baseFee) : __('billing.content_free') }}
                        </span>
                    </div>
                    @if($this->isEarlyBird && $event->early_bird_discount > 0)
                        <div class="flex justify-between text-secondary">
                            <span>{{ __('billing.content_early_bird_discount') }}</span>
                            <span>-{{ format_currency($event->early_bird_discount) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between pt-2 border-t border-outline-variant font-medium">
                        <span class="text-on-surface">{{ __('common.content_total') }}</span>
                        <span class="text-on-surface {{ $this->effectiveFee === 0 ? 'text-secondary' : '' }}">
                            {{ $this->effectiveFee > 0 ? format_currency($this->effectiveFee) : __('billing.content_free') }}
                        </span>
                    </div>
                </div>
                @if($this->isEarlyBird)
                    <p class="mt-3 text-xs text-secondary">
                        {{ __('billing.field_early_bird_pricing_ends_date', ['date' => format_date($event->early_bird_deadline, 'datetime')]) }}
                    </p>
                @endif
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-between">
                <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="text-sm text-on-surface-variant hover:text-on-surface">
                    {{ __('common.action_cancel') }}
                </a>
                <button type="submit"
                    class="px-6 py-3 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ $this->effectiveFee > 0 ? __('billing.action_proceed_to_payment') : __('events.content_complete_registration') }}
                </button>
            </div>
        </form>
    </div>
</div>
