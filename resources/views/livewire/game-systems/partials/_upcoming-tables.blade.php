{{-- ── Upcoming tables (M062 / 62-02 T02) ──────────────────────────────
     Upcoming PUBLIC scheduled tables offering this system, served by
     GameSystemLandingService — the same TTL-cached set that feeds the
     ItemList JSON-LD in GameSystem::getDynamicSEOData (D140), so cards
     and structured data can never disagree. PUBLIC-ONLY visibility is
     deliberate (D141): crawlers are guests. When the set is empty the
     evergreen fallback keeps the page useful and indexable (MEM999) —
     never a dead panel and never the old mis-scoped
     content_no_game_systems_available_yet_short copy. --}}
<section class="bg-surface-container rounded-xl shadow-ambient p-6" aria-labelledby="game-system-upcoming-heading">
    <h2 id="game-system-upcoming-heading" class="text-lg font-heading font-bold text-on-surface mb-2 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary" aria-hidden="true">event_upcoming</span>
        {{ __('games.heading_upcoming_tables') }}
    </h2>

    @if($upcomingTables->isNotEmpty())
        <p class="text-sm text-on-surface-variant mb-4">
            {{ __('games.content_upcoming_tables_intro') }}
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($upcomingTables as $game)
                @include('livewire.discovery.partials.game-card', ['game' => $game])
            @endforeach
        </div>
    @else
        {{-- Evergreen fallback: intro copy + type-aware discovery link.
             Mirrors _sessions-list's ttrpg fork — oneshot adventures for
             tabletop systems, board-game discovery otherwise. --}}
        @php($isTtrpg = $system->type === 'ttrpg')
        <div class="rounded-xl border border-dashed border-outline-variant/50 p-8 text-center">
            <span class="material-symbols-outlined text-3xl text-on-surface-variant/70" aria-hidden="true">event_busy</span>
            <p class="mt-2 text-on-surface-variant">
                {{ __('games.empty_upcoming_tables_intro', ['system' => $system->name]) }}
            </p>
            @if($isTtrpg)
                <a href="{{ route('discover.adventures', ['game_system_id' => $system->id, 'session_type' => 'oneshot']) }}" wire:navigate class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                    {{ __('games.empty_upcoming_tables_cta') }}
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
                </a>
            @else
                <a href="{{ route('discover.board-games', ['game_system_id' => $system->id]) }}" wire:navigate class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                    {{ __('games.empty_upcoming_tables_cta') }}
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
                </a>
            @endif
        </div>
    @endif
</section>
