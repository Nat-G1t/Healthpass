@props([
    'label'    => null,
    'error'    => null,
    'password' => false,
    'id'       => null,
])

@php
    $inputId      = $id ?? ($label ? Str::slug($label, '_') : null);
    $baseClasses  = 'w-full rounded-lg border-[1.5px] border-hp-slate/25 px-3.5 py-2.5 text-sm text-hp-slate
                     placeholder-hp-slate/40 transition-colors duration-hp-fast
                     focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                     disabled:bg-hp-slate/5 disabled:cursor-not-allowed';

    // Validation motion (§5.7): a field that arrives with an error shakes once
    // (hp-anim-shake) and its message fades in — at rest nothing changes.
    if ($error) {
        $baseClasses .= ' hp-anim-shake';
    }
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <label
            @if ($inputId) for="{{ $inputId }}" @endif
            class="text-[13px] font-semibold text-hp-slate"
        >{{ $label }}</label>
    @endif

    @if ($password)
        {{-- Password field with eye toggle (Alpine.js) --}}
        <div x-data="{ show: false }" class="relative">
            <input
                @if ($inputId) id="{{ $inputId }}" @endif
                type="password"
                :type="show ? 'text' : 'password'"
                {{ $attributes->merge(['class' => $baseClasses . ' pr-10']) }}
            >
            <button
                type="button"
                @click="show = !show"
                :aria-label="show ? 'Hide password' : 'Show password'"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-hp-slate/50 hover:text-hp-slate"
                tabindex="-1"
            >
                {{-- Eye icon --}}
                <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                {{-- EyeOff icon --}}
                <svg x-show="show" xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     style="display:none">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
            </button>
        </div>
    @else
        <input
            @if ($inputId) id="{{ $inputId }}" @endif
            {{ $attributes->merge(['class' => $baseClasses]) }}
        >
    @endif

    @if ($error)
        <p class="hp-anim-fade text-xs text-red-600">{{ $error }}</p>
    @endif
</div>
