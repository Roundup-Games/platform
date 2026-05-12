<x-public-layout>

    {{-- Hero --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 text-center">
            <h1 class="text-4xl sm:text-5xl font-heading font-bold tracking-tight leading-tight">
                {{ __('safety.field_safety_tools_for_tabletop_gaming') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto">
                {{ __('safety.content_everyone_deserves_to_feel_safe') }}
            </p>
        </div>
    </section>

    {{-- Intro --}}
    <section class="py-16 bg-surface">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center">
            <p class="text-lg text-on-surface-variant leading-relaxed max-w-3xl mx-auto">
                {{ __('safety.content_whether_you_re_a_seasoned') }}
            </p>
            <div class="mt-8 flex items-center justify-center gap-4">
                <a href="{{ route('discover') }}" wire:navigate
                   class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors font-medium">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">explore</span>
                    {{ __('games.action_find_games') }}
                </a>
            </div>
        </div>
    </section>

    {{-- Tools by Category --}}
    @foreach($categories as $category)
        @php
            $categoryTools = collect($tools)->filter(fn ($tool) => $tool->category() === $category);
        @endphp

        <section class="py-12 {{ $loop->even ? 'bg-surface-container-low' : 'bg-surface' }}">
            <div class="max-w-5xl mx-auto px-4 sm:px-6">
                <div class="flex items-center gap-3 mb-8">
                    @if($category->value === 'before')
                        <span class="material-symbols-outlined text-3xl text-primary" aria-hidden="true">start</span>
                    @elseif($category->value === 'during')
                        <span class="material-symbols-outlined text-3xl text-primary" aria-hidden="true">play_circle</span>
                    @else
                        <span class="material-symbols-outlined text-3xl text-primary" aria-hidden="true">stop_circle</span>
                    @endif
                    <div>
                        <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface">
                            {{ $category->label() }}
                        </h2>
                        @if($category->value === 'before')
                            <p class="text-sm text-on-surface-variant">{{ __('common.action_set_expectations_before_the_dice_start_rolling') }}</p>
                        @elseif($category->value === 'during')
                            <p class="text-sm text-on-surface-variant">{{ __('common.content_tools_you_can_use_right_at_the_table') }}</p>
                        @else
                            <p class="text-sm text-on-surface-variant">{{ __('campaigns.content_reflect_and_improve_after_each_session') }}</p>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach($categoryTools as $tool)
                        <div class="bg-surface-container-high rounded-xl shadow-ambient p-6 {{ $loop->even ? 'md:translate-y-4' : '' }}">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">
                                        {{ $tool === \App\Enums\SafetyTool::XCard || $tool === \App\Enums\SafetyTool::XnoCard ? 'block' : ($tool === \App\Enums\SafetyTool::Breaks ? 'coffee' : 'verified_user') }}
                                    </span>
                                </div>
                                <h3 class="text-lg font-heading font-semibold text-on-surface">{{ $tool->label() }}</h3>
                            </div>
                            <p class="text-sm text-on-surface-variant leading-relaxed">{{ $tool->fullDescription() }}</p>
                            @if($tool === \App\Enums\SafetyTool::XCard)
                                <p class="mt-3 text-xs text-on-surface-variant">
                                    {{ __('common.content_created_by_john_stavropoulos_learn_more_at') }}
                                    <a href="https://tinyurl.com/x-card-rpg" target="_blank" rel="noopener" class="text-primary hover:underline">tinyurl.com/x-card-rpg</a>
                                </p>
                            @endif
                            @if($tool === \App\Enums\SafetyTool::ScriptChange)
                                <p class="mt-3 text-xs text-on-surface-variant">
                                    {{ __('common.content_created_by_beau_j_gr_sheldon') }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    {{-- Getting Started --}}
    <section class="py-16 bg-surface">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface text-center mb-10">
                {{ __('common.field_getting_started') }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="w-16 h-16 bg-secondary-container rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-on-secondary-container text-2xl" aria-hidden="true">checklist</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface mb-2">{{ __('safety.field_start_with_session_zero') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('safety.content_the_single_most_impactful_thing') }}</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-secondary-container rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-on-secondary-container text-2xl" aria-hidden="true">pan_tool</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface mb-2">{{ __('safety.content_have_an_x_card_on_the_table') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('pages.content_even_if_you_never_use') }}</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-secondary-container rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-on-secondary-container text-2xl" aria-hidden="true">chat</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface mb-2">{{ __('common.content_check_in_regularly') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('games.content_use_stars_wishes_or_a') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- When creating a game --}}
    <section class="py-12 bg-surface-container-low">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center">
            <div class="bg-surface-container-high rounded-xl shadow-ambient p-8">
                <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">info</span>
                <h2 class="text-xl font-heading font-bold text-on-surface mt-3 mb-3">{{ __('safety.content_using_safety_tools_on_roundup') }}</h2>
                <p class="text-on-surface-variant max-w-2xl mx-auto">
                    {{ __('safety.content_when_you_create_a_game') }}
                </p>
                @auth
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center gap-2 mt-6 px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors font-medium text-sm">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">add</span>
                        {{ __('games.action_create_a_game') }}
                    </a>
                @else
                    <a href="{{ route('register') }}" wire:navigate
                       class="inline-flex items-center gap-2 mt-6 px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors font-medium text-sm">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">person_add</span>
                        {{ __('auth.action_sign_up_to_get_started') }}
                    </a>
                @endauth
            </div>
        </div>
    </section>
</x-public-layout>
