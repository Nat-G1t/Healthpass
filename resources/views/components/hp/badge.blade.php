@props([
    'variant' => 'neutral',
    {{--
        Peach group  — bg-hp-peach, text-hp-orange:  positive | approved | fit | flagged
        Live         — bg-hp-orange, text-white:      live
        Slate group  — bg-hp-slate/10, text-hp-slate: neutral | pending | rejected | unfit
        Red          — bg-red-100, text-red-700:      danger (an Expired clearance, D-107)
    --}}
])

@php
    $classes = match ($variant) {
        'positive', 'approved', 'fit', 'cleared'
                     => 'bg-hp-peach text-hp-orange',
        'flagged'    => 'bg-hp-peach text-hp-orange',
        'live'       => 'bg-hp-orange text-white',
        'danger'     => 'bg-red-100 text-red-700',
        'rejected', 'unfit'
                     => 'bg-hp-slate/10 text-hp-slate',
        default      => 'bg-hp-slate/10 text-hp-slate', // neutral | pending
    };
@endphp

{{--
    `max-w-full` + `break-words` keep long labels — the longest real one is
    BatchRequest::statusLabel()'s "Pending Director Approval" — inside the
    pill: it wraps and the pill grows instead of the text spilling out of it.
    `leading-tight` (not `leading-none`) gives those wrapped lines room.
--}}
<span {{ $attributes->merge([
    'class' => "inline-flex max-w-full items-center justify-center rounded-full px-2.5 py-0.5 text-center text-[11px] font-semibold leading-tight break-words {$classes}",
]) }}>
    {{ $slot }}
</span>
