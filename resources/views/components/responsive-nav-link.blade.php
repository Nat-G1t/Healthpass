@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 dark:border-hp-orange text-start text-base font-medium text-indigo-700 dark:text-hp-orange bg-indigo-50 dark:bg-hp-peach focus:outline-none focus:text-indigo-800 focus:dark:text-hp-orange focus:bg-indigo-100 focus:dark:bg-hp-peach focus:border-indigo-700 focus:dark:border-hp-orange transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-hp-slate/70 hover:text-gray-800 hover:dark:text-hp-slate hover:bg-gray-50 hover:dark:bg-hp-white hover:dark:bg-hp-white hover:border-gray-300 hover:dark:border-hp-slate/25 focus:outline-none focus:text-gray-800 focus:dark:text-hp-slate focus:bg-gray-50 focus:dark:bg-hp-white focus:dark:bg-hp-white focus:border-gray-300 focus:dark:border-hp-slate/25 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
