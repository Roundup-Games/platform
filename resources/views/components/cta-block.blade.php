{{-- ─────────────────────────────────────────────────────────────
     Shared marketing CTA band (M066/S09/T01)

     Replaces the four copy-paste CTA bands so every page ends with
     the call to action for ITS audience instead of the same
     "Ready to find your table?" block everywhere:

       player    → find-a-game funnel     (home, how-it-works, pledge)
       organizer → host-a-session funnel  (for-organizers)
       values    → values-reader handoff  (about → the pledge)

     Usage: <x-cta-block variant="player" />
     Unknown variants fall back to the player funnel.
     ───────────────────────────────────────────────────────────── --}}
@props([
    'variant' => 'player',
])

@php
    $audiences = [
        'player' => [
            'heading' => __('common.content_ready_to_find_your_table'),
            'body' => __('campaigns.content_create_a_free_account_set'),
            'guest' => [
                'primary' => [
                    'href' => route('register'),
                    'label' => __('profile.action_create_free_account'),
                    'icon' => 'person_add',
                ],
                'secondary' => [
                    'href' => route('discover'),
                    'label' => __('common.action_find_a_game_near_you'),
                    'icon' => 'explore',
                ],
            ],
            'member' => [
                'primary' => [
                    'href' => route('discover'),
                    'label' => __('common.action_find_a_game_near_you'),
                    'icon' => 'explore',
                ],
                'secondary' => null,
            ],
        ],
        'organizer' => [
            'heading' => __('campaigns.field_start_your_first_session_it_s_free'),
            'body' => __('pages.content_no_subscriptions_required_no_credit'),
            'guest' => [
                'primary' => [
                    'href' => route('register'),
                    'label' => __('pages.field_start_hosting_it_s_free'),
                    'icon' => 'person_add',
                ],
                'secondary' => [
                    'href' => route('how-it-works'),
                    'label' => __('pages.content_see_how_it_works'),
                    'icon' => 'explore',
                ],
            ],
            'member' => [
                'primary' => [
                    'href' => route('games.create'),
                    'label' => __('campaigns.action_create_your_first_session'),
                    'icon' => 'add_circle',
                ],
                'secondary' => [
                    'href' => route('discover'),
                    'label' => __('campaigns.action_browse_sessions'),
                    'icon' => 'explore',
                ],
            ],
        ],
        'values' => [
            'heading' => __('common.field_like_what_we_stand_for'),
            'body' => __('common.content_values_shape_every_decision'),
            'guest' => [
                'primary' => [
                    'href' => route('pledge'),
                    'label' => __('pages.content_safe_spaces_pledge_link'),
                    'icon' => 'volunteer_activism',
                ],
                'secondary' => [
                    'href' => route('register'),
                    'label' => __('auth.content_sign_up_free'),
                    'icon' => 'person_add',
                ],
            ],
            'member' => [
                'primary' => [
                    'href' => route('pledge'),
                    'label' => __('pages.content_safe_spaces_pledge_link'),
                    'icon' => 'volunteer_activism',
                ],
                'secondary' => null,
            ],
        ],
    ];

    $audience = $audiences[$variant] ?? $audiences['player'];
    $actions = auth()->check() ? $audience['member'] : $audience['guest'];
@endphp

<section class="py-16 sm:py-20 bg-primary text-on-primary">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
        <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
            {{ $audience['heading'] }}
        </h2>
        <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
            {{ $audience['body'] }}
        </p>
        <div class="mt-8 flex flex-wrap justify-center gap-4">
            <a href="{{ $actions['primary']['href'] }}" wire:navigate
               class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">{{ $actions['primary']['icon'] }}</span>
                {{ $actions['primary']['label'] }}
            </a>
            @if ($actions['secondary'])
                <a href="{{ $actions['secondary']['href'] }}" wire:navigate
                   class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                    <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">{{ $actions['secondary']['icon'] }}</span>
                    {{ $actions['secondary']['label'] }}
                </a>
            @endif
        </div>
    </div>
</section>
