@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-brand dark:focus:border-brand-light focus:ring-brand dark:focus:ring-brand-light rounded-lg shadow-sm']) }}>
