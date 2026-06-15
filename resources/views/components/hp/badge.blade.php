@props([
    'variant' => 'neutral',
    {{--
        Peach group  — bg-hp-peach, text-hp-orange:  positive | approved | fit | flagged
        Live         — bg-hp-orange, text-white:      live
        Slate group  — bg-hp-slate/10, text-hp-slate: neutral | pending | rejected | unfit
    --}}
])

@php
    $classes = match ($variant) {
        'positive', 'approved', 'fit', 'cleared'
                     => 'bg-hp-peach text-hp-orange',
        'flagged'    => 'bg-hp-peach text-hp-orange',
        'live'       => 'bg-hp-orange text-white',
        'rejected', 'unfit'
                     => 'bg-hp-slate/10 text-hp-slate',
        default      => 'bg-hp-slate/10 text-hp-slate', // neutral | pending
    };
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold leading-none {$classes}",
]) }}>
    {{ $slot }}
</span>
