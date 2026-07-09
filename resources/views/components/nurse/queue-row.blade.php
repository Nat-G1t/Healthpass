@props([
    'visit',
    'isNext' => false,
])

{{--
    One Live Queue row (FR-NRS-01). The JS poller (live-queue.js) builds the
    SAME structure for rows that arrive after page load — keep the two in step.

    "NEXT-ness" is a single `data-next` attribute on the <tr>; the peach band,
    the Next tag, the reference line, and the primary/ghost buttons are all
    styled/toggled by CSS in queue.blade.php off that one attribute.
--}}
@php
    $vs = $visit->vitalSigns;

    // Initials avatar — first letters of up to the first two words.
    $initials = collect(explode(' ', $visit->student->name ?? ''))
        ->filter()
        ->map(fn ($w) => strtoupper(substr($w, 0, 1)))
        ->take(2)
        ->implode('');

    // Capture time = kiosk check-in (frozen at submit); fall back to created_at
    // only for a legacy row that lacks it.
    $capturedAt = $visit->checked_in_at ?? $visit->created_at;

    // A vital renders bold orange when its stored flag is set (values are
    // server-computed at submit — never recomputed here).
    $flagged = 'font-bold text-hp-orange';
    $normal  = 'text-hp-slate/70';
@endphp

<tr data-visit-id="{{ $visit->id }}" @if ($isNext) data-next @endif>

    {{-- Student: avatar + name; Next tag on the top row, reference line otherwise --}}
    <td class="py-4 pl-4 pr-6 whitespace-nowrap">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full
                        bg-hp-peach text-xs font-bold text-hp-orange" data-cell="initials">
                {{ $initials }}
            </div>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-hp-slate" data-cell="name">
                    {{ $visit->student->name ?? '—' }}
                </p>
                <span class="queue-next-tag mt-0.5 inline-flex items-center rounded-full bg-hp-orange
                             px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-white">
                    Next
                </span>
                <p class="queue-ref font-mono text-[11px] text-hp-slate/35" data-cell="ref">{{ $visit->reference_no }}</p>
            </div>
        </div>
    </td>

    {{-- College (capture-time snapshot) --}}
    <td class="py-4 pr-6 text-sm text-hp-slate/60 whitespace-nowrap" data-cell="college">
        {{ $visit->college->name ?? '—' }}
    </td>

    {{-- Inline vitals summary — flagged values bold orange --}}
    <td class="py-4 pr-6 text-sm whitespace-nowrap" data-cell="vitals">
        @if ($vs)
            <div class="flex items-center gap-x-3">
                <span class="{{ $vs->is_temp_flagged ? $flagged : $normal }}">{{ $vs->temperature_c }}°C</span>
                <span class="text-hp-slate/20">·</span>
                <span class="{{ $vs->is_bp_flagged ? $flagged : $normal }}">{{ $vs->bp_systolic }}/{{ $vs->bp_diastolic }}</span>
                <span class="text-hp-slate/20">·</span>
                <span class="{{ $vs->is_bmi_flagged ? $flagged : $normal }}">BMI {{ $vs->bmi }}</span>
                <span class="text-hp-slate/20">·</span>
                <span class="{{ $normal }}">{{ $vs->heart_rate_bpm }} bpm</span>
            </div>
        @else
            <span class="text-hp-slate/30">—</span>
        @endif
    </td>

    {{-- Flags column — a badge per flagged vital, or a dash --}}
    <td class="py-4 pr-6 whitespace-nowrap" data-cell="flags">
        @if ($vs && ($vs->is_temp_flagged || $vs->is_bp_flagged || $vs->is_bmi_flagged))
            <div class="flex flex-wrap gap-1.5">
                @if ($vs->is_temp_flagged) <x-hp.badge variant="flagged">Temp</x-hp.badge> @endif
                @if ($vs->is_bp_flagged)   <x-hp.badge variant="flagged">BP</x-hp.badge>   @endif
                @if ($vs->is_bmi_flagged)  <x-hp.badge variant="flagged">BMI</x-hp.badge>  @endif
            </div>
        @else
            <span class="text-hp-slate/30">—</span>
        @endif
    </td>

    {{-- Capture time, humanized (e.g. "2m ago") --}}
    <td class="py-4 pr-6 text-sm text-hp-slate/50 whitespace-nowrap" data-cell="time">
        {{ $capturedAt?->diffForHumans() ?? '—' }}
    </td>

    {{-- Encode Result — stub until the encode flow lands (FR-NRS-03).
         Primary on the top row, ghost on the rest (FR-NRS-01). --}}
    <td class="py-4 pr-4 text-right whitespace-nowrap">
        <span class="queue-btn-next"><x-hp.button type="button" size="sm" variant="primary">Encode Result</x-hp.button></span>
        <span class="queue-btn-rest"><x-hp.button type="button" size="sm" variant="ghost">Encode Result</x-hp.button></span>
    </td>

</tr>
