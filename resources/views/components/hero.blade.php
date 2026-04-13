@props(['title', 'subtitle'])

<section class="bg-gradient-to-br from-[#C12E26] to-[#9A231F] dark:from-gray-800 dark:to-gray-900 text-white">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-16 sm:py-20 text-center">
        <h1 class="text-4xl sm:text-5xl font-heading font-bold uppercase tracking-wide">{{ $title }}</h1>
        @if($subtitle)
            <p class="mt-4 text-lg text-white/80 max-w-2xl mx-auto">{{ $subtitle }}</p>
        @endif
    </div>
</section>
