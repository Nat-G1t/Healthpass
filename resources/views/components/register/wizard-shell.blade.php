@props(['step' => 1, 'maxWidth' => 'max-w-[480px]'])

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
    @include('partials.favicon')
</head>
<body class="flex min-h-full flex-col items-center bg-hp-bg p-6">

    {{-- Logo (md size, no subtitle — matches prototype) --}}
    <div class="mb-[22px]">
        <x-hp.logo size="md" />
    </div>

    {{-- Progress bar — 4 coloured bar segments with labels below --}}
    <div class="mb-[22px] flex w-full {{ $maxWidth }} gap-[6px]">
        @foreach ($steps as $n => $label)
            <div class="flex flex-1 flex-col text-center">
                <div class="mb-[5px] h-1 rounded-sm transition-colors duration-300
                            {{ $n <= $step ? 'bg-hp-orange' : 'bg-hp-slate/[18%]' }}"></div>
                <span class="text-[10px] leading-none
                             {{ $n === $step ? 'font-bold text-hp-orange' : 'font-normal text-hp-slate/[45%]' }}">
                    {{ $label }}
                </span>
            </div>
        @endforeach
    </div>

    {{-- Step card --}}
    <x-hp.card class="w-full {{ $maxWidth }}">
        {{ $slot }}
    </x-hp.card>

    {{-- RA 10173 footer --}}
    <p class="mt-6 text-center text-[10.5px] leading-[1.7] text-hp-slate/[45%]">
        This system is governed by <strong>RA 10173</strong> (Data Privacy Act of 2012).<br>
        Health data is used solely for medical clearance purposes.
    </p>

</body>
</html>
