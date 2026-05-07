<div>
    @section('title', $system->name)

    {{-- Back link + Hero --}}
    @include('livewire.game-systems.partials._system-header')

    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-8">

        {{-- Flash message + Preference & Discovery Bar --}}
        @include('livewire.game-systems.partials._system-actions')

        {{-- Two-column layout: Description + Metadata --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Description, categories, instructions, showcases, FAQ, links --}}
            <div class="lg:col-span-2 space-y-6">
                @include('livewire.game-systems.partials._system-info')
            </div>

            {{-- Metadata sidebar --}}
            @include('livewire.game-systems.partials._system-sidebar')
        </div>

        {{-- Base game + Expansions --}}
        @include('livewire.game-systems.partials._related-systems')

        {{-- Active Sessions & Campaigns --}}
        @include('livewire.game-systems.partials._sessions-list')
    </div>
</div>
