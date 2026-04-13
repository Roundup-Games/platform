<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-5 py-2.5 bg-secondary-container text-on-secondary-container rounded-xl font-semibold text-sm hover:opacity-90 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:ring-offset-2 focus:ring-offset-surface disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
