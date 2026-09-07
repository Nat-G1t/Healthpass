@props([
    'label' => null,
    'error' => null,
    'id'    => null,
])

@php
    $selectId = $id ?? ($label ? Str::slug($label, '_') : null);
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <label
            @if ($selectId) for="{{ $selectId }}" @endif
            class="text-sm font-semibold text-hp-slate"
        >{{ $label }}</label>
    @endif

    {{--
        pr-9, not px-3: the browser draws the dropdown arrow INSIDE the select's
        padding box at the right edge, so with equal padding a long option label
        runs underneath it and hides the arrow — "CCS — College of Computing
        Studies" was doing exactly that on the Staff Accounts form. The extra
        right padding reserves the arrow its own lane.
    --}}
    <select
        @if ($selectId) id="{{ $selectId }}" @endif
        {{ $attributes->merge([
            'class' => 'w-full rounded-lg border border-hp-slate/25 pl-3 pr-9 py-2 text-sm text-hp-slate
                        transition-colors duration-150 bg-hp-white
                        focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                        disabled:bg-hp-slate/5 disabled:cursor-not-allowed',
        ]) }}
    >
        {{ $slot }}
    </select>

    @if ($error)
        <p class="text-xs text-red-600">{{ $error }}</p>
    @endif
</div>
