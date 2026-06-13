<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-error-container text-on-error-container rounded-xl font-semibold text-sm hover:opacity-90 active:scale-[0.98] focus:outline-hidden focus:ring-2 focus:ring-error/30 focus:ring-offset-2 focus:ring-offset-surface transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
