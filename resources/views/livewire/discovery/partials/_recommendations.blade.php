{{-- ── Recommended for You (logged-in users only) ────────── --}}
@auth
    @if($recommendations)
        <section class="space-y-3">
            <h2 class="text-lg font-heading font-semibold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary" aria-hidden="true">auto_awesome</span>
                {{ __('discovery.field_recommended_for_you') }}
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($recommendations as $item)
                    @if($item->discoverable_type === 'game')
                        @include('livewire.discovery.partials.game-card', ['game' => $item])
                    @else
                        @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
                    @endif
                @endforeach
            </div>
            <hr class="border-outline-variant/30 mt-4" />
        </section>
    @endif
@endauth
