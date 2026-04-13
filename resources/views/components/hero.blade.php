@props(['title', 'subtitle'])

<section class="bg-gradient-to-br from-primary to-primary-container text-on-primary">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-16 sm:py-20 text-center">
        <h1 class="text-4xl sm:text-5xl font-heading font-bold tracking-tight">{{ $title }}</h1>
        @if($subtitle)
            <p class="mt-4 text-lg text-on-primary/80 max-w-2xl mx-auto">{{ $subtitle }}</p>
        @endif
    </div>
</section>
