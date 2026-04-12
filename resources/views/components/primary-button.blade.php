<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-5 py-2.5 bg-brand border border-transparent rounded-lg font-heading font-semibold text-sm text-white uppercase tracking-wider hover:bg-brand-dark active:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
