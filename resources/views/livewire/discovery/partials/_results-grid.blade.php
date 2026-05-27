{{-- ── Results Grid ────────────────────────────────────────── --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    @foreach($results as $item)
        @if($item->discoverable_type === 'game')
            @include('livewire.discovery.partials.game-card', ['game' => $item])
        @else
            @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
        @endif
    @endforeach
</div>

@include('livewire.discovery.partials._load-more', ['loadMoreAction' => 'loadMore'])
