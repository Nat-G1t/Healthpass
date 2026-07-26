@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-indigo-400 dark:border-hp-orange text-sm font-medium leading-5 text-gray-900 dark:text-hp-slate focus:outline-none focus:border-indigo-700 focus:dark:border-hp-orange transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 dark:text-hp-slate/60 hover:text-gray-700 hover:dark:text-hp-slate/80 hover:border-gray-300 hover:dark:border-hp-slate/25 focus:outline-none focus:text-gray-700 focus:dark:text-hp-slate/80 focus:border-gray-300 focus:dark:border-hp-slate/25 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
