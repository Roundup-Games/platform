<x-public-layout>

<x-hero :title="__('notifications.unsubscribe_confirm_title')" />

<section class="py-16 sm:py-20 bg-surface">
    <div class="max-w-2xl mx-auto px-4 text-center">

        {{-- Scanner-safe confirmation step (invite-optout precedent): GET renders
             this page without any write; the POST form below performs the opt-out.
             URL-sandboxing mail scanners (SafeLinks & co.) only GET — they can no
             longer silently disable a category the user never touched. --}}
        <p class="text-lg text-on-surface/80 mb-8">
            {{ __('notifications.unsubscribe_confirm_content', ['category' => $unsubscribeTarget]) }}
        </p>

        <form method="POST" action="{{ $actionUrl }}">
            {{-- The form POSTs back to the exact signed URL (signature query
                 intact). _from marks the human confirmation leg: RFC 8058
                 one-click POSTs carry no form fields and must keep getting the
                 bare 200 they require, while this leg gets the redirect UX. --}}
            <input type="hidden" name="_from" value="link">
            <button type="submit"
                    class="inline-block px-6 py-3 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors">
                {{ __('notifications.unsubscribe_confirm_action') }}
            </button>
        </form>

        <p class="mt-6 text-sm text-on-surface/60">
            {{ __('notifications.unsubscribe_confirm_note') }}
        </p>

    </div>
</section>

</x-public-layout>
