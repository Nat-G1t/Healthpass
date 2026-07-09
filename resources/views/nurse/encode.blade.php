<x-layout.sidebar title="Doctor's Assessment">

{{-- ── Encode Result — "Doctor's Assessment" (FR-NRS-03, BR-15) ─────────────────
     One screen, two modes (see EncodeController):
       $readOnly = false → captured visit, editable assessment form
       $readOnly = true  → encoded visit, same screen locked + Reprint

     Left column: everything the nurse assesses FROM (identity, vitals,
     questionnaire) — all server-frozen capture-time values. Right column:
     the assessment form itself. Save & Close / Preview & Print / Reprint are
     stubs today — wired in FR-NRS-04/05.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $student = $visit->student;
    $profile = $student?->studentProfile;
    $vs      = $visit->vitalSigns;
    $sr      = $visit->screeningResponse;
    $record  = $visit->clearanceRecord;

    // Initials avatar — same recipe as the queue row.
    $initials = collect(explode(' ', $student->name ?? ''))
        ->filter()
        ->map(fn ($w) => strtoupper(substr($w, 0, 1)))
        ->take(2)
        ->implode('');

    $sexLabel = match ($profile?->sex) { 'M' => 'Male', 'F' => 'Female', default => '—' };

    $capturedAt = $visit->checked_in_at ?? $visit->created_at;

    // The 9 body systems, column → label. Mirrors SYSTEMS in
    // resources/js/kiosk/state-machine.js — keep the two lists in step.
    $systems = [
        'vision'      => 'Vision / Eyes',
        'hearing'     => 'Hearing / Ears',
        'nose'        => 'Nose & Throat',
        'skin'        => 'Skin',
        'respiratory' => 'Respiratory / Breathing',
        'heart'       => 'Heart / Circulation',
        'digestive'   => 'Digestive / Stomach',
        'bones'       => 'Bones & Joints',
        'nervous'     => 'Nervous / Neurological',
    ];
@endphp

{{-- ── Page header: back link, title, lifecycle badge ─────────────────────────── --}}
<div class="mb-7">
    <a href="{{ route('nurse.queue') }}"
       class="text-sm font-medium text-hp-slate/60 transition-colors hover:text-hp-orange">
        ← Back to Live Queue
    </a>
    <div class="mt-2 flex flex-wrap items-center gap-2.5">
        <h2 class="text-xl font-semibold text-hp-slate">Doctor's Assessment</h2>
        @if ($readOnly)
            <x-hp.badge variant="neutral">Encoded</x-hp.badge>
        @else
            <x-hp.badge variant="flagged">Awaiting encode</x-hp.badge>
        @endif
    </div>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        <span class="font-mono">{{ $visit->reference_no }}</span>
        · captured {{ $capturedAt?->diffForHumans() ?? '—' }}
    </p>
</div>

{{-- Read-only notice — encoding is one-time (BR-11 / FR-NRS-04). --}}
@if ($readOnly)
    <div class="mb-5 rounded-xl border border-hp-peach bg-hp-peach/30 px-4 py-3 text-sm text-hp-slate">
        This visit has already been encoded
        @if ($record)
            by <span class="font-semibold">{{ $record->encoder->name ?? '—' }}</span>
            on {{ $record->encoded_at?->format('M j, Y g:i A') ?? '—' }}
        @endif
        — the assessment below is read-only. Use <span class="font-semibold">Reprint</span> for another copy.
    </div>
@endif

<div class="grid items-start gap-5 lg:grid-cols-3">

    {{-- ══ Left column — what the nurse assesses FROM ══════════════════════════ --}}
    <div class="space-y-5 lg:col-span-2">

        {{-- ── Student identity ────────────────────────────────────────────── --}}
        <x-hp.card>
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full
                            bg-hp-peach text-lg font-bold text-hp-orange">
                    {{ $initials }}
                </div>
                <div class="min-w-0">
                    <p class="text-lg font-semibold text-hp-slate">{{ $student->name ?? '—' }}</p>
                    <p class="font-mono text-sm text-hp-slate/50">{{ $profile->student_number ?? '—' }}</p>
                </div>
            </div>
            <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">College</dt>
                    {{-- Capture-time snapshot (FR-STU-09/D-17), not the profile's current college. --}}
                    <dd class="mt-0.5 text-hp-slate">{{ $visit->college->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Course &amp; Year</dt>
                    <dd class="mt-0.5 text-hp-slate">{{ $profile->course ?? '—' }} · {{ $profile->year_level ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Age</dt>
                    <dd class="mt-0.5 text-hp-slate">{{ $profile?->date_of_birth?->age ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Sex</dt>
                    <dd class="mt-0.5 text-hp-slate">{{ $sexLabel }}</dd>
                </div>
            </dl>
        </x-hp.card>

        {{-- ── Vital signs — server-frozen values + flag badges (BR-14) ─────── --}}
        <x-hp.card>
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-hp-slate">Vital Signs</h3>
                @if ($vs)
                    <span class="text-xs text-hp-slate/40">Entry: {{ ucfirst($vs->entry_method) }}</span>
                @endif
            </div>
            @if ($vs)
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ([
                        ['Height',         $vs->height_cm.' cm',                          false],
                        ['Weight',         $vs->weight_kg.' kg',                          false],
                        ['BMI',            $vs->bmi,                                      $vs->is_bmi_flagged],
                        ['Temperature',    $vs->temperature_c.' °C',                      $vs->is_temp_flagged],
                        ['Blood Pressure', $vs->bp_systolic.'/'.$vs->bp_diastolic.' mmHg', $vs->is_bp_flagged],
                        ['Heart Rate',     $vs->heart_rate_bpm.' bpm',                    false],
                    ] as [$label, $value, $isFlagged])
                        <div class="rounded-xl bg-hp-bg p-3">
                            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">{{ $label }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="text-lg font-bold {{ $isFlagged ? 'text-hp-orange' : 'text-hp-slate' }}">{{ $value }}</span>
                                @if ($isFlagged)
                                    <x-hp.badge variant="flagged">⚑ Flagged</x-hp.badge>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-3 text-sm text-hp-slate/40">No vitals recorded for this visit.</p>
            @endif
        </x-hp.card>

        {{-- ── Questionnaire — 9 systems + pregnancy/LMP (FR-KSK-10 data) ───── --}}
        <x-hp.card>
            <h3 class="text-sm font-semibold text-hp-slate">Health Questionnaire</h3>
            @if ($sr)
                <div class="mt-2 grid gap-x-8 sm:grid-cols-2">
                    @foreach ($systems as $column => $label)
                        <div class="flex items-center justify-between gap-3 border-b border-hp-slate/10 py-2">
                            <span class="text-sm text-hp-slate/70">{{ $label }}</span>
                            {{-- Kiosk colour language: orange = reported issue, green = all clear. --}}
                            <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold
                                         {{ $sr->{$column} ? 'bg-hp-orange/15 text-hp-orange' : 'bg-emerald-50 text-emerald-600' }}">
                                {{ $sr->{$column} ? 'Yes' : 'No' }}
                            </span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between gap-3 border-b border-hp-slate/10 py-2">
                        <span class="text-sm text-hp-slate/70">
                            Currently pregnant
                            @if ($sr->is_pregnant)
                                <span class="block text-xs text-hp-slate/40">
                                    LMP: {{ $sr->last_menstrual_period?->format('M j, Y') ?? '—' }}
                                </span>
                            @endif
                        </span>
                        <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold
                                     {{ $sr->is_pregnant ? 'bg-hp-orange/15 text-hp-orange' : 'bg-emerald-50 text-emerald-600' }}">
                            {{ $sr->is_pregnant ? 'Yes' : 'No' }}
                        </span>
                    </div>
                </div>
            @else
                <p class="mt-3 text-sm text-hp-slate/40">No questionnaire recorded for this visit.</p>
            @endif
        </x-hp.card>
    </div>

    {{-- ══ Right column — the assessment form (FR-NRS-03) ══════════════════════ --}}
    <x-hp.card class="lg:sticky lg:top-20">
        <h3 class="text-sm font-semibold text-hp-slate">Assessment</h3>
        <p class="mt-0.5 text-xs text-hp-slate/50">
            Result is required; category and purpose are optional (BR-16).
        </p>

        {{-- No action yet — Save & Close POSTs in FR-NRS-04. Buttons are
             type="button" so nothing can submit accidentally today. --}}
        <form class="mt-5 space-y-5">

            {{-- Result — Fit / Unfit, the one required field (BR-16).
                 Radios are visually-hidden (.sr-only) siblings of the styled
                 tiles; Tailwind's peer-checked does the "toggle" look with no
                 JS. Kiosk colour language: green = clear, orange = attention. --}}
            <div>
                <span class="text-sm font-semibold text-hp-slate">
                    Result <span class="text-hp-orange">*</span>
                </span>
                <div class="mt-1.5 grid grid-cols-2 gap-3">
                    <label class="{{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                        <input type="radio" name="result" value="Fit" class="peer sr-only" required
                               @checked($record?->result === 'Fit') @disabled($readOnly)>
                        <span class="flex items-center justify-center rounded-xl border-2 border-hp-slate/20
                                     py-3 text-base font-bold text-hp-slate/50 transition-colors
                                     peer-checked:border-emerald-500 peer-checked:bg-emerald-500 peer-checked:text-white
                                     peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500 peer-focus-visible:ring-offset-2">
                            Fit
                        </span>
                    </label>
                    <label class="{{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                        <input type="radio" name="result" value="Unfit" class="peer sr-only"
                               @checked($record?->result === 'Unfit') @disabled($readOnly)>
                        <span class="flex items-center justify-center rounded-xl border-2 border-hp-slate/20
                                     py-3 text-base font-bold text-hp-slate/50 transition-colors
                                     peer-checked:border-hp-orange peer-checked:bg-hp-orange peer-checked:text-white
                                     peer-focus-visible:ring-2 peer-focus-visible:ring-hp-orange peer-focus-visible:ring-offset-2">
                            Unfit
                        </span>
                    </label>
                </div>
                @error('result')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <x-hp.select label="Medical Case Category" name="case_category" :disabled="$readOnly">
                <option value="">— Optional —</option>
                @foreach (\App\Models\ClearanceRecord::CASE_CATEGORIES as $category)
                    <option value="{{ $category }}" @selected($record?->case_category === $category)>{{ $category }}</option>
                @endforeach
            </x-hp.select>

            <x-hp.select label="Purpose" name="purpose" :disabled="$readOnly">
                <option value="">— Optional —</option>
                @foreach (\App\Models\ClearanceRecord::PURPOSES as $purpose)
                    <option value="{{ $purpose }}" @selected($record?->purpose === $purpose)>{{ $purpose }}</option>
                @endforeach
            </x-hp.select>

            <x-hp.textarea label="Nurse Notes" name="nurse_notes" rows="4" :disabled="$readOnly"
                           placeholder="Observations, advice given, follow-ups…">{{ $record?->nurse_notes }}</x-hp.textarea>

            <div class="flex flex-col gap-2.5 pt-1">
                @if ($readOnly)
                    {{-- Stub — FR-NRS-05 wires this to the print view + re-stamps printed_at. --}}
                    <x-hp.button type="button" variant="soft" class="w-full">Reprint</x-hp.button>
                @else
                    {{-- Stubs — FR-NRS-05 (print preview) and FR-NRS-04 (save) wire these. --}}
                    <x-hp.button type="button" variant="ghost" class="w-full">Preview &amp; Print</x-hp.button>
                    <x-hp.button type="button" variant="primary" class="w-full">Save &amp; Close</x-hp.button>
                @endif
            </div>
        </form>
    </x-hp.card>

</div>

</x-layout.sidebar>
