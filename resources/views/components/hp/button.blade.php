@props([
    'variant' => 'primary',  {{-- primary | ghost | soft | muted --}}
    'size'    => 'md',       {{-- sm | md | lg | xl --}}
    'type'    => 'button',
])

@php
    $variantClasses = match ($variant) {
        'primary' => 'bg-hp-orange text-white hover:bg-orange-500 focus-visible:ring-hp-orange',
        'ghost'   => 'bg-transparent text-hp-slate border-[1.5px] border-hp-slate/30 hover:bg-hp-slate/8 focus-visible:ring-hp-slate',
        'soft'    => 'bg-hp-peach text-hp-orange hover:bg-orange-100 focus-visible:ring-hp-orange',
        'muted'   => 'bg-hp-slate/10 text-hp-slate hover:bg-hp-slate/20 focus-visible:ring-hp-slate',
        'danger'  => 'bg-transparent text-red-500 border-[1.5px] border-red-300 hover:bg-red-50 focus-visible:ring-red-500',
        default   => '',
    };

    $sizeClasses = match ($size) {
        'sm'  => 'px-4 py-1.5 text-xs',
        'md'  => 'px-6 py-2.5 text-sm',
        'lg'  => 'px-8 py-[13px] text-[15px]',
        'xl'  => 'px-12 py-4 text-base',
        default => 'px-6 py-2.5 text-sm',
    };
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->merge([
        'class' => "inline-flex items-center justify-center gap-2 rounded-full font-semibold
                    transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2
                    focus-visible:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed
                    {$variantClasses} {$sizeClasses}",
    ]) }}
>
    {{ $slot }}
</button>
