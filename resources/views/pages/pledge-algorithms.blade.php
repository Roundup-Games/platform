<x-public-layout>

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('pages.content_pledge_card_algorithms_title') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto leading-relaxed">
                {{ __('pages.content_pledge_algo_hero_subtitle') }}
            </p>
        </div>
    </section>

    {{-- ── Intro ─────────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <p class="text-on-surface-variant text-lg leading-relaxed mb-6">
                {{ __('pages.content_pledge_algo_intro_1') }}
            </p>
            <p class="text-on-surface-variant text-lg leading-relaxed mb-6">
                {{ __('pages.content_pledge_algo_intro_2') }}
            </p>
            <p class="text-on-surface-variant text-lg leading-relaxed">
                {{ __('pages.content_pledge_algo_intro_3') }}
            </p>
        </div>
    </section>

    {{-- ── Table of Contents ─────────────────────────────────── --}}
    <section class="py-12 sm:py-16 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface mb-6 text-center">
                {{ __('pages.content_pledge_algo_toc_heading') }}
            </h2>
            <nav aria-label="{{ __('pages.content_pledge_algo_toc_heading') }}">
                <ol class="space-y-3 max-w-xl mx-auto">
                    <li>
                        <a href="#reliability-score" class="flex items-center gap-3 group">
                            <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">verified_user</span>
                            <span class="text-on-surface group-hover:text-primary transition-colors font-medium">{{ __('pages.heading_pledge_algo_reliability') }}</span>
                            <span class="material-symbols-outlined text-on-surface-variant text-base ml-auto group-hover:translate-x-1 transition-transform" aria-hidden="true">arrow_forward</span>
                        </a>
                    </li>
                    <li>
                        <a href="#gm-ratings" class="flex items-center gap-3 group">
                            <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">star_rate</span>
                            <span class="text-on-surface group-hover:text-primary transition-colors font-medium">{{ __('pages.heading_pledge_algo_gm') }}</span>
                            <span class="material-symbols-outlined text-on-surface-variant text-base ml-auto group-hover:translate-x-1 transition-transform" aria-hidden="true">arrow_forward</span>
                        </a>
                    </li>
                    <li>
                        <a href="#people-discovery" class="flex items-center gap-3 group">
                            <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">people</span>
                            <span class="text-on-surface group-hover:text-primary transition-colors font-medium">{{ __('pages.heading_pledge_algo_people') }}</span>
                            <span class="material-symbols-outlined text-on-surface-variant text-base ml-auto group-hover:translate-x-1 transition-transform" aria-hidden="true">arrow_forward</span>
                        </a>
                    </li>
                    <li>
                        <a href="#session-recommendations" class="flex items-center gap-3 group">
                            <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">recommend</span>
                            <span class="text-on-surface group-hover:text-primary transition-colors font-medium">{{ __('pages.heading_pledge_algo_session') }}</span>
                            <span class="material-symbols-outlined text-on-surface-variant text-base ml-auto group-hover:translate-x-1 transition-transform" aria-hidden="true">arrow_forward</span>
                        </a>
                    </li>
                    <li>
                        <a href="#proximity-engine" class="flex items-center gap-3 group">
                            <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">distance</span>
                            <span class="text-on-surface group-hover:text-primary transition-colors font-medium">{{ __('pages.heading_pledge_algo_proximity') }}</span>
                            <span class="material-symbols-outlined text-on-surface-variant text-base ml-auto group-hover:translate-x-1 transition-transform" aria-hidden="true">arrow_forward</span>
                        </a>
                    </li>
                    <li>
                        <a href="#trending" class="flex items-center gap-3 group">
                            <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">trending_up</span>
                            <span class="text-on-surface group-hover:text-primary transition-colors font-medium">{{ __('pages.heading_pledge_algo_trending') }}</span>
                            <span class="material-symbols-outlined text-on-surface-variant text-base ml-auto group-hover:translate-x-1 transition-transform" aria-hidden="true">arrow_forward</span>
                        </a>
                    </li>
                    <li>
                        <a href="#platform-score" class="flex items-center gap-3 group">
                            <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">insights</span>
                            <span class="text-on-surface group-hover:text-primary transition-colors font-medium">{{ __('pages.heading_pledge_algo_platform') }}</span>
                            <span class="material-symbols-outlined text-on-surface-variant text-base ml-auto group-hover:translate-x-1 transition-transform" aria-hidden="true">arrow_forward</span>
                        </a>
                    </li>
                </ol>
            </nav>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════
        ALGORITHM SECTIONS
        ══════════════════════════════════════════════════════════ --}}

    {{-- ── 1. Player Reliability Score ───────────────────────── --}}
    <section id="reliability-score" class="py-16 sm:py-20 bg-surface scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">verified_user</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.heading_pledge_algo_reliability') }}
                </h2>
            </div>

            <p class="text-on-surface-variant leading-relaxed mb-8">
                {{ __('pages.content_pledge_algo_reliability_explanation') }}
            </p>

            {{-- Formula Block --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">function</span>
                    {{ __('pages.content_pledge_algo_formula_heading') }}
                </h3>
                <div class="font-mono text-sm text-on-surface-variant bg-surface-container-lowest rounded-lg p-4 overflow-x-auto mb-4">
                    <p class="mb-2">Score = (weighted_sum / game_count) &times; 100</p>
                    <p class="mb-2">Clamped to range [0%, 100%]</p>
                </div>
                <table class="w-full text-sm text-on-surface-variant">
                    <caption class="sr-only">Attendance status weights</caption>
                    <thead>
                        <tr class="border-b border-outline-variant">
                            <th class="text-left py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_table_status') }}</th>
                            <th class="text-left py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_table_player_weight') }}</th>
                            <th class="text-left py-2 font-medium">{{ __('pages.content_pledge_algo_table_host_weight') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_status_attended') }}</td>
                            <td class="py-2 pr-4 font-mono">+1.0</td>
                            <td class="py-2 font-mono">+1.0</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_status_late_cancel') }}</td>
                            <td class="py-2 pr-4 font-mono">&minus;0.3</td>
                            <td class="py-2 font-mono text-error">&minus;1.2</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_status_no_show') }}</td>
                            <td class="py-2 pr-4 font-mono">&minus;1.0</td>
                            <td class="py-2 font-mono text-error">&minus;1.5</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_status_excused') }}</td>
                            <td class="py-2 pr-4 font-mono">0.0</td>
                            <td class="py-2 font-mono">0.0</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_status_cancelled_early') }}</td>
                            <td class="py-2 pr-4 font-mono">0.0</td>
                            <td class="py-2 font-mono">0.0</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Tier Classification --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">category</span>
                    {{ __('pages.content_pledge_algo_tier_heading') }}
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="text-center p-4 bg-surface-container-lowest rounded-lg">
                        <div class="font-heading font-bold text-primary text-lg mb-1">{{ __('pages.content_pledge_algo_tier_reliable') }}</div>
                        <p class="text-sm text-on-surface-variant">{{ __('pages.content_pledge_algo_tier_reliable_desc') }}</p>
                    </div>
                    <div class="text-center p-4 bg-surface-container-lowest rounded-lg">
                        <div class="font-heading font-bold text-on-surface text-lg mb-1">{{ __('pages.content_pledge_algo_tier_active') }}</div>
                        <p class="text-sm text-on-surface-variant">{{ __('pages.content_pledge_algo_tier_active_desc') }}</p>
                    </div>
                    <div class="text-center p-4 bg-surface-container-lowest rounded-lg">
                        <div class="font-heading font-bold text-on-surface-variant text-lg mb-1">{{ __('pages.content_pledge_algo_tier_newcomer') }}</div>
                        <p class="text-sm text-on-surface-variant">{{ __('pages.content_pledge_algo_tier_newcomer_desc') }}</p>
                    </div>
                </div>
            </div>

            {{-- Design Decisions --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">lightbulb</span>
                    {{ __('pages.content_pledge_algo_design_heading') }}
                </h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_reliability_design_1') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_reliability_design_2') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_reliability_design_3') }}</span>
                    </li>
                </ul>
            </div>

            {{-- GitHub Link --}}
            <div class="text-center">
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/ReliabilityScoreService.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_reliability') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── 2. GM Ratings & Reviews ───────────────────────────── --}}
    <section id="gm-ratings" class="py-16 sm:py-20 bg-surface-container-low scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">star_rate</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.heading_pledge_algo_gm') }}
                </h2>
            </div>

            <p class="text-on-surface-variant leading-relaxed mb-8">
                {{ __('pages.content_pledge_algo_gm_explanation') }}
            </p>

            {{-- Formula Block --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">function</span>
                    {{ __('pages.content_pledge_algo_formula_heading') }}
                </h3>
                <div class="font-mono text-sm text-on-surface-variant bg-surface-container-lowest rounded-lg p-4 overflow-x-auto mb-4">
                    <p class="mb-2">Average Rating = COALESCE(AVG(rating), 0)</p>
                    <p class="mb-2">Review Count = COUNT(*) <span class="text-primary">WHERE status = 'published'</span></p>
                    <p>Proficiency Badges = TOP 3 tags by frequency across published reviews</p>
                </div>
                <p class="text-sm text-on-surface-variant">
                    {{ __('pages.content_pledge_algo_gm_formula_note') }}
                </p>
            </div>

            {{-- Design Decisions --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">lightbulb</span>
                    {{ __('pages.content_pledge_algo_design_heading') }}
                </h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_gm_design_1') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_gm_design_2') }}</span>
                    </li>
                </ul>
            </div>

            {{-- GitHub Link --}}
            <div class="text-center">
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/ReviewAggregateService.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_gm') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── 3. People Discovery ────────────────────────────────── --}}
    <section id="people-discovery" class="py-16 sm:py-20 bg-surface scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">people</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.heading_pledge_algo_people') }}
                </h2>
            </div>

            <p class="text-on-surface-variant leading-relaxed mb-8">
                {{ __('pages.content_pledge_algo_people_explanation') }}
            </p>

            {{-- Pipeline Diagram --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">account_tree</span>
                    {{ __('pages.content_pledge_algo_people_pipeline_heading') }}
                </h3>
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-7 h-7 bg-primary/10 rounded-full text-primary text-sm font-bold shrink-0">1</span>
                        <div>
                            <div class="font-semibold text-on-surface text-sm">{{ __('pages.content_pledge_algo_people_pipeline_1') }}</div>
                            <p class="text-on-surface-variant text-sm mt-1">{{ __('pages.content_pledge_algo_people_pipeline_1_desc') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-7 h-7 bg-primary/10 rounded-full text-primary text-sm font-bold shrink-0">2</span>
                        <div>
                            <div class="font-semibold text-on-surface text-sm">{{ __('pages.content_pledge_algo_people_pipeline_2') }}</div>
                            <p class="text-on-surface-variant text-sm mt-1">{{ __('pages.content_pledge_algo_people_pipeline_2_desc') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-7 h-7 bg-primary/10 rounded-full text-primary text-sm font-bold shrink-0">3</span>
                        <div>
                            <div class="font-semibold text-on-surface text-sm">{{ __('pages.content_pledge_algo_people_pipeline_3') }}</div>
                            <p class="text-on-surface-variant text-sm mt-1">{{ __('pages.content_pledge_algo_people_pipeline_3_desc') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-7 h-7 bg-primary/10 rounded-full text-primary text-sm font-bold shrink-0">4</span>
                        <div>
                            <div class="font-semibold text-on-surface text-sm">{{ __('pages.content_pledge_algo_people_pipeline_4') }}</div>
                            <p class="text-on-surface-variant text-sm mt-1">{{ __('pages.content_pledge_algo_people_pipeline_4_desc') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Formula Block --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">function</span>
                    {{ __('pages.content_pledge_algo_formula_heading') }}
                </h3>
                <div class="font-mono text-sm text-on-surface-variant bg-surface-container-lowest rounded-lg p-4 overflow-x-auto mb-4">
                    <p class="mb-2">Taste: J(A, B) = |A &cap; B| / |A &cup; B|</p>
                    <p class="mb-2">&nbsp;&nbsp;Computed on game systems + vibes, averaged</p>
                    <p class="mb-2">Social: (team overlap + mutual follow) / components</p>
                    <p class="mb-3">Composite:</p>
                    <p class="mb-1">&nbsp;&nbsp;if taste &amp; social: score = taste &times; 0.7 + social &times; 0.3</p>
                    <p class="mb-1">&nbsp;&nbsp;if taste only: score = taste</p>
                    <p>&nbsp;&nbsp;if social only: score = social</p>
                </div>
                <table class="w-full text-sm text-on-surface-variant">
                    <caption class="sr-only">Scoring component weights</caption>
                    <thead>
                        <tr class="border-b border-outline-variant">
                            <th class="text-left py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_table_component') }}</th>
                            <th class="text-left py-2 font-medium">{{ __('pages.content_pledge_algo_table_weight_both') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_taste_similarity') }}</td>
                            <td class="py-2 font-mono">0.7</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_social_overlap') }}</td>
                            <td class="py-2 font-mono">0.3</td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-xs text-on-surface-variant mt-3 italic"{{ __('pages.content_pledge_algo_single_signal_note') }}</p>
            </div>

            {{-- Design Decisions --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">lightbulb</span>
                    {{ __('pages.content_pledge_algo_design_heading') }}
                </h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_people_design_1') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_people_design_2') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_people_design_3') }}</span>
                    </li>
                </ul>
            </div>

            {{-- GitHub Links --}}
            <div class="text-center flex flex-wrap justify-center gap-3">
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/PeopleDiscoveryService.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_people') }}
                </a>
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/ProfileVisibilityResolver.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_visibility') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── 4. Session Recommendations ─────────────────────────── --}}
    <section id="session-recommendations" class="py-16 sm:py-20 bg-surface-container-low scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">recommend</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.heading_pledge_algo_session') }}
                </h2>
            </div>

            <p class="text-on-surface-variant leading-relaxed mb-8">
                {{ __('pages.content_pledge_algo_session_explanation') }}
            </p>

            {{-- Two-Query Approach --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">account_tree</span>
                    {{ __('pages.content_pledge_algo_session_two_query_heading') }}
                </h3>
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-7 h-7 bg-primary/10 rounded-full text-primary text-sm font-bold shrink-0">1</span>
                        <div>
                            <div class="font-semibold text-on-surface text-sm">{{ __('pages.content_pledge_algo_session_query_boosted') }}</div>
                            <p class="text-on-surface-variant text-sm mt-1">{{ __('pages.content_pledge_algo_session_query_boosted_desc') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-7 h-7 bg-primary/10 rounded-full text-primary text-sm font-bold shrink-0">2</span>
                        <div>
                            <div class="font-semibold text-on-surface text-sm">{{ __('pages.content_pledge_algo_session_query_fallback') }}</div>
                            <p class="text-on-surface-variant text-sm mt-1">{{ __('pages.content_pledge_algo_session_query_fallback_desc') }}</p>
                        </div>
                    </div>
                </div>
                <div class="mt-4 p-3 bg-surface-container-lowest rounded-lg">
                    <p class="text-sm text-on-surface-variant">{{ __('pages.content_pledge_algo_session_dedup') }}</p>
                </div>
            </div>

            {{-- Formula Block --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">function</span>
                    {{ __('pages.content_pledge_algo_preference_resolution_heading') }}
                </h3>
                <div class="font-mono text-sm text-on-surface-variant bg-surface-container-lowest rounded-lg p-4 overflow-x-auto mb-4">
                    <p class="mb-2">allowed = (favorites + implied_favorites) &minus; avoided</p>
                    <p class="mb-2">Boosted: allowed AND favorite_vibes</p>
                    <p>Fallback: allowed (any vibe)</p>
                </div>
                <table class="w-full text-sm text-on-surface-variant">
                    <caption class="sr-only">Preference resolution rules</caption>
                    <thead>
                        <tr class="border-b border-outline-variant">
                            <th class="text-left py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_table_rule') }}</th>
                            <th class="text-left py-2 font-medium">{{ __('pages.content_pledge_algo_table_behavior') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_rule_base_favorited') }}</td>
                            <td class="py-2">{!! __('pages.content_pledge_algo_rule_base_favorited_behavior') !!} class="bg-surface-container-lowest px-1 rounded text-xs">implied_favorites</code></td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_rule_explicit_avoid') }}</td>
                            <td class="py-2">{{ __('pages.content_pledge_algo_rule_explicit_avoid_behavior') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_rule_vibe_exclusivity') }}</td>
                            <td class="py-2">{{ __('pages.content_pledge_algo_rule_vibe_exclusivity_behavior') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Design Decisions --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">lightbulb</span>
                    {{ __('pages.content_pledge_algo_design_heading') }}
                </h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_session_design_1') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_session_design_2') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_session_design_3') }}</span>
                    </li>
                </ul>
            </div>

            {{-- GitHub Links --}}
            <div class="text-center flex flex-wrap justify-center gap-3">
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/DiscoveryQueryService.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_discovery') }}
                </a>
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/UserPreferenceResolver.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_preferences') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── 5. Proximity Engine (Haversine) ─────────────────────── --}}
    <section id="proximity-engine" class="py-16 sm:py-20 bg-surface scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">distance</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.heading_pledge_algo_proximity') }}
                </h2>
            </div>

            <p class="text-on-surface-variant leading-relaxed mb-8">
                {{ __('pages.content_pledge_algo_proximity_explanation') }}
            </p>

            {{-- Two-Phase Approach --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">account_tree</span>
                    {{ __('pages.content_pledge_algo_proximity_two_phase_heading') }}
                </h3>
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-7 h-7 bg-primary/10 rounded-full text-primary text-sm font-bold shrink-0">1</span>
                        <div>
                            <div class="font-semibold text-on-surface text-sm">{{ __('pages.content_pledge_algo_proximity_phase_1') }}</div>
                            <p class="text-on-surface-variant text-sm mt-1">{{ __('pages.content_pledge_algo_proximity_phase_1_desc') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-7 h-7 bg-primary/10 rounded-full text-primary text-sm font-bold shrink-0">2</span>
                        <div>
                            <div class="font-semibold text-on-surface text-sm">{{ __('pages.content_pledge_algo_proximity_phase_2') }}</div>
                            <p class="text-on-surface-variant text-sm mt-1">{{ __('pages.content_pledge_algo_proximity_phase_2_desc') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Haversine Formula --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">function</span>
                    {{ __('pages.content_pledge_algo_formula_heading') }}
                </h3>
                <div class="font-mono text-sm text-on-surface-variant bg-surface-container-lowest rounded-lg p-4 overflow-x-auto mb-4">
                    <p class="mb-2">d = 2R &times; arcsin(&radic;(</p>
                    <p class="mb-1">&nbsp;&nbsp;sin&sup2;(&Delta;lat / 2) +</p>
                    <p class="mb-1">&nbsp;&nbsp;cos(lat&#8321;) &times; cos(lat&#8322;) &times; sin&sup2;(&Delta;lng / 2)</p>
                    <p>))</p>
                </div>
                <p class="text-sm text-on-surface-variant"{{ __('pages.content_pledge_algo_haversine_radius') }}</p>
            </div>

            {{-- Geohash Tile System --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">grid_on</span>
                    {{ __('pages.content_pledge_algo_proximity_geohash') }}
                </h3>
                <table class="w-full text-sm text-on-surface-variant">
                    <caption class="sr-only">Geohash precision levels and approximate tile sizes</caption>
                    <thead>
                        <tr class="border-b border-outline-variant">
                            <th class="text-left py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_table_precision') }}</th>
                            <th class="text-left py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_table_approx_size') }}</th>
                            <th class="text-left py-2 font-medium">{{ __('pages.content_pledge_algo_table_use') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4 font-mono">4 chars</td>
                            <td class="py-2 pr-4">~20km &times; 20km</td>
                            <td class="py-2"{{ __('pages.content_pledge_algo_geohash_city') }}</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4 font-mono">5 chars</td>
                            <td class="py-2 pr-4">~2.4km &times; 4.9km</td>
                            <td class="py-2"{{ __('pages.content_pledge_algo_geohash_neighborhood') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono">6 chars</td>
                            <td class="py-2 pr-4">~0.6km &times; 1.2km</td>
                            <td class="py-2"{{ __('pages.content_pledge_algo_geohash_venue') }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-xs text-on-surface-variant mt-3 italic">{{ __('pages.content_pledge_algo_proximity_caching') }}</p>
            </div>

            {{-- Design Decisions --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">lightbulb</span>
                    {{ __('pages.content_pledge_algo_design_heading') }}
                </h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_proximity_design_1') }}</span>
                    </li>
                </ul>
            </div>

            {{-- GitHub Links --}}
            <div class="text-center flex flex-wrap justify-center gap-3">
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/ProximityQuery.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_proximity') }}
                </a>
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/Geohash.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_geohash') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── 6. Trending & Popular ──────────────────────────────── --}}
    <section id="trending" class="py-16 sm:py-20 bg-surface-container-low scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">trending_up</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.heading_pledge_algo_trending') }}
                </h2>
            </div>

            <p class="text-on-surface-variant leading-relaxed mb-8">
                {{ __('pages.content_pledge_algo_trending_explanation') }}
            </p>

            {{-- Selection Criteria --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">filter_list</span>
                    {{ __('pages.content_pledge_algo_trending_criteria') }}
                </h3>
                <table class="w-full text-sm text-on-surface-variant">
                    <caption class="sr-only">Trending session selection criteria</caption>
                    <thead>
                        <tr class="border-b border-outline-variant">
                            <th class="text-left py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_trending_table_parameter') }}</th>
                            <th class="text-left py-2 font-medium">{{ __('pages.content_pledge_algo_trending_table_value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_trending_geo_scope') }}</td>
                            <td class="py-2"{{ __('pages.content_pledge_algo_trending_geo_value') }} (~20km &times; 20km)</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_trending_time_window') }}</td>
                            <td class="py-2"{{ __('pages.content_pledge_algo_trending_time_value') }}</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_trending_visibility') }}</td>
                            <td class="py-2"{{ __('pages.content_pledge_algo_trending_visibility_value') }}</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_trending_sort') }}</td>
                            <td class="py-2 font-mono">participants DESC, created_at DESC</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_trending_limit') }}</td>
                            <td class="py-2 font-mono">Top 5 per tile</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_trending_cache_ttl') }}</td>
                            <td class="py-2 font-mono">10 minutes</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Design Decisions --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">lightbulb</span>
                    {{ __('pages.content_pledge_algo_design_heading') }}
                </h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_trending_design_1') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_trending_design_2') }}</span>
                    </li>
                </ul>
            </div>

            {{-- GitHub Links --}}
            <div class="text-center flex flex-wrap justify-center gap-3">
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/DashboardCacheService.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_dashboard_cache') }}
                </a>
                <a href="https://github.com/roundup-games/platform/blob/main/app/Jobs/WarmTrendingNearby.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_warm_trending') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── 7. Platform Score ──────────────────────────────────── --}}
    <section id="platform-score" class="py-16 sm:py-20 bg-surface scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">insights</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.heading_pledge_algo_platform') }}
                </h2>
            </div>

            <p class="text-on-surface-variant leading-relaxed mb-8">
                {{ __('pages.content_pledge_algo_platform_explanation') }}
            </p>

            {{-- Formula Block --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">function</span>
                    {{ __('pages.content_pledge_algo_formula_heading') }}
                </h3>
                <div class="font-mono text-sm text-on-surface-variant bg-surface-container-lowest rounded-lg p-4 overflow-x-auto mb-4">
                    <p class="mb-2">score = (favorites &times; w&#8321;) + (games &times; w&#8322;)</p>
                    <p>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;+ (campaigns &times; w&#8323;) + (active_games &times; w&#8324;)</p>
                </div>
                <table class="w-full text-sm text-on-surface-variant">
                    <caption class="sr-only">Type-differentiated scoring weights</caption>
                    <thead>
                        <tr class="border-b border-outline-variant">
                            <th class="text-left py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_table_signal') }}</th>
                            <th class="text-center py-2 pr-4 font-medium">{{ __('pages.content_pledge_algo_table_board_games') }}</th>
                            <th class="text-center py-2 font-medium">{{ __('pages.content_pledge_algo_table_ttrpgs') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_signal_favorites') }}</td>
                            <td class="py-2 pr-4 text-center font-mono">10</td>
                            <td class="py-2 text-center font-mono">10</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_signal_total_games') }}</td>
                            <td class="py-2 pr-4 text-center font-mono">3</td>
                            <td class="py-2 text-center font-mono">3</td>
                        </tr>
                        <tr class="border-b border-outline-variant/50">
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_signal_campaigns') }}</td>
                            <td class="py-2 pr-4 text-center font-mono">5</td>
                            <td class="py-2 text-center font-mono text-primary font-bold">15</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4">{{ __('pages.content_pledge_algo_signal_active_games') }}</td>
                            <td class="py-2 pr-4 text-center font-mono text-primary font-bold">20</td>
                            <td class="py-2 text-center font-mono">10</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Design Decisions --}}
            <div class="bg-surface-container-high rounded-xl p-6 mb-8 border border-outline-variant">
                <h3 class="font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">lightbulb</span>
                    {{ __('pages.content_pledge_algo_design_heading') }}
                </h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_platform_design_1') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-lg mt-0.5 shrink-0" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface-variant text-sm leading-relaxed">{{ __('pages.content_pledge_algo_platform_design_2') }}</span>
                    </li>
                </ul>
            </div>

            {{-- GitHub Link --}}
            <div class="text-center">
                <a href="https://github.com/roundup-games/platform/blob/main/app/Services/PlatformScoreService.php"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('pages.content_pledge_algo_source_platform') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── CTA ─────────────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
                {{ __('pages.content_pledge_algo_cta_heading') }}
            </h2>
            <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
                {{ __('pages.content_pledge_algo_cta_body') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                <a href="https://github.com/roundup-games/platform" target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                    <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">code</span>
                    {{ __('pages.content_pledge_algo_cta_github') }}
                </a>
                @guest
                    <a href="{{ route('register') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                        {{ __('pages.content_pledge_algo_cta_join') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>
