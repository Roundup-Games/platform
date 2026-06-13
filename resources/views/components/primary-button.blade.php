<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-5 py-2.5 bg-primary text-on-primary rounded-xl font-semibold text-sm hover:opacity-90 active:scale-[0.98] focus:outline-hidden focus:ring-2 focus:ring-secondary/20 focus:ring-offset-2 focus:ring-offset-surface transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
