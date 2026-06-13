@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-surface-container-high text-on-surface rounded-md placeholder:text-outline focus:bg-surface-container-lowest focus:ring-2 focus:ring-secondary/20 focus:outline-hidden transition duration-150 ease-in-out']) }}>
