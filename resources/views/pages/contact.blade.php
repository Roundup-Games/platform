<x-public-layout title="Contact">
    <x-hero title="Contact Us" subtitle="Have a question, suggestion, or just want to say hi? We'd love to hear from you." />

    <section class="py-16 sm:py-20 bg-white dark:bg-gray-800">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                {{-- Contact Form --}}
                <div>
                    <h2 class="text-2xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 mb-6">Send Us a Message</h2>

                    @if(session('success'))
                        <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg" role="status" aria-live="polite">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-green-600 dark:text-green-400 shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <p class="text-sm text-green-800 dark:text-green-300">{{ session('success') }}</p>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('contact.submit') }}" class="space-y-5">
                        @csrf

                        {{-- Name --}}
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] shadow-sm"
                                required />
                            @error('name')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Email --}}
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" id="email" value="{{ old('email') }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] shadow-sm"
                                required />
                            @error('email')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Subject --}}
                        <div>
                            <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject</label>
                            <input type="text" name="subject" id="subject" value="{{ old('subject') }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] shadow-sm" />
                            @error('subject')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Message --}}
                        <div>
                            <label for="message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Message <span class="text-red-500">*</span></label>
                            <textarea name="message" id="message" rows="6"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] shadow-sm"
                                required>{{ old('message') }}</textarea>
                            @error('message')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit"
                            class="inline-flex items-center px-6 py-3 bg-[#C12E26] text-white rounded-lg font-semibold hover:bg-[#9A231F] transition-colors text-sm">
                            <svg aria-hidden="true" class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            Send Message
                        </button>
                    </form>
                </div>

                {{-- Contact Info --}}
                <div>
                    <h2 class="text-2xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 mb-6">Other Ways to Reach Us</h2>

                    <div class="space-y-6">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center shrink-0">
                                <svg aria-hidden="true" class="w-5 h-5 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </div>
                            <div>
                                <h3 class="font-medium text-gray-900 dark:text-gray-100">Email</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">For general inquiries and support.</p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center shrink-0">
                                <svg aria-hidden="true" class="w-5 h-5 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <h3 class="font-medium text-gray-900 dark:text-gray-100">Response Time</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">We typically respond within 24–48 hours during business days.</p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center shrink-0">
                                <svg aria-hidden="true" class="w-5 h-5 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            </div>
                            <div>
                                <h3 class="font-medium text-gray-900 dark:text-gray-100">Community</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Join events, connect with other players, and be part of the community.</p>
                                <a href="{{ route('events.index') }}" class="mt-2 inline-flex items-center text-sm font-medium text-[#C12E26] hover:underline">
                                    Browse Events →
                                </a>
                            </div>
                        </div>
                    </div>

                    {{-- FAQ Quick Section --}}
                    <div class="mt-10 pt-10 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="font-heading font-semibold text-gray-900 dark:text-gray-100 text-lg mb-4">Frequently Asked</h3>
                        <div class="space-y-4">
                            <div>
                                <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">How do I create an event?</h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Sign up for a free account, then click "Create Event" from your dashboard or the events page.</p>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">Is it free to use?</h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Creating events and registering for free events is completely free. Paid events may have registration fees set by organizers.</p>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">Can I register a team?</h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Yes! Many events support team registration. Create a team, invite your members, and register together.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-public-layout>
