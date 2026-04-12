<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-5 py-2.5 bg-white dark:bg-gray-800 border-2 border-gray-300 dark:border-gray-500 rounded-lg font-heading font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-wider shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
