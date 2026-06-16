@php
    $welcome = $newcomerWelcome ?? [];
    $matches = $preferenceMatches ?? [];
    $tracker = $progressTracker ?? [];
    $people = $nearbyPeople ?? [];
    $hasWelcome = !empty($welcome);
    $hasMatches = !empty($matches['games']);
    $hasPeople = !empty($people['people']);
    $hasTracker = !empty($tracker['steps']);
@endphp

{{-- ═══ Welcome Card ═══ --}}
@if($hasWelcome)
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8" role="status" aria-live="polite">
    <div class="flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
            <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1" aria-hidden="true">waving_hand</span>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-xl font-heading font-semibold text-on-surface">
                {{ __('profile.dashboard_newcomer_welcome', ['name' => $welcome['first_name'] ?? '']) }}
            </h2>

            @if(($welcome['city'] ?? null))
                <p class="text-sm text-on-surface-variant mt-1 flex items-center gap-1">
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">location_on</span>
                    {{ $welcome['city'] }}
                </p>
            @endif

            {{-- Preferred systems badges --}}
            @if(!empty($welcome['preferred_systems']))
                <div class="flex flex-wrap gap-1.5 mt-3">
                    @foreach($welcome['preferred_systems'] as $system)
                        <span class="inline-flex items-center gap-1 text-xs font-medium px-2.5 py-1 rounded-full bg-primary/10 text-primary">
                            <span class="material-symbols-outlined text-xs" aria-hidden="true">casino</span>
                            {{ $system }}
                        </span>
                    @endforeach
                </div>
            @endif

            {{-- Matching games count or CTA --}}
            @if(($welcome['matching_games_count'] ?? 0) > 0)
                <p class="text-sm text-on-surface-variant mt-3">
                    {{ trans_choice('profile.dashboard_newcomer_matching_games', $welcome['matching_games_count'], ['count' => $welcome['matching_games_count']]) }}
                </p>
            @elseif(empty($welcome['preferred_systems']) || !($welcome['has_location'] ?? false))
                <div class="mt-4">
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                        <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1" aria-hidden="true">add_circle</span>
                        {{ __('profile.dashboard_newcomer_be_first') }}
                    </a>
                </div>
            @endif
        </div>

        {{-- Unread notifications badge --}}
        @if(($unreadNotificationsCount ?? 0) > 0)
            <a href="{{ route('notifications.index') }}" wire:navigate
               aria-label="{{ $unreadNotificationsCount }} {{ __('profile.dashboard_stats_unread_notifications') }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 text-primary text-sm font-medium hover:bg-primary/20 transition-colors shrink-0">
                <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">notifications</span>
                {{ $unreadNotificationsCount }} {{ __('profile.dashboard_stats_unread_notifications') }}
            </a>
        @endif
    </div>
</div>
@endif

{{-- ═══ Smart Prompt (if present) ═══ --}}
@if(isset($smartPrompt) && ($smartPrompt['message'] ?? null))
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-4 sm:p-5" role="status" aria-live="polite">
    <div class="flex items-start gap-3">
        <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl mt-0.5 shrink-0"
              style="font-variation-settings: 'FILL' 0">auto_awesome</span>
        <div class="min-w-0">
            <p class="text-on-surface text-sm leading-relaxed">{{ $smartPrompt['message'] }}</p>
            @if($smartPrompt['action_url'] ?? null)
                <a href="{{ $smartPrompt['action_url'] }}" wire:navigate
                   class="inline-flex items-center gap-1.5 mt-2 text-sm font-semibold text-primary hover:underline">
                    {{ $smartPrompt['action_label'] ?? __('profile.dashboard_prompt_view_details') }}
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
                </a>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ═══ Your Best Matches ═══ --}}
@if($hasMatches)
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
        <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">recommend</span>
        {{ __('profile.dashboard_newcomer_best_matches_heading') }}
    </h3>

    {{-- Horizontal scrollable card row --}}
    <div class="flex gap-3 overflow-x-auto pb-2 -mx-1 px-1 snap-x snap-mandatory" role="list" aria-label="{{ __('profile.dashboard_newcomer_best_matches_heading') }}">
        @foreach($matches['games'] as $game)
            @php
                $relevanceTags = $game['relevance_tags'] ?? [];
                $primaryTag = null;
                $tagColor = 'primary';
                if ($relevanceTags['matches_your_taste'] ?? false) {
                    $primaryTag = __('profile.dashboard_newcomer_relevance_matches_taste');
                    $tagColor = 'primary';
                } elseif ($relevanceTags['popular_nearby'] ?? false) {
                    $primaryTag = __('profile.dashboard_newcomer_relevance_popular');
                    $tagColor = 'secondary';
                } elseif ($relevanceTags['starting_soon'] ?? false) {
                    $primaryTag = __('profile.dashboard_newcomer_relevance_starting_soon');
                    $tagColor = 'tertiary';
                } elseif ($relevanceTags['filling_fast'] ?? false) {
                    $primaryTag = __('profile.dashboard_newcomer_relevance_filling_fast');
                    $tagColor = 'error';
                }
                $dateRelative = isset($game['date_time'])
                    ? \Carbon\Carbon::parse($game['date_time'])->diffForHumans(['short' => true])
                    : null;
                $spotsLeft = ($game['max_players'] ?? 0) - ($game['participant_count'] ?? 0);
            @endphp
            <a href="{{ route('games.show', $game['id']) }}" wire:navigate
               class="shrink-0 w-56 sm:w-64 snap-start bg-surface-container-low rounded-xl border border-outline-variant/30 hover:border-primary/40 hover:shadow-ambient-md transition-all p-4 group"
               role="listitem">
                <div class="min-w-0">
                    {{-- System badge --}}
                    @if($game['game_system_name'] ?? null)
                        <span class="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-primary/10 text-primary mb-2">
                            {{ $game['game_system_name'] }}
                        </span>
                    @endif

                    {{-- Game name --}}
                    <p class="text-sm font-semibold text-on-surface group-hover:text-primary transition-colors truncate leading-tight">
                        {{ $game['name'] }}
                    </p>

                    {{-- Date/time relative --}}
                    @if($dateRelative)
                        <p class="text-xs text-on-surface-variant mt-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                            {{ $dateRelative }}
                        </p>
                    @endif

                    {{-- Spots indicator --}}
                    @if($spotsLeft > 0)
                        <p class="text-xs text-on-surface-variant mt-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-xs" aria-hidden="true">group</span>
                            {{ trans_choice('profile.dashboard_newcomer_spots', $spotsLeft, ['count' => $spotsLeft]) }}
                        </p>
                    @endif

                    {{-- Relevance tag pill --}}
                    @if($primaryTag)
                        <span class="inline-block mt-2 text-[10px] font-semibold px-2 py-0.5 rounded-full {{ $tagColor === 'primary' ? 'bg-primary/10 text-primary' : ($tagColor === 'secondary' ? 'bg-secondary/10 text-secondary' : ($tagColor === 'tertiary' ? 'bg-tertiary/10 text-tertiary' : 'bg-error/10 text-error')) }}">
                            {{ $primaryTag }}
                        </span>
                    @endif

                    {{-- Distance (D060 grid-snap; cached array has no Location model) --}}
                    @if($game['distance_km'] ?? null)
                        <p class="text-[10px] text-on-surface-variant mt-1.5">
                            <x-distance-display :precise-km="$game['distance_km']" grid-snap icon="" />
                        </p>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
</div>
@elseif($hasWelcome && ($welcome['has_location'] ?? false) && !empty($welcome['preferred_systems']))
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 text-center">
    <span class="material-symbols-outlined text-on-surface-variant text-4xl" aria-hidden="true">search_off</span>
    <p class="text-on-surface-variant text-sm mt-2">{{ __('profile.dashboard_newcomer_no_matches') }}</p>
    <a href="{{ route('games.create') }}" wire:navigate
       class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
        <span class="material-symbols-outlined text-lg" aria-hidden="true">add_circle</span>
        {{ __('profile.dashboard_newcomer_be_first_cta') }}
    </a>
</div>
@endif

{{-- ═══ Getting Started — Progress Tracker ═══ --}}
@if($hasTracker)
@php
    $steps = $tracker['steps'] ?? [];
    $currentStep = $tracker['current_step'] ?? 1;
    $stepIcons = ['person', 'tune', 'search', 'celebration'];
    $stepRoutes = ['profile.edit', 'profile.edit', 'discover', 'games.index'];
@endphp
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
        <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">route</span>
        {{ __('profile.dashboard_newcomer_progress_heading') }}
        @if(($tracker['completion_percentage'] ?? 0) > 0)
            <span class="ml-auto text-sm font-normal text-on-surface-variant">
                {{ $tracker['completion_percentage'] }}%
            </span>
        @endif
    </h3>

    {{-- Horizontal progress steps --}}
    <div class="flex items-start justify-between gap-2 sm:gap-4">
        @foreach($steps as $index => $step)
            @php
                $stepNumber = $index + 1;
                $isComplete = $step['is_complete'] ?? false;
                $isCurrent = $stepNumber === $currentStep && !$isComplete;
                $isLast = $stepNumber === count($steps);
                $icon = $stepIcons[$index] ?? 'circle';
                $route = $stepRoutes[$index] ?? 'games.index';
            @endphp
            <div class="flex flex-col items-center flex-1 min-w-0">
                {{-- Step circle with icon --}}
                <a href="{{ route($route) }}" wire:navigate
                   class="flex items-center justify-center w-10 h-10 rounded-full transition-colors {{ $isComplete ? 'bg-primary text-on-primary' : ($isCurrent ? 'bg-primary/20 text-primary ring-2 ring-primary' : 'bg-surface-container-high text-on-surface-variant') }}"
                   aria-label="{{ $step['name'] }} {{ $isComplete ? '✓' : ($isCurrent ? '(' . __('profile.dashboard_newcomer_step_current') . ')' : '') }}">
                    @if($isComplete)
                        <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1" aria-hidden="true">check</span>
                    @else
                        <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' {{ $isCurrent ? '1' : '0' }}" aria-hidden="true">{{ $icon }}</span>
                    @endif
                </a>

                {{-- Step label --}}
                <p class="text-[11px] sm:text-xs font-medium mt-1.5 text-center leading-tight {{ $isComplete ? 'text-primary' : ($isCurrent ? 'text-on-surface' : 'text-on-surface-variant') }}">
                    {{ $step['name'] }}
                </p>
            </div>
        @endforeach
    </div>

    {{-- Progress bar --}}
    @if(($tracker['completion_percentage'] ?? 0) < 100)
    <div class="mt-4 h-1.5 bg-surface-container-high rounded-full overflow-hidden" role="progressbar"
         aria-label="{{ __('profile.dashboard_newcomer_progress_heading') }}" aria-valuenow="{{ $tracker['completion_percentage'] ?? 0 }}" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full bg-primary rounded-full transition-all duration-500" style="width: {{ $tracker['completion_percentage'] ?? 0 }}%"></div>
    </div>
    @endif
</div>
@endif

{{-- ═══ Who's Playing Nearby ═══ --}}
@if($hasPeople)
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
        <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">group</span>
        {{ __('profile.dashboard_newcomer_nearby_people_heading') }}
    </h3>

    {{-- Horizontal scrollable people row --}}
    <div class="flex gap-3 overflow-x-auto pb-2 -mx-1 px-1 snap-x snap-mandatory" role="list" aria-label="{{ __('profile.dashboard_newcomer_nearby_people_heading') }}">
        @foreach($people['people'] as $person)
            <a href="{{ route('profile.show', $person['id']) }}" wire:navigate
               class="shrink-0 w-32 sm:w-36 snap-start bg-surface-container-low rounded-xl border border-outline-variant/30 hover:border-primary/40 hover:shadow-ambient-md transition-all p-3 group text-center"
               role="listitem">
                {{-- Avatar --}}
                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-heading font-bold text-sm mx-auto overflow-hidden">
                    @if($person['avatar_url'] ?? null)
                        <img src="{{ $person['avatar_url'] }}" alt="" class="w-full h-full object-cover" aria-hidden="true" width="32" height="32">
                    @else
                        {{ strtoupper(\Illuminate\Support\Str::substr($person['name'] ?? '', 0, 1)) }}
                    @endif
                </div>

                {{-- Name --}}
                <p class="text-xs font-semibold text-on-surface group-hover:text-primary transition-colors truncate mt-2 leading-tight">
                    {{ $person['name'] }}
                </p>

                {{-- Top system badge --}}
                @if($person['top_system_name'] ?? null)
                    <span class="inline-block text-[9px] font-medium px-1.5 py-0.5 rounded-full bg-primary/10 text-primary mt-1">
                        {{ $person['top_system_name'] }}
                    </span>
                @endif

                {{-- Shared systems count --}}
                @if(($person['shared_systems_count'] ?? 0) > 0)
                    <p class="text-[10px] text-on-surface-variant mt-1">
                        {{ trans_choice('profile.dashboard_newcomer_shared_systems', $person['shared_systems_count'], ['count' => $person['shared_systems_count']]) }}
                    </p>
                @endif
            </a>
        @endforeach
    </div>
</div>
@endif

{{-- ═══ Quick Actions ═══ --}}
@if(isset($quickActions) && count($quickActions) > 0)
<nav class="flex flex-wrap gap-3" aria-label="{{ __('profile.dashboard_quick_actions_heading') }}">
    @foreach($quickActions as $action)
        <a href="{{ $action['url'] }}" wire:navigate
           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium transition-colors {{ $action['style'] === 'primary' ? 'bg-primary text-on-primary hover:bg-primary/90' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low' }}">
            <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' {{ $action['style'] === 'primary' ? '1' : '0' }}">{{ $action['icon'] }}</span>
            {{ __($action['label']) }}
        </a>
    @endforeach
</nav>
@endif
