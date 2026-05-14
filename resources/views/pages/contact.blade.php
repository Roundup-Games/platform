<x-public-layout>

    <x-hero :title="__('pages.content_contact_us')" :subtitle="__('common.content_have_a_question_suggestion_or')" />

    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                {{-- Contact Form --}}
                <div>
                    <h2 class="text-2xl font-heading font-bold tracking-tight text-on-surface mb-6">{{ __('pages.field_send_us_a_message') }}</h2>

                    @if(session('success'))
                        <div class="mb-6 p-4 bg-secondary-container border border-secondary/20 rounded-lg" role="status" aria-live="polite">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-secondary text-lg shrink-0" aria-hidden="true">check_circle</span>
                                <p class="text-sm text-on-secondary-container">{{ session('success') }}</p>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('contact.submit') }}" class="space-y-5">
                        @csrf

                        {{-- Category --}}
                        <div>
                            <label for="category" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('support.field_category') }}</label>
                            <select name="category" id="category"
                                aria-invalid="@error('category') true @else false @enderror"
                                aria-describedby="category-error"
                                class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                                <option value="general" @selected(old('category', 'general') === 'general')>{{ __('support.category_general') }}</option>
                                <option value="account_recovery" @selected(old('category') === 'account_recovery')>{{ __('support.category_account_recovery') }}</option>
                            </select>
                            @error('category')
                                <p id="category-error" class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Name --}}
                        <div>
                            <label for="name" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_name') }} <span class="text-error">*</span></label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}"
                                aria-invalid="@error('name') true @else false @enderror"
                                aria-describedby="name-error"
                                class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"
                                required />
                            @error('name')
                                <p id="name-error" class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Email --}}
                        <div>
                            <label for="email" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('emails.field_email') }} <span class="text-error">*</span></label>
                            <input type="email" name="email" id="email" value="{{ old('email') }}"
                                aria-invalid="@error('email') true @else false @enderror"
                                aria-describedby="email-error"
                                class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"
                                required />
                            @error('email')
                                <p id="email-error" class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Subject --}}
                        <div>
                            <label for="subject" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_subject') }}</label>
                            <input type="text" name="subject" id="subject" value="{{ old('subject') }}"
                                aria-invalid="@error('subject') true @else false @enderror"
                                aria-describedby="subject-error"
                                class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('subject')
                                <p id="subject-error" class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Message --}}
                        <div>
                            <label for="message" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_message') }} <span class="text-error">*</span></label>
                            <textarea name="message" id="message" rows="6"
                                aria-invalid="@error('message') true @else false @enderror"
                                aria-describedby="message-error"
                                class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"
                                required>{{ old('message') }}</textarea>
                            @error('message')
                                <p id="message-error" class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit"
                            class="inline-flex items-center px-6 py-3 bg-primary text-on-primary rounded-xl font-semibold hover:brightness-110 transition-all text-sm">
                            <span class="material-symbols-outlined mr-2 text-base" aria-hidden="true">mail</span>
                            {{ __('common.field_send_message') }}
                        </button>
                    </form>
                </div>

                {{-- Contact Info --}}
                <div>
                    <h2 class="text-2xl font-heading font-bold tracking-tight text-on-surface mb-6">{{ __('common.content_other_ways_to_reach_us') }}</h2>

                    <div class="space-y-6">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">mail</span>
                            </div>
                            <div>
                                <h3 class="font-medium text-on-surface">{{ __('emails.field_email') }}</h3>
                                <p class="mt-1 text-sm text-on-surface-variant">{{ __('common.content_for_general_inquiries_and_support') }}</p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">schedule</span>
                            </div>
                            <div>
                                <h3 class="font-medium text-on-surface">{{ __('common.field_response_time') }}</h3>
                                <p class="mt-1 text-sm text-on-surface-variant">{{ __('common.content_we_typically_respond_within_24') }}</p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">groups</span>
                            </div>
                            <div>
                                <h3 class="font-medium text-on-surface">{{ __('pages.content_community') }}</h3>
                                <p class="mt-1 text-sm text-on-surface-variant">{{ __('events.content_join_events_connect_with_other') }}</p>
                                <a href="{{ route('events.index') }}" wire:navigate class="mt-2 inline-flex items-center text-sm font-medium text-primary hover:underline">
                                    {{ __('events.action_browse_events') }}
                                </a>
                            </div>
                        </div>
                    </div>

                    {{-- FAQ Quick Section --}}
                    <div class="mt-10 pt-10 border-t border-outline-variant">
                        <h3 class="font-heading font-semibold text-on-surface text-lg mb-4">{{ __('common.content_frequently_asked') }}</h3>
                        <div class="space-y-4">
                            <div>
                                <h4 class="text-sm font-medium text-on-surface">{{ __('events.content_how_do_i_create_an_event') }}</h4>
                                <p class="mt-1 text-sm text-on-surface-variant">{{ __('pages.content_sign_up_for_a_free') }}</p>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-on-surface">{{ __('common.content_is_it_free_to_use') }}</h4>
                                <p class="mt-1 text-sm text-on-surface-variant">{{ __('billing.content_creating_events_and_registering_for') }}</p>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-on-surface">{{ __('events.content_can_i_register_a_team') }}</h4>
                                <p class="mt-1 text-sm text-on-surface-variant">{{ __('events.content_yes_many_events_support_team') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-public-layout>
