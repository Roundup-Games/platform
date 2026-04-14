<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                Back to Events
            </a>
        </div>
    </div>

    {{-- Event Header / Banner --}}
    <section class="bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">
                    {{ ucfirst($event->type) }}
                </span>
                @if($event->status === 'registration_open')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/30 text-on-primary">
                        ● Registration Open
                    </span>
                @elseif($event->status === 'in_progress')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/30 text-on-primary">
                        ● In Progress
                    </span>
                @elseif($event->status === 'registration_closed')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/30 text-on-primary">
                        ● Registration Closed
                    </span>
                @endif
                @if($event->is_featured)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/10 text-on-primary">
                        ★ Featured
                    </span>
                @endif
            </div>

            <h1 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">{{ $event->name }}</h1>

            @if($event->short_description)
                <p class="mt-3 text-lg text-on-primary/80 max-w-3xl">{{ $event->short_description }}</p>
            @endif

            {{-- Quick info row --}}
            <div class="mt-6 flex flex-wrap gap-6 text-sm text-on-primary/80">
                {{-- Date --}}
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">calendar_today</span>
                    {{ $event->start_date->format('M j, Y') }}
                    @if($event->end_date && $event->end_date->ne($event->start_date))
                        – {{ $event->end_date->format('M j, Y') }}
                    @endif
                </span>

                {{-- Location --}}
                @if($event->venue_name || $event->city)
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">location_on</span>
                        {{ collect([$event->venue_name, $event->city, $event->country])->filter()->join(', ') }}
                    </span>
                @endif

                {{-- Registration Type --}}
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">groups</span>
                    {{ ucfirst($event->registration_type) }} Registration
                </span>
            </div>
        </div>
    </section>

    {{-- Content --}}
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 bg-surface">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Main Content --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Description --}}
                @if($event->description)
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4">About This Event</h2>
                        <div class="prose prose-sm max-w-none text-on-surface-variant">
                            {{ $event->description }}
                        </div>
                    </section>
                @endif

                {{-- Divisions --}}
                @if($event->divisions && count($event->divisions) > 0)
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4">Divisions</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @foreach($event->divisions as $division)
                                <div class="border border-outline-variant rounded-lg p-4">
                                    @if(is_array($division))
                                        <h3 class="font-semibold text-on-surface">{{ $division['name'] ?? 'Division' }}</h3>
                                        @if(isset($division['description']))
                                            <p class="text-sm text-on-surface-variant mt-1">{{ $division['description'] }}</p>
                                        @endif
                                    @else
                                        <h3 class="font-semibold text-on-surface">{{ $division }}</h3>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Schedule --}}
                @if($event->schedule && count($event->schedule) > 0)
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4">Schedule</h2>
                        <div class="space-y-3">
                            @foreach($event->schedule as $item)
                                <div class="flex items-start gap-3 py-2 {{ !$loop->last ? 'border-b border-outline-variant/50' : '' }}">
                                    <div class="w-2 h-2 mt-2 rounded-full bg-primary shrink-0"></div>
                                    <div>
                                        @if(is_array($item))
                                            <p class="text-sm font-medium text-on-surface">{{ $item['date'] ?? '' }} {{ $item['time'] ?? '' }}</p>
                                            <p class="text-sm text-on-surface-variant">{{ $item['event'] ?? $item['title'] ?? '' }}</p>
                                        @else
                                            <p class="text-sm text-on-surface-variant">{{ $item }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach>
                        </div>
                    </section>
                @endif

                {{-- Announcements --}}
                @if($announcements->count())
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4">Announcements</h2>
                        <div class="space-y-4">
                            @foreach($announcements as $announcement)
                                <div class="border-l-4 {{ $announcement->is_pinned ? 'border-primary bg-primary/5' : 'border-outline-variant' }} pl-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-semibold text-on-surface">{{ $announcement->title }}</h3>
                                        @if($announcement->is_pinned)
                                            <span class="text-xs text-primary">📌 Pinned</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-on-surface-variant">{{ $announcement->content }}</p>
                                    <p class="mt-1 text-xs text-on-surface-variant/60">{{ $announcement->created_at->format('M j, Y \a\t g:i A') }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">
                {{-- Registration Card --}}
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-6 sticky top-6">
                    <h3 class="font-heading font-bold tracking-tight text-on-surface">Registration</h3>

                    {{-- Status --}}
                    <div class="mt-4">
                        @if($event->isRegistrationOpen())
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-secondary-container text-on-secondary-container">
                                <span class="w-2 h-2 rounded-full bg-on-secondary-container"></span>
                                Registration Open
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-surface-container text-on-surface-variant">
                                Registration {{ ucfirst(str_replace('_', ' ', $event->status)) }}
                            </span>
                        @endif
                    </div>

                    {{-- Registration Window --}}
                    @if($event->registration_opens_at || $event->registration_closes_at)
                        <div class="mt-4 text-sm space-y-1">
                            @if($event->registration_opens_at)
                                <p class="text-on-surface-variant">
                                    <span class="font-medium text-on-surface">Opens:</span>
                                    {{ $event->registration_opens_at->format('M j, Y \a\t g:i A') }}
                                </p>
                            @endif
                            @if($event->registration_closes_at)
                                <p class="text-on-surface-variant">
                                    <span class="font-medium text-on-surface">Closes:</span>
                                    {{ $event->registration_closes_at->format('M j, Y \a\t g:i A') }}
                                </p>
                            @endif
                        </div>
                    @endif

                    {{-- Capacity --}}
                    <div class="mt-4">
                        <p class="text-sm font-medium text-on-surface mb-2">Capacity</p>
                        @if($event->registration_type === 'team' || $event->registration_type === 'both')
                            <div class="flex items-center justify-between text-sm text-on-surface-variant">
                                <span>Teams</span>
                                <span>{{ $teamCount }}{{ $event->max_teams ? '/' . $event->max_teams : '' }}</span>
                            </div>
                            @if($event->max_teams)
                                @php $teamPct = min(100, ($teamCount / $event->max_teams) * 100) @endphp
                                <div class="mt-1 w-full bg-outline-variant/30 rounded-full h-2">
                                    <div class="h-2 rounded-full {{ $teamPct >= 90 ? 'bg-error' : ($teamPct >= 70 ? 'bg-tertiary' : 'bg-secondary') }}" style="width: {{ $teamPct }}%"></div>
                                </div>
                                @if($teamPct >= 90)
                                    <p class="text-xs text-error mt-1">⚠️ Nearly full!</p>
                                @endif
                            @endif
                        @endif
                        @if($event->registration_type === 'individual' || $event->registration_type === 'both')
                            <div class="flex items-center justify-between text-sm text-on-surface-variant {{ ($event->registration_type === 'both') ? 'mt-2' : '' }}">
                                <span>Participants</span>
                                <span>{{ $individualCount }}{{ $event->max_participants ? '/' . $event->max_participants : '' }}</span>
                            </div>
                            @if($event->max_participants)
                                @php $indPct = min(100, ($individualCount / $event->max_participants) * 100) @endphp
                                <div class="mt-1 w-full bg-outline-variant/30 rounded-full h-2">
                                    <div class="h-2 rounded-full {{ $indPct >= 90 ? 'bg-error' : ($indPct >= 70 ? 'bg-tertiary' : 'bg-secondary') }}" style="width: {{ $indPct }}%"></div>
                                </div>
                                @if($indPct >= 90)
                                    <p class="text-xs text-error mt-1">⚠️ Nearly full!</p>
                                @endif
                            @endif
                        @endif
                    </div>

                    {{-- Fees --}}
                    <div class="mt-4 pt-4 border-t border-outline-variant">
                        <p class="text-sm font-medium text-on-surface mb-2">Fees</p>
                        @if($event->team_registration_fee > 0)
                            <p class="text-sm text-on-surface-variant">
                                Team: ${{ number_format($event->team_registration_fee / 100, 2) }}
                                @if($event->early_bird_discount && $event->early_bird_deadline && now()->lt($event->early_bird_deadline))
                                    <span class="text-secondary ml-1">(Early bird: -${{ number_format($event->early_bird_discount / 100, 2) }})</span>
                                @endif
                            </p>
                        @endif
                        @if($event->individual_registration_fee > 0)
                            <p class="text-sm text-on-surface-variant">
                                Individual: ${{ number_format($event->individual_registration_fee / 100, 2) }}
                                @if($event->early_bird_discount && $event->early_bird_deadline && now()->lt($event->early_bird_deadline))
                                    <span class="text-secondary ml-1">(Early bird: -${{ number_format($event->early_bird_discount / 100, 2) }})</span>
                                @endif
                            </p>
                        @endif
                        @if($event->team_registration_fee === 0 && $event->individual_registration_fee === 0)
                            <p class="text-sm text-secondary font-medium">Free</p>
                        @endif
                    </div>

                    {{-- Register button --}}
                    <div class="mt-6">
                        @if($event->isRegistrationOpen() && $event->hasCapacity())
                            @auth
                                <a href="{{ route('events.register', ['slug' => $event->slug]) }}" wire:navigate class="block w-full text-center px-4 py-3 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity font-medium">
                                    Register Now
                                </a>
                            @else
                                <a href="{{ route('login') }}" wire:navigate class="block w-full text-center px-4 py-3 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity font-medium">
                                    Sign in to Register
                                </a>
                            @endauth
                        @elseif($event->isRegistrationOpen() && !$event->hasCapacity())
                            <button disabled class="block w-full text-center px-4 py-3 bg-surface-container text-on-surface-variant rounded-lg cursor-not-allowed font-medium">
                                Event Full
                            </button>
                        @else
                            <button disabled class="block w-full text-center px-4 py-3 bg-surface-container text-on-surface-variant rounded-lg cursor-not-allowed font-medium">
                                Registration Closed
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Venue Card --}}
                @if($event->venue_name || $event->venue_address)
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">location_on</span>
                            Venue
                        </h3>
                        <div class="mt-3 text-sm text-on-surface-variant space-y-1">
                            @if($event->venue_name)
                                <p class="font-medium text-on-surface">{{ $event->venue_name }}</p>
                            @endif
                            @if($event->venue_address)
                                <p>{{ $event->venue_address }}</p>
                            @endif
                            <p>{{ collect([$event->city, $event->country, $event->postal_code])->filter()->join(', ') }}</p>
                        </div>
                    </div>
                @endif

                {{-- Contact Card --}}
                @if($event->contact_email || $event->contact_phone)
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">mail</span>
                            Contact
                        </h3>
                        <div class="mt-3 text-sm space-y-1">
                            @if($event->contact_email)
                                <p class="text-on-surface-variant">
                                    <a href="mailto:{{ $event->contact_email }}" class="text-primary hover:underline">{{ $event->contact_email }}</a>
                                </p>
                            @endif
                            @if($event->contact_phone)
                                <p class="text-on-surface-variant">{{ $event->contact_phone }}</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
