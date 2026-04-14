<div class="py-8 bg-surface">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 space-y-6">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('events.index') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">Create Event</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">Set up a new event for your participants.</p>
        </div>

        {{-- Step Indicator --}}
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4">
            <div class="flex items-center justify-between">
                @php
                    $stepLabels = [1 => 'Basic Info', 2 => 'Venue', 3 => 'Fees & Registration', 4 => 'Divisions', 5 => 'Rules & Settings'];
                @endphp
                @foreach($stepLabels as $num => $label)
                    <button wire:click="goToStep({{ $num }})"
                            class="flex items-center gap-2 text-sm {{ $step === $num ? 'text-primary font-semibold' : ($step > $num ? 'text-secondary' : 'text-on-surface-variant/50') }}">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold border-2 {{ $step === $num ? 'border-primary bg-primary text-on-primary' : ($step > $num ? 'border-secondary bg-secondary text-on-secondary' : 'border-outline text-on-surface-variant/50') }}">
                            @if($step > $num)
                                ✓
                            @else
                                {{ $num }}
                            @endif
                        </span>
                        <span class="hidden sm:inline">{{ $label }}</span>
                    </button>
                    @if(!$loop->last)
                        <div class="flex-1 h-0.5 mx-1 {{ $step > $num ? 'bg-secondary' : 'bg-outline-variant/30' }}"></div>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- Flash --}}
        @if(session()->has('error'))
            <div class="bg-error-container border border-error/20 rounded-lg p-3 text-sm text-on-error-container" role="alert" aria-live="polite">
                {{ session('error') }}
            </div>
        @endif

        {{-- Step 1: Basic Info --}}
        @if($step === 1)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">Basic Information</h2>

            <div>
                <label for="event-name" class="block text-sm font-medium text-on-surface-variant mb-1">Event Name *</label>
                <input type="text" id="event-name" wire:model="name" placeholder="e.g. Summer Slam Tournament 2026"
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="event-short-description" class="block text-sm font-medium text-on-surface-variant mb-1">Short Description</label>
                <input type="text" id="event-short-description" wire:model="short_description" maxlength="500" placeholder="One-liner for listings and cards..."
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                @error('short_description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="event-description" class="block text-sm font-medium text-on-surface-variant mb-1">Full Description</label>
                <textarea id="event-description" wire:model="description" rows="5" placeholder="Detailed event description, what participants can expect..."
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="event-type" class="block text-sm font-medium text-on-surface-variant mb-1">Event Type *</label>
                <select id="event-type" wire:model="type" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                    <option value="tournament">Tournament</option>
                    <option value="league">League</option>
                    <option value="camp">Camp</option>
                    <option value="clinic">Clinic</option>
                    <option value="social">Social</option>
                    <option value="other">Other</option>
                </select>
                @error('type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="event-start-date" class="block text-sm font-medium text-on-surface-variant mb-1">Start Date *</label>
                    <input type="date" id="event-start-date" wire:model="start_date"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('start_date') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-end-date" class="block text-sm font-medium text-on-surface-variant mb-1">End Date *</label>
                    <input type="date" id="event-end-date" wire:model="end_date"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('end_date') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 2: Venue --}}
        @if($step === 2)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">Venue Details</h2>
            <p class="text-sm text-on-surface-variant">All venue fields are optional — fill in what you know.</p>

            <div>
                <label for="event-venue-name" class="block text-sm font-medium text-on-surface-variant mb-1">Venue Name</label>
                <input type="text" id="event-venue-name" wire:model="venue_name" placeholder="e.g. Roundup Arena"
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                @error('venue_name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="event-address" class="block text-sm font-medium text-on-surface-variant mb-1">Address</label>
                <textarea id="event-address" wire:model="venue_address" rows="2" placeholder="Street address..."
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('venue_address') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label for="event-city" class="block text-sm font-medium text-on-surface-variant mb-1">City</label>
                    <input type="text" id="event-city" wire:model="city" placeholder="e.g. Austin"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-country" class="block text-sm font-medium text-on-surface-variant mb-1">Country</label>
                    <input type="text" id="event-country" wire:model="country" maxlength="3" placeholder="e.g. USA"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('country') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-postal-code" class="block text-sm font-medium text-on-surface-variant mb-1">Postal Code</label>
                    <input type="text" id="event-postal-code" wire:model="postal_code" placeholder="e.g. 78701"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('postal_code') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 3: Fees & Registration --}}
        @if($step === 3)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">Registration & Fees</h2>

            <div>
                <label class="block text-sm font-medium text-on-surface-variant mb-1">Registration Type *</label>
                <div class="flex gap-3 mt-1">
                    @foreach(['team' => 'Team Only', 'individual' => 'Individual Only', 'both' => 'Both'] as $val => $label)
                        <button type="button" wire:click="$set('registration_type', '{{ $val }}')"
                                class="px-4 py-2 rounded-lg text-sm font-medium border-2 transition-colors {{ $registration_type === $val ? 'border-primary bg-primary/10 text-primary' : 'border-outline text-on-surface-variant hover:border-outline-variant' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @error('registration_type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if(in_array($registration_type, ['team', 'both']))
                <div>
                    <label for="event-max-teams" class="block text-sm font-medium text-on-surface-variant mb-1">Max Teams</label>
                    <input type="number" id="event-max-teams" wire:model="max_teams" min="1" placeholder="Unlimited"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('max_teams') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-min-players" class="block text-sm font-medium text-on-surface-variant mb-1">Min Players per Team</label>
                    <input type="number" id="event-min-players" wire:model="min_players_per_team" min="1"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('min_players_per_team') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-max-players" class="block text-sm font-medium text-on-surface-variant mb-1">Max Players per Team</label>
                    <input type="number" id="event-max-players" wire:model="max_players_per_team" min="1"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('max_players_per_team') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endif
                @if(in_array($registration_type, ['individual', 'both']))
                <div>
                    <label for="event-max-participants" class="block text-sm font-medium text-on-surface-variant mb-1">Max Participants</label>
                    <input type="number" id="event-max-participants" wire:model="max_participants" min="1" placeholder="Unlimited"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('max_participants') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endif
            </div>

            <h3 class="text-md font-medium text-on-surface pt-2">Fees <span class="text-xs text-on-surface-variant">(in cents)</span></h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if(in_array($registration_type, ['team', 'both']))
                <div>
                    <label for="event-team-fee" class="block text-sm font-medium text-on-surface-variant mb-1">Team Registration Fee</label>
                    <input type="number" id="event-team-fee" wire:model="team_registration_fee" min="0" placeholder="0 (free)"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('team_registration_fee') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endif
                @if(in_array($registration_type, ['individual', 'both']))
                <div>
                    <label for="event-individual-fee" class="block text-sm font-medium text-on-surface-variant mb-1">Individual Registration Fee</label>
                    <input type="number" id="event-individual-fee" wire:model="individual_registration_fee" min="0" placeholder="0 (free)"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('individual_registration_fee') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endif
                <div>
                    <label for="event-early-bird-discount" class="block text-sm font-medium text-on-surface-variant mb-1">Early Bird Discount</label>
                    <input type="number" id="event-early-bird-discount" wire:model="early_bird_discount" min="0" placeholder="No discount"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('early_bird_discount') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-early-bird-deadline" class="block text-sm font-medium text-on-surface-variant mb-1">Early Bird Deadline</label>
                    <input type="datetime-local" id="event-early-bird-deadline" wire:model="early_bird_deadline"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('early_bird_deadline') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <h3 class="text-md font-medium text-on-surface pt-2">Registration Window</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="event-reg-opens" class="block text-sm font-medium text-on-surface-variant mb-1">Opens At</label>
                    <input type="datetime-local" id="event-reg-opens" wire:model="registration_opens_at"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('registration_opens_at') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-reg-closes" class="block text-sm font-medium text-on-surface-variant mb-1">Closes At</label>
                    <input type="datetime-local" id="event-reg-closes" wire:model="registration_closes_at"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('registration_closes_at') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 4: Divisions --}}
        @if($step === 4)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">Divisions</h2>
            <p class="text-sm text-on-surface-variant">Optional — add competitive divisions to your event.</p>

            {{-- Add Division Form --}}
            <div class="bg-surface-container rounded-lg p-4 space-y-3">
                <div>
                    <label for="event-division-name" class="block text-sm font-medium text-on-surface-variant mb-1">Division Name *</label>
                    <input type="text" id="event-division-name" wire:model="newDivisionName" placeholder="e.g. Open Division"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('newDivisionName') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-division-description" class="block text-sm font-medium text-on-surface-variant mb-1">Description</label>
                    <input type="text" id="event-division-description" wire:model="newDivisionDescription" placeholder="e.g. For experienced players"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('newDivisionDescription') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <button wire:click="addDivision" class="px-4 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest text-sm font-medium transition-colors inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    Add Division
                </button>
            </div>

            {{-- Division List --}}
            @if(!empty($divisions))
                <div class="space-y-2">
                    @foreach($divisions as $i => $division)
                        <div class="flex items-center justify-between bg-surface border border-outline-variant rounded-lg px-4 py-3">
                            <div>
                                <p class="font-medium text-on-surface">{{ $division['name'] }}</p>
                                @if(!empty($division['description']))
                                    <p class="text-sm text-on-surface-variant">{{ $division['description'] }}</p>
                                @endif
                            </div>
                            <button wire:click="removeDivision({{ $i }})" wire:confirm="Remove this division?"
                                    class="text-on-surface-variant hover:text-error transition-colors" aria-label="Remove division">
                                <span class="material-symbols-outlined text-xl" aria-hidden="true">delete</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-center text-on-surface-variant/50 text-sm py-4">No divisions added yet. Skip if not applicable.</p>
            @endif
        </section>
        @endif

        {{-- Step 5: Rules & Settings --}}
        @if($step === 5)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">Rules & Settings</h2>

            <div>
                <label for="event-rules" class="block text-sm font-medium text-on-surface-variant mb-1">Rules <span class="text-xs text-on-surface-variant">(one per line)</span></label>
                <textarea id="event-rules" wire:model="rules" rows="5" placeholder="Enter each rule on a separate line..."
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('rules') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="event-schedule" class="block text-sm font-medium text-on-surface-variant mb-1">Schedule <span class="text-xs text-on-surface-variant">(one item per line)</span></label>
                <textarea id="event-schedule" wire:model="schedule" rows="4" placeholder="Enter schedule items on separate lines..."
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('schedule') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="event-contact-email" class="block text-sm font-medium text-on-surface-variant mb-1">Contact Email</label>
                    <input type="email" id="event-contact-email" wire:model="contact_email" placeholder="organizer@example.com"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('contact_email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-contact-phone" class="block text-sm font-medium text-on-surface-variant mb-1">Contact Phone</label>
                    <input type="text" id="event-contact-phone" wire:model="contact_phone" placeholder="+1 555 123 4567"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('contact_phone') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-6 pt-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="event-is-public" wire:model="is_public" class="rounded border-outline text-primary focus:ring-primary/20" />
                    <span class="text-sm text-on-surface-variant">Public Event</span>
                </label>
            </div>
        </section>
        @endif

        {{-- Navigation Buttons --}}
        <div class="flex items-center justify-between">
            <div>
                @if($step > 1)
                    <button wire:click="previousStep"
                            class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                        ← Back
                    </button>
                @else
                    <a href="{{ route('events.index') }}" wire:navigate
                       class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                        Cancel
                    </a>
                @endif
            </div>
            <div>
                @if($step < self::MAX_STEPS)
                    <button wire:click="nextStep"
                            class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
                        Next →
                    </button>
                @else
                    <button wire:click="create" wire:loading.attr="disabled"
                            class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
                        <span wire:loading.remove>Create Event</span>
                        <span wire:loading>Creating...</span>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
