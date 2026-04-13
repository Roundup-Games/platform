<div>
    {{-- Back link --}}
    <div class="bg-gray-100 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to {{ $event->name }}
            </a>
        </div>
    </div>

    {{-- Header --}}
    <section class="bg-gradient-to-br from-[#C12E26] to-[#9A231F] dark:from-gray-800 dark:to-gray-900 text-white">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-2xl sm:text-3xl font-heading font-bold uppercase tracking-wide">Register for Event</h1>
            <p class="mt-2 text-white/80">{{ $event->name }}</p>
        </div>
    </section>

    {{-- Form --}}
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
        {{-- Flash messages --}}
        @if(session()->has('error'))
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 text-sm text-red-700 dark:text-red-400" role="alert" aria-live="polite">
                {{ session('error') }}
            </div>
        @endif

        <form wire:submit="register" class="space-y-6">
            {{-- Registration Mode --}}
            @if($event->registration_type === 'both')
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                    <h2 class="text-lg font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Registration Type</h2>
                    <div class="grid grid-cols-2 gap-4">
                        <button type="button"
                            wire:click="$set('registrationMode', 'individual')"
                            class="p-4 rounded-lg border-2 text-center transition-colors {{
                                $registrationMode === 'individual'
                                    ? 'border-[#C12E26] bg-[#C12E26]/5 dark:bg-[#C12E26]/10'
                                    : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'
                            }}">
                            <svg aria-hidden="true" class="w-8 h-8 mx-auto mb-2 {{ $registrationMode === 'individual' ? 'text-brand-dark' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                            <span class="block text-sm font-medium {{ $registrationMode === 'individual' ? 'text-brand-dark' : 'text-gray-600 dark:text-gray-400' }}">Individual</span>
                        </button>
                        <button type="button"
                            wire:click="$set('registrationMode', 'team')"
                            class="p-4 rounded-lg border-2 text-center transition-colors {{
                                $registrationMode === 'team'
                                    ? 'border-[#C12E26] bg-[#C12E26]/5 dark:bg-[#C12E26]/10'
                                    : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'
                            }}">
                            <svg aria-hidden="true" class="w-8 h-8 mx-auto mb-2 {{ $registrationMode === 'team' ? 'text-brand-dark' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            <span class="block text-sm font-medium {{ $registrationMode === 'team' ? 'text-brand-dark' : 'text-gray-600 dark:text-gray-400' }}">Team</span>
                        </button>
                    </div>
                    @error('registrationMode')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            {{-- Team Selection (team mode only) --}}
            @if($registrationMode === 'team')
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                    <h2 class="text-lg font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Select Team</h2>

                    @if($this->userTeams->isEmpty())
                        <div class="text-center py-6">
                            <p class="text-gray-500 dark:text-gray-400 text-sm">You are not a member of any teams.</p>
                            <a href="{{ route('teams.create') }}" wire:navigate class="mt-3 inline-block text-sm text-brand-dark hover:underline">Create a Team</a>
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach($this->userTeams as $team)
                                <label class="flex items-center gap-3 p-3 rounded-lg border {{ $selectedTeamId === (string) $team->id ? 'border-[#C12E26] bg-[#C12E26]/5 dark:bg-[#C12E26]/10' : 'border-gray-200 dark:border-gray-700' }} cursor-pointer hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                                    <input type="radio" wire:model.live="selectedTeamId" value="{{ $team->id }}" class="text-[#C12E26] focus:ring-[#C12E26]" />
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $team->name }}</p>
                                        @if($team->city)
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $team->city }}</p>
                                        @endif
                                    </div>
                                    @if(!$team->isCaptain(auth()->user()))
                                        <span class="ml-auto text-xs text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-900/20 px-2 py-0.5 rounded">Non-captain</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        @error('selectedTeamId')
                            <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror

                        {{-- Roster preview --}}
                        @if($this->selectedTeam)
                            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Team Roster ({{ $this->selectedTeam->activeMembers->count() }} members)</h3>
                                <div class="max-h-48 overflow-y-auto space-y-1">
                                    @foreach($this->selectedTeam->activeMembers as $member)
                                        <div class="flex items-center justify-between py-1 text-sm">
                                            <span class="text-gray-700 dark:text-gray-300">{{ $member->user?->name ?? 'Unknown' }}</span>
                                            <span class="text-xs text-gray-400 uppercase">{{ $member->role }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                @if($event->min_players_per_team || $event->max_players_per_team)
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        Team size: {{ $event->min_players_per_team ?? 0 }}–{{ $event->max_players_per_team ?? '∞' }} players
                                    </p>
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            {{-- Division (if applicable) --}}
            @if($event->divisions && count($event->divisions) > 0)
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                    <h2 class="text-lg font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Division</h2>
                    <div class="space-y-2">
                        @foreach($event->divisions as $div)
                            @php $divName = is_array($div) ? ($div['name'] ?? '') : $div @endphp
                            <label class="flex items-center gap-3 p-3 rounded-lg border {{ $division === $divName ? 'border-[#C12E26] bg-[#C12E26]/5 dark:bg-[#C12E26]/10' : 'border-gray-200 dark:border-gray-700' }} cursor-pointer hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                                <input type="radio" wire:model="division" value="{{ $divName }}" class="text-[#C12E26] focus:ring-[#C12E26]" />
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $divName }}</p>
                                    @if(is_array($div) && isset($div['description']))
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $div['description'] }}</p>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Notes --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <h2 class="text-lg font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Additional Notes</h2>
                <textarea id="registration-notes" wire:model="notes" rows="3" placeholder="Any special requests, dietary requirements, or notes for the organizer..."
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] text-sm"></textarea>
                @error('notes')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            {{-- Fee Summary --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <h2 class="text-lg font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Fee Summary</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600 dark:text-gray-400">
                            {{ ucfirst($registrationMode) }} Registration
                        </span>
                        <span class="text-gray-900 dark:text-gray-100">
                            @php
                                $baseFee = $registrationMode === 'team' ? ($event->team_registration_fee ?? 0) : ($event->individual_registration_fee ?? 0);
                            @endphp
                            {{ $baseFee > 0 ? '$' . number_format($baseFee / 100, 2) : 'Free' }}
                        </span>
                    </div>
                    @if($this->isEarlyBird && $event->early_bird_discount > 0)
                        <div class="flex justify-between text-green-600 dark:text-green-400">
                            <span>Early Bird Discount</span>
                            <span>-${{ number_format($event->early_bird_discount / 100, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-gray-700 font-medium">
                        <span class="text-gray-900 dark:text-gray-100">Total</span>
                        <span class="text-gray-900 dark:text-gray-100 {{ $this->effectiveFee === 0 ? 'text-green-600 dark:text-green-400' : '' }}">
                            {{ $this->effectiveFee > 0 ? '$' . number_format($this->effectiveFee / 100, 2) : 'Free' }}
                        </span>
                    </div>
                </div>
                @if($this->isEarlyBird)
                    <p class="mt-3 text-xs text-green-600 dark:text-green-400">
                        🏷️ Early bird pricing ends {{ $event->early_bird_deadline->format('M j, Y \a\t g:i A') }}
                    </p>
                @endif
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-between">
                <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
                    Cancel
                </a>
                <button type="submit"
                    class="px-6 py-3 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ $this->effectiveFee > 0 ? 'Proceed to Payment' : 'Complete Registration' }}
                </button>
            </div>
        </form>
    </div>
</div>
