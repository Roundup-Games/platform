{{--
    Public site header (M0xx revamp): sticky 64/72px bar — wordmark, the
    five discovery surfaces (≥1140px only: DE compounds like
    "Veranstaltungsorte" plus the utility cluster don't fit below it — same
    number momani uses), a city-hub pill and an account chip (native
    Popover API menus pinned by site-header.js), the persistent Sign-up
    CTA (hybrid decision), and a menu button that opens the native-dialog
    drawer below 1140px. Utility chrome (theme, language, auth, contact,
    cookies) composes into the account popover instead of competing with
    the primary destinations.
--}}
<header id="site-header" class="site-header sticky top-0 z-50 border-b border-outline-variant/15 bg-surface transition-[background-color,box-shadow] duration-200">
    <div class="mx-auto flex h-16 w-full max-w-(--breakpoint-2xl) items-center gap-3 px-4 sm:px-6 min-[1140px]:h-[72px] min-[1140px]:px-8">
        <a href="{{ route('home') }}" wire:navigate class="flex shrink-0 items-center gap-2">
            @include('partials.logo', ['class' => 'h-9 min-[1140px]:h-10 w-auto'])
        </a>

        {{-- Primary destinations — the discovery surfaces only --}}
        @php
            // [route-name-or-null, fallback-path, routeIs-pattern, label] —
            // game-systems keeps its Route::has() guard like the old header.
            $gameSystemsHref = \Illuminate\Support\Facades\Route::has('game-systems')
                ? route('game-systems')
                : url(app()->getLocale() . '/game-systems');
            $navItems = [
                [route('discover'), 'discover', __('discovery.action_discover')],
                [route('gm.directory'), 'gm.directory', __('profile.nav_gm_directory')],
                [route('events.index'), 'events.*', __('events.content_events')],
                [$gameSystemsHref, 'game-systems*', __('games.content_game_systems')],
                [route('venues.directory'), 'venues.*', __('venues.nav_venue_directory')],
            ];
        @endphp
        <nav class="ml-3 hidden h-full min-w-0 items-stretch gap-1.5 min-[1140px]:flex"
             aria-label="{{ __('common.aria_main_navigation') }}">
            @foreach ($navItems as [$href, $pattern, $label])
                <a href="{{ $href }}"
                   wire:navigate
                   class="relative inline-flex items-center px-2.5 font-heading text-[15px] font-medium tracking-tight whitespace-nowrap transition-colors {{ request()->routeIs($pattern) ? 'text-primary font-semibold' : 'text-on-surface-variant hover:text-primary' }}">
                    {{ $label }}
                    @if (request()->routeIs($pattern))
                        <span class="absolute inset-x-2.5 -bottom-px h-[3px] rounded-t-full bg-primary" aria-hidden="true"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-2">
            {{-- City hub pill — visible at every width (label from sm).
                 Accessible name must CONTAIN the visible text (axe
                 label-content-name-mismatch): "<visible>, <action>". --}}
            @php($currentCity = null)
            @if (request()->routeIs('city-hubs.show') && isset($navCities))
                @foreach ($navCities as $city)
                    @if (request()->route('slug') === $city['slug'])
                        @php($currentCity = $city['city'])
                    @endif
                @endforeach
            @endif
            <button type="button"
                    popovertarget="city-menu"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-label="{{ ($currentCity ?? __('city-hubs.nav_cities')).', '.__('common.aria_open_city_hubs') }}"
                    class="inline-flex h-10 items-center gap-[7px] rounded-full border border-outline-variant/55 px-3.5 text-sm font-medium text-on-surface transition-colors hover:border-primary sm:px-3.5 max-sm:px-2.5">
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">location_on</span>
                <span class="hidden max-sm:inline">{{ $currentCity ?? __('city-hubs.nav_cities') }}</span>
                <span class="material-symbols-outlined chev hidden text-[18px] transition-transform duration-200 min-[1140px]:inline" aria-hidden="true">expand_more</span>
            </button>

            {{-- Account chip — composes theme · language · auth · contact.
                 Accessible name contains the visible text (first name when
                 signed in, "Account" for guests) per axe label-content-name-
                 mismatch — same construction as the city pill above. --}}
            <button type="button"
                    popovertarget="account-menu"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-label="{{ (auth()->check() ? \Illuminate\Support\Str::before(auth()->user()->name, ' ') : __('profile.content_account')).', '.__('common.aria_open_account_menu') }}"
                    class="inline-flex h-10 items-center gap-[7px] rounded-full border border-outline-variant/55 px-3.5 text-sm font-medium text-on-surface transition-colors hover:border-primary max-sm:px-2.5">
                @guest
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">person</span>
                    <span class="hidden sm:inline">{{ __('profile.content_account') }}</span>
                @else
                    <x-user-avatar :user="auth()->user()" size="w-6.5 h-6.5" text-size="text-[10px]" />
                    <span class="hidden truncate sm:inline" data-ph-mask>{{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</span>
                @endguest
                <span class="material-symbols-outlined chev hidden text-[18px] transition-transform duration-200 min-[1140px]:inline" aria-hidden="true">expand_more</span>
            </button>

            {{-- Hybrid decision: Sign-up stays a persistent primary CTA (≥sm) --}}
            @guest
                <a href="{{ route('login') }}" wire:navigate
                   class="hidden h-10 items-center rounded-full bg-primary px-5 text-sm font-semibold text-on-primary shadow-sm transition-[filter,transform] hover:brightness-110 active:scale-[.97] sm:inline-flex">
                    {{ __('auth.content_sign_up') }}
                </a>
            @endguest

            {{-- Drawer trigger (< 1140px) --}}
            <button type="button"
                    id="site-drawer-open"
                    data-site-drawer
                    aria-haspopup="dialog"
                    aria-label="{{ __('common.aria_toggle_navigation_menu') }}"
                    class="inline-grid size-10 place-items-center rounded-full border border-outline-variant/55 transition-colors hover:border-primary min-[1140px]:hidden">
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">menu</span>
            </button>
        </div>
    </div>

    <x-site-header.account-menu />
    {{-- navCities reaches the city menu via its own view composer — object
         graphs (Collection<CitySummary>) don't survive attribute marshalling
         into anonymous children (incomplete-object unserialize errors). --}}
    <x-site-header.city-menu />
    <x-site-header.drawer />
</header>
