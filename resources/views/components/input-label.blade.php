@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-700 dark:text-hp-slate/80']) }}>
    {{ $value ?? $slot }}
</label>
