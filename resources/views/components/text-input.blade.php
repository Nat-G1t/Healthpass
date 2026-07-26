@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 dark:border-hp-slate/25 focus:border-indigo-500 focus:dark:border-hp-orange focus:ring-indigo-500 focus:dark:ring-hp-orange rounded-md shadow-sm']) }}>
