@props(['step' => 1])

@php
    $steps = [
        1 => 'Consent',
        2 => 'Account Info',
        3 => 'Email Verify',
        4 => 'Link ID',
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Register — HealthPass</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col items-center bg-hp-bg px-4 py-10">

    {{-- Logo --}}
    <div class="mb-8 flex flex-col items-center gap-2 text-center">
        <x-hp.logo size="lg" />
        <p class="text-sm text-hp-slate/60">Medical Clearance — Pampanga State University</p>
    </div>

    {{-- Progress bar --}}
    <div class="mb-6 w-full max-w-2xl px-2">
        <div class="flex items-start">
            @foreach ($steps as $n => $label)
                {{-- Step circle + label --}}
                <div class="flex flex-1 flex-col items-center">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold transition-colors
                        @if ($n < $step) bg-hp-orange text-white
                        @elseif ($n === $step) bg-hp-orange text-white ring-4 ring-hp-peach
                        @else bg-white border-2 border-hp-slate/25 text-hp-slate/40
                        @endif">
                        @if ($n < $step)
                            {{-- Checkmark for completed steps --}}
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="3"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        @else
                            {{ $n }}
                        @endif
                    </div>
                    <span class="mt-1.5 text-center text-[11px] font-semibold leading-tight
                        @if ($n <= $step) text-hp-orange @else text-hp-slate/40 @endif">
                        {{ $label }}
                    </span>
                </div>

                {{-- Connector line between steps --}}
                @if ($n < count($steps))
                    <div class="mt-[18px] h-0.5 flex-1
                        @if ($n < $step) bg-hp-orange @else bg-hp-slate/20 @endif">
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Step card --}}
    <x-hp.card class="w-full max-w-2xl">
        {{ $slot }}
    </x-hp.card>

    {{-- Footer --}}
    <p class="mt-6 text-center text-[11px] leading-relaxed text-hp-slate/40">
        Information collected is protected under the Data Privacy Act of 2012 (RA 10173).<br>
        For concerns, contact the clinic or your Data Protection Officer.
    </p>

</body>
</html>
