<x-public-layout>

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('pages.content_commitment_roadmap_title') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto leading-relaxed">
                {{ __('roadmap.content_road_ahead_hero_subtitle') }}
            </p>
        </div>
    </section>

    {{-- ── Framing: no dates on purpose ────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <p class="text-on-surface-variant text-lg leading-relaxed mb-6">
                {{ __('roadmap.content_road_ahead_intro_1') }}
            </p>
            <p class="text-on-surface-variant text-lg leading-relaxed mb-8">
                {{ __('roadmap.content_road_ahead_intro_2') }}
            </p>
            <div class="text-center">
                <a href="https://github.com/roundup-games/platform" target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">open_in_new</span>
                    {{ __('roadmap.action_road_ahead_view_repository') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── Future ideas (aspirational — never plans) ───────── --}}
    <section id="future-ideas" class="py-16 sm:py-20 bg-surface-container-low scroll-mt-24">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">lightbulb</span>
                </div>
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                    {{ __('roadmap.heading_future_ideas') }}
                </h2>
                <p class="text-on-surface-variant max-w-2xl mx-auto leading-relaxed">
                    {{ __('roadmap.content_future_ideas_intro') }}
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 max-w-4xl mx-auto">
                {{-- Community game library --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">auto_stories</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_future_idea_community_library') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_future_idea_community_library_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- GM & organizer certification --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">workspace_premium</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_future_idea_certification') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_future_idea_certification_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Community HQs --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">home_work</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_future_idea_community_hqs') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_future_idea_community_hqs_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Other uncommitted ideas --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">more_horiz</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_future_idea_other') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_future_idea_other_body') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Aspirational closing + feedback invitation --}}
            <div class="mt-12 max-w-2xl mx-auto bg-surface-container-lowest rounded-xl p-6 sm:p-8 shadow-ambient border border-outline-variant text-center">
                <p class="text-on-surface-variant leading-relaxed mb-6">
                    {{ __('roadmap.content_future_ideas_closing') }}
                </p>
                <a href="{{ route('contact', app()->getLocale()) }}" wire:navigate
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-xl font-semibold hover:opacity-90 transition-opacity text-sm">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">forum</span>
                    {{ __('roadmap.action_future_ideas_tell_us') }}
                </a>
            </div>
        </div>
    </section>

    {{-- ── Delivered recently (shipped receipts) ───────────── --}}
    <section id="delivered-recently" class="py-16 sm:py-20 bg-surface scroll-mt-24">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">task_alt</span>
                </div>
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                    {{ __('roadmap.heading_delivered_recently') }}
                </h2>
                <p class="text-on-surface-variant max-w-2xl mx-auto leading-relaxed">
                    {{ __('roadmap.content_delivered_intro') }}
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 max-w-4xl mx-auto">
                {{-- The Campaign Charter (M070) --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">history_edu</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_delivered_charter') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_delivered_charter_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Campaign Journal & Previously On (M071) --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">book_5</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_delivered_journal') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_delivered_journal_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Badges grounded in real history (M069) --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">military_tech</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_delivered_badges') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_delivered_badges_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Public pricing, FAQ & code of conduct (M066) --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">verified_user</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_delivered_trust_pages') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_delivered_trust_pages_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Fully bilingual platform (M066) --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">translate</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_delivered_bilingual') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_delivered_bilingual_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Events with tickets & co-organizers --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">confirmation_number</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_delivered_events') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_delivered_events_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Transparent algorithms page --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">psychology</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('roadmap.heading_delivered_algorithms') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('roadmap.content_delivered_algorithms_body') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-public-layout>
