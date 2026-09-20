{{-- ── Vital signs as the clinic encoded them (D-65) ────────────────────────────
     The clinic's CONFIRMED copy — the numbers that print on the document —
     not the kiosk's raw reading, so the screen and the paper agree. A record
     encoded before D-65 has no confirmed copy and falls back to the kiosk's,
     which is what it always printed.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $vs        = $visit->vitalSigns;
    $confirmed = $visit->clearanceRecord?->printedVitals($vs) ?? [];

    // Normalize every number to ONE string shape: encoded_vitals stores JSON
    // floats while vital_signs casts to decimal strings, so 165 and "165.0"
    // are the same reading.
    $decimal = fn ($value) => $value === null ? null : number_format((float) $value, 1);
    $whole   = fn ($value) => $value === null ? null : (string) (int) $value;
    $unit    = fn (?string $value, string $suffix) => $value === null ? '—' : trim("{$value} {$suffix}");

    $bp = (isset($confirmed['bp_systolic'], $confirmed['bp_diastolic']))
        ? $whole($confirmed['bp_systolic']).'/'.$whole($confirmed['bp_diastolic']).' mmHg'
        : '—';

    // ── Rest & re-check (D-72) ───────────────────────────────────────────────
    // Which readings were re-taken after the rest. recheckStepsFor() is the
    // SAME rule the kiosk walked, so the label cannot claim a reading was
    // re-taken that the kiosk never asked for. The 'bp' step covers blood
    // pressure AND heart rate — one cuff measurement produces both.
    $first = is_array($vs?->first_reading) ? $vs->first_reading : [];

    $steps = $first === [] ? [] : \App\Models\VitalSigns::recheckStepsFor([
        'is_temp_flagged' => (bool) ($first['is_temp_flagged'] ?? false),
        'is_bp_flagged' => (bool) ($first['is_bp_flagged'] ?? false),
        'is_hr_flagged' => (bool) ($first['is_hr_flagged'] ?? false),
    ]);

    $retookVitals = in_array('bp', $steps, true);
    $retookTemp   = in_array('temp', $steps, true);

    // [label, value, flagged?, re-checked?]
    $tiles = [
        ['Height', $unit($decimal($confirmed['height_cm'] ?? null), 'cm'), false, false],
        ['Weight', $unit($decimal($confirmed['weight_kg'] ?? null), 'kg'), false, false],
        ['BMI', $unit($decimal($confirmed['bmi'] ?? null), ''), (bool) $vs?->is_bmi_flagged, false],
        ['Temperature', $unit($decimal($confirmed['temperature_c'] ?? null), '°C'), (bool) $vs?->is_temp_flagged, $retookTemp],
        ['Blood Pressure', $bp, (bool) $vs?->is_bp_flagged, $retookVitals],
        ['Heart Rate', $unit($whole($confirmed['heart_rate_bpm'] ?? null), 'bpm'), (bool) $vs?->is_hr_flagged, $retookVitals],
        ['Respiratory Rate', $unit($whole($confirmed['respiratory_rate'] ?? null), 'breaths/min'), (bool) $vs?->is_rr_flagged, false],
    ];
@endphp

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">Vital Signs</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">As confirmed by the clinic when this visit was encoded.</p>

    {{-- D-72: the first pass read high, you rested, and the numbers below are
         the re-take. Saying so keeps a "re-checked" tag from reading as a
         correction of the kiosk. --}}
    @if ($vs?->firstReadingSummary())
        <p class="mt-3 rounded-lg bg-hp-peach/30 px-3 py-2 text-xs text-hp-slate/70">
            Your first reading was {{ $vs->firstReadingSummary() }}@if ($vs->firstReadingTakenAtLabel()) at {{ $vs->firstReadingTakenAtLabel() }}@endif.
            You rested and re-took it — the re-checked readings are below.
        </p>
    @endif

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($tiles as [$label, $value, $isFlagged, $wasRechecked])
            <div class="rounded-xl bg-hp-bg p-3">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">{{ $label }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="text-lg font-bold {{ $isFlagged ? 'text-hp-orange' : 'text-hp-slate' }}">{{ $value }}</span>
                    @if ($isFlagged)
                        <x-hp.badge variant="flagged">⚑ Flagged</x-hp.badge>
                    @endif
                </div>
                @if ($wasRechecked)
                    <p class="mt-1 text-[11px] text-hp-slate/45">re-checked</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- A flag is a reading outside the usual range, not a result — the result
         is the clinic's, above. Saying so stops an orange tile reading as bad
         news on its own. --}}
    <p class="mt-4 text-xs text-hp-slate/50">
        A flagged reading is simply one outside the usual range. Your result above is the clinic's.
    </p>
</x-hp.card>
