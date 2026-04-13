<div class="py-8">
    <div class="max-w-3xl mx-auto space-y-6">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('events.index') }}" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-2xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Create Event</h1>
            </div>
            <p class="ml-8 text-sm text-gray-500 dark:text-gray-400">Set up a new event for your participants.</p>
        </div>

        {{-- Step Indicator --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
            <div class="flex items-center justify-between">
                @php
                    $stepLabels = [1 => 'Basic Info', 2 => 'Venue', 3 => 'Fees & Registration', 4 => 'Divisions', 5 => 'Rules & Settings'];
                @endphp
                @foreach($stepLabels as $num => $label)
                    <button wire:click="goToStep({{ $num }})"
                            class="flex items-center gap-2 text-sm {{ $step === $num ? 'text-[#C12E26] font-semibold' : ($step > $num ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-gray-500') }}">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold border-2 {{ $step === $num ? 'border-[#C12E26] bg-[#C12E26] text-white' : ($step > $num ? 'border-green-500 bg-green-500 text-white' : 'border-gray-300 dark:border-gray-600 text-gray-400') }}">
                            @if($step > $num)
                                ✓
                            @else
                                {{ $num }}
                            @endif
                        </span>
                        <span class="hidden sm:inline">{{ $label }}</span>
                    </button>
                    @if(!$loop->last)
                        <div class="flex-1 h-0.5 mx-1 {{ $step > $num ? 'bg-green-500' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- Flash --}}
        @if(session()->has('error'))
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 text-sm text-red-700 dark:text-red-400">
                {{ session('error') }}
            </div>
        @endif

        {{-- Step 1: Basic Info --}}
        @if($step === 1)
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 space-y-4">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 font-['Montserrat']">Basic Information</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Event Name *</label>
                <input type="text" wire:model="name" placeholder="e.g. Summer Slam Tournament 2026"
                       class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Short Description</label>
                <input type="text" wire:model="short_description" maxlength="500" placeholder="One-liner for listings and cards..."
                       class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                @error('short_description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Description</label>
                <textarea wire:model="description" rows="5" placeholder="Detailed event description, what participants can expect..."
                          class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]"></textarea>
                @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Event Type *</label>
                <select wire:model="type" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                    <option value="tournament">Tournament</option>
                    <option value="league">League</option>
                    <option value="camp">Camp</option>
                    <option value="clinic">Clinic</option>
                    <option value="social">Social</option>
                    <option value="other">Other</option>
                </select>
                @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start Date *</label>
                    <input type="date" wire:model="start_date"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('start_date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End Date *</label>
                    <input type="date" wire:model="end_date"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('end_date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 2: Venue --}}
        @if($step === 2)
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 space-y-4">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 font-['Montserrat']">Venue Details</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">All venue fields are optional — fill in what you know.</p>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Venue Name</label>
                <input type="text" wire:model="venue_name" placeholder="e.g. Roundup Arena"
                       class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                @error('venue_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
                <textarea wire:model="venue_address" rows="2" placeholder="Street address..."
                          class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]"></textarea>
                @error('venue_address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">City</label>
                    <input type="text" wire:model="city" placeholder="e.g. Austin"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('city') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Country</label>
                    <input type="text" wire:model="country" maxlength="3" placeholder="e.g. USA"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('country') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Postal Code</label>
                    <input type="text" wire:model="postal_code" placeholder="e.g. 78701"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('postal_code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 3: Fees & Registration --}}
        @if($step === 3)
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 space-y-4">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 font-['Montserrat']">Registration & Fees</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Registration Type *</label>
                <div class="flex gap-3 mt-1">
                    @foreach(['team' => 'Team Only', 'individual' => 'Individual Only', 'both' => 'Both'] as $val => $label)
                        <button type="button" wire:click="$set('registration_type', '{{ $val }}')"
                                class="px-4 py-2 rounded-lg text-sm font-medium border-2 transition-colors {{ $registration_type === $val ? 'border-[#C12E26] bg-[#C12E26]/10 text-[#C12E26]' : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:border-gray-400' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @error('registration_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if(in_array($registration_type, ['team', 'both']))
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max Teams</label>
                    <input type="number" wire:model="max_teams" min="1" placeholder="Unlimited"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('max_teams') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Min Players per Team</label>
                    <input type="number" wire:model="min_players_per_team" min="1"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('min_players_per_team') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max Players per Team</label>
                    <input type="number" wire:model="max_players_per_team" min="1"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('max_players_per_team') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @endif
                @if(in_array($registration_type, ['individual', 'both']))
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max Participants</label>
                    <input type="number" wire:model="max_participants" min="1" placeholder="Unlimited"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('max_participants') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @endif
            </div>

            <h3 class="text-md font-medium text-gray-900 dark:text-gray-100 pt-2">Fees <span class="text-xs text-gray-400">(in cents)</span></h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if(in_array($registration_type, ['team', 'both']))
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Team Registration Fee</label>
                    <input type="number" wire:model="team_registration_fee" min="0" placeholder="0 (free)"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('team_registration_fee') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @endif
                @if(in_array($registration_type, ['individual', 'both']))
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Individual Registration Fee</label>
                    <input type="number" wire:model="individual_registration_fee" min="0" placeholder="0 (free)"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('individual_registration_fee') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @endif
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Early Bird Discount</label>
                    <input type="number" wire:model="early_bird_discount" min="0" placeholder="No discount"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('early_bird_discount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Early Bird Deadline</label>
                    <input type="datetime-local" wire:model="early_bird_deadline"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('early_bird_deadline') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <h3 class="text-md font-medium text-gray-900 dark:text-gray-100 pt-2">Registration Window</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Opens At</label>
                    <input type="datetime-local" wire:model="registration_opens_at"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('registration_opens_at') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Closes At</label>
                    <input type="datetime-local" wire:model="registration_closes_at"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('registration_closes_at') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 4: Divisions --}}
        @if($step === 4)
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 space-y-4">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 font-['Montserrat']">Divisions</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Optional — add competitive divisions to your event.</p>

            {{-- Add Division Form --}}
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Division Name *</label>
                    <input type="text" wire:model="newDivisionName" placeholder="e.g. Open Division"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('newDivisionName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <input type="text" wire:model="newDivisionDescription" placeholder="e.g. For experienced players"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('newDivisionDescription') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <button wire:click="addDivision" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 text-sm font-medium transition-colors">
                    + Add Division
                </button>
            </div>

            {{-- Division List --}}
            @if(!empty($divisions))
                <div class="space-y-2">
                    @foreach($divisions as $i => $division)
                        <div class="flex items-center justify-between bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg px-4 py-3">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $division['name'] }}</p>
                                @if(!empty($division['description']))
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $division['description'] }}</p>
                                @endif
                            </div>
                            <button wire:click="removeDivision({{ $i }})" wire:confirm="Remove this division?"
                                    class="text-gray-400 hover:text-red-500 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-center text-gray-400 dark:text-gray-500 text-sm py-4">No divisions added yet. Skip if not applicable.</p>
            @endif
        </section>
        @endif

        {{-- Step 5: Rules & Settings --}}
        @if($step === 5)
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 space-y-4">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 font-['Montserrat']">Rules & Settings</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Rules <span class="text-xs text-gray-400">(one per line)</span></label>
                <textarea wire:model="rules" rows="5" placeholder="Enter each rule on a separate line..."
                          class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]"></textarea>
                @error('rules') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Schedule <span class="text-xs text-gray-400">(one item per line)</span></label>
                <textarea wire:model="schedule" rows="4" placeholder="Enter schedule items on separate lines..."
                          class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]"></textarea>
                @error('schedule') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contact Email</label>
                    <input type="email" wire:model="contact_email" placeholder="organizer@example.com"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('contact_email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contact Phone</label>
                    <input type="text" wire:model="contact_phone" placeholder="+1 555 123 4567"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('contact_phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-6 pt-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="is_public" class="rounded border-gray-300 text-[#C12E26] focus:ring-[#C12E26]" />
                    <span class="text-sm text-gray-700 dark:text-gray-300">Public Event</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="is_featured" class="rounded border-gray-300 text-[#C12E26] focus:ring-[#C12E26]" />
                    <span class="text-sm text-gray-700 dark:text-gray-300">Featured</span>
                </label>
            </div>
        </section>
        @endif

        {{-- Navigation Buttons --}}
        <div class="flex items-center justify-between">
            <div>
                @if($step > 1)
                    <button wire:click="previousStep"
                            class="px-4 py-2.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm transition-colors">
                        ← Back
                    </button>
                @else
                    <a href="{{ route('events.index') }}"
                       class="px-4 py-2.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm transition-colors">
                        Cancel
                    </a>
                @endif
            </div>
            <div>
                @if($step < self::MAX_STEPS)
                    <button wire:click="nextStep"
                            class="px-6 py-2.5 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                        Next →
                    </button>
                @else
                    <button wire:click="create" wire:loading.attr="disabled"
                            class="px-6 py-2.5 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                        <span wire:loading.remove>Create Event</span>
                        <span wire:loading>Creating...</span>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
