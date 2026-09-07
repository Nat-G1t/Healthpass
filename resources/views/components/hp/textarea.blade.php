@props([
    'label' => null,
    'error' => null,
    'id'    => null,
    'rows'  => 4,
])

@php
    $areaId = $id ?? ($label ? Str::slug($label, '_') : null);
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <label
            @if ($areaId) for="{{ $areaId }}" @endif
            class="text-sm font-semibold text-hp-slate"
        >{{ $label }}</label>
    @endif

    <textarea
        @if ($areaId) id="{{ $areaId }}" @endif
        rows="{{ $rows }}"
        {{ $attributes->merge([
            'class' => 'w-full rounded-lg border border-hp-slate/25 px-3 py-2 text-sm text-hp-slate
                        placeholder-hp-slate/40 resize-y transition-colors duration-150
                        focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                        disabled:bg-hp-slate/5 disabled:cursor-not-allowed',
        ]) }}
    >{{ $slot }}</textarea>

    @if ($error)
        <p class="text-xs text-red-600">{{ $error }}</p>
    @endif
</div>
