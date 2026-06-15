@props(['size' => 'md'])

@php
    [$iconSize, $textSize, $gap] = match ($size) {
        'sm'  => ['20', 'text-sm',  'gap-1.5'],
        'md'  => ['28', 'text-lg',  'gap-2'],
        'lg'  => ['40', 'text-2xl', 'gap-3'],
        default => ['28', 'text-lg', 'gap-2'],
    };
@endphp

<div class="inline-flex items-center {{ $gap }}">
    {{-- Orange plus-cross mark --}}
    <svg
        width="{{ $iconSize }}"
        height="{{ $iconSize }}"
        viewBox="0 0 40 40"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
    >
        {{-- Horizontal bar --}}
        <rect x="0"  y="14" width="40" height="12" rx="6" fill="#FF8C2A"/>
        {{-- Vertical bar --}}
        <rect x="14" y="0"  width="12" height="40" rx="6" fill="#FF8C2A"/>
    </svg>

    {{-- Wordmark --}}
    <span class="font-bold leading-none {{ $textSize }}" style="font-family: 'Poppins', sans-serif;">
        <span style="color: #4B5563;">Health</span><span style="color: #FF8C2A;">Pass</span>
    </span>
</div>
