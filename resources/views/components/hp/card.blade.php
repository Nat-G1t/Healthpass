@props(['class' => ''])

<div {{ $attributes->merge([
    'class' => "bg-white rounded-xl border border-hp-slate/15 p-6 {$class}",
]) }}>
    {{ $slot }}
</div>
