{{-- Load More button for discovery pages --}}
{{-- Expects: $results (LengthAwarePaginator), $loadMoreAction (string, e.g. 'loadMore') --}}

@if($results->hasMorePages())
    <div class="mt-6 text-center">
        <button wire:click="{{ $loadMoreAction }}"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 px-6 py-3 bg-surface-container-high text-on-surface text-sm font-medium rounded-xl shadow-ambient hover:bg-surface-container transition-colors">
            <span wire:loading.remove wire:target="{{ $loadMoreAction }}">
                <span class="material-symbols-outlined text-base" aria-hidden="true">expand_more</span>
            </span>
            <span wire:loading wire:target="{{ $loadMoreAction }}">
                <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
            </span>
            {{ __('discovery.action_load_more') }}
        </button>
        <p class="mt-2 text-xs text-on-surface-variant">
            {{ __('discovery.content_showing_of_total', [
                'shown' => $results->count(),
                'total' => $results->total(),
            ]) }}
        </p>
    </div>
@endif
