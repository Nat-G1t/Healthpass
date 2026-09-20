<x-layout.sidebar title="Doctor's Assessment">

{{-- ── Encode Result — "Doctor's Assessment" (FR-NRS-03, BR-15) ─────────────────
     One screen, two modes (see EncodeController):
       $readOnly = false → captured visit, editable assessment form
       $readOnly = true  → encoded visit, same screen locked + Reprint

     Left column: everything the nurse assesses FROM (identity, vitals,
     questionnaire) — all server-frozen capture-time values. Right column:
     the assessment form itself. Save & Close encodes (FR-NRS-04); Preview &
     Print / Reprint post the form into a hidden print iframe (FR-NRS-05).
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

    // ── Vital Signs card (D-65) ──────────────────────────────────────────────
    // Normalize every number to ONE string shape before it is displayed or
    // compared: encoded_vitals stores JSON floats while vital_signs casts to
    // decimal strings, so 165 and "165.0" are the same reading and must not
    // read as a correction.
    $decimal = fn ($value) => $value === null ? null : number_format((float) $value, 1);
    $whole   = fn ($value) => $value === null ? null : (string) (int) $value;

    // The clinic's confirmed copy (read-only mode); pre-D-65 records fall back
    // to the kiosk reading, which is what they always printed.
    $confirmed = $record?->printedVitals($vs) ?? [];

    // What an editable input opens with: a failed submit's own value first,
    // then the kiosk's reading. Respiratory rate has no kiosk counterpart, so
    // it opens empty.
    $vitalValue = fn (string $field) => old($field, $vs?->{$field});

    // The kiosk's screening flag (BR-14) that belongs beside a given input.
    $kioskFlag = fn (string $field) => match ($field) {
        'temperature_c' => (bool) $vs?->is_temp_flagged,
        'bp_systolic', 'bp_diastolic' => (bool) $vs?->is_bp_flagged,
        default => false,
    };

    // Read-only tiles: [label, confirmed value, the kiosk's value, flagged?].
    // Blood pressure reads as one "145/92 mmHg" tile, the way it always has.
    $pair = fn (?string $systolic, ?string $diastolic) => ($systolic === null || $diastolic === null)
        ? null
        : "{$systolic}/{$diastolic} mmHg";
    $unit = fn (?string $value, string $unit) => $value === null ? '—' : trim("{$value} {$unit}");

    $confirmedTiles = [
        ['Height', $unit($decimal($confirmed['height_cm'] ?? null), 'cm'), $unit($decimal($vs?->height_cm), 'cm'), false],
        ['Weight', $unit($decimal($confirmed['weight_kg'] ?? null), 'kg'), $unit($decimal($vs?->weight_kg), 'kg'), false],
        ['BMI', $unit($decimal($confirmed['bmi'] ?? null), ''), $unit($decimal($vs?->bmi), ''), (bool) $vs?->is_bmi_flagged],
        ['Temperature', $unit($decimal($confirmed['temperature_c'] ?? null), '°C'), $unit($decimal($vs?->temperature_c), '°C'), (bool) $vs?->is_temp_flagged],
        ['Blood Pressure',
            $pair($whole($confirmed['bp_systolic'] ?? null), $whole($confirmed['bp_diastolic'] ?? null)) ?? '—',
            $pair($whole($vs?->bp_systolic), $whole($vs?->bp_diastolic)),
            (bool) $vs?->is_bp_flagged],
        ['Heart Rate', $unit($whole($confirmed['heart_rate_bpm'] ?? null), 'bpm'), $unit($whole($vs?->heart_rate_bpm), 'bpm'), false],
        // The kiosk never measures this one, so there is nothing to compare to.
        ['Respiratory Rate', $unit($whole($confirmed['respiratory_rate'] ?? null), 'breaths/min'), null, false],
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
        {{-- D-62: the official form this student's batch uses. --}}
        <x-hp.badge variant="neutral" data-form-type="{{ $visit->formType() }}">
            {{ \App\Models\BatchRequest::FORM_TYPES[$visit->formType()] }}
        </x-hp.badge>
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

{{-- Re-submit flash (FR-NRS-04) — a Save on an already-encoded visit lands
     here with a friendly note instead of a second record. --}}
@if (session('status'))
    <div data-hp-flash class="mb-5 rounded-xl border border-hp-peach bg-hp-peach/30 px-4 py-3 text-sm text-hp-slate">
        {{ session('status') }}
    </div>
@endif

{{-- Read-only notice — encoding is one-time (BR-11 / FR-NRS-04). --}}
@if ($readOnly)
    <div class="mb-5 rounded-xl border border-hp-peach bg-hp-peach/30 px-4 py-3 text-sm text-hp-slate">
        This visit has already been encoded
        @if ($record)
            {{-- D-64: name + role, so a physician's encode reads as theirs. --}}
            by <span class="font-semibold">{{ $record->encoder->name ?? '—' }}</span>@if ($record->encoder) ({{ $record->encoder->roleLabel() }})@endif
            on {{ $record->encoded_at?->format('M j, Y g:i A') ?? '—' }}
        @endif
        — the assessment below is read-only. Use <span class="font-semibold">Reprint</span> for another copy.
    </div>
@endif

{{-- ONE form around both columns (D-65): the Vital Signs card on the left is
     now part of the assessment, so Save & Close and Preview & Print post the
     confirmed vitals together with the result. --}}
<form method="POST" action="{{ route('nurse.visits.encode.store', $visit) }}"
      class="grid items-start gap-5 lg:grid-cols-3">
    @csrf

    {{-- ══ Left column — the visit, and the vitals the clinic confirms ════════ --}}
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

        {{-- ── Vital Signs (D-65) — the clinic confirms the kiosk's reading ───
             Editable: every value is pre-filled from the kiosk and the clinic
             corrects what it re-measured; Respiratory Rate starts empty
             because no kiosk sensor measures it. What is saved goes to
             clearance_records.encoded_vitals — vital_signs keeps the kiosk's
             own reading untouched (BR-14), which is what the flag badges and
             the analytics describe. Read-only: the confirmed copy, with the
             kiosk's value underneath wherever the two differ. --}}
        <x-hp.card>
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-hp-slate">Vital Signs</h3>
                @if ($vs)
                    <span class="text-xs text-hp-slate/40">Kiosk entry: {{ ucfirst($vs->entry_method) }}</span>
                @endif
            </div>

            @if ($readOnly)
                <p class="mt-0.5 text-xs text-hp-slate/50">As confirmed by the clinic when this visit was encoded.</p>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ($confirmedTiles as [$label, $value, $kioskValue, $isFlagged])
                        <div class="rounded-xl bg-hp-bg p-3">
                            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">{{ $label }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="text-lg font-bold {{ $isFlagged ? 'text-hp-orange' : 'text-hp-slate' }}">{{ $value }}</span>
                                @if ($isFlagged)
                                    <x-hp.badge variant="flagged">⚑ Flagged</x-hp.badge>
                                @endif
                            </div>
                            {{-- Only where the clinic corrected the kiosk's number. --}}
                            @if ($kioskValue !== null && $kioskValue !== $value)
                                <p class="mt-1 text-[11px] text-hp-slate/45">Kiosk: {{ $kioskValue }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    Pre-filled from the kiosk — correct anything the clinic re-measured.
                    Respiratory rate is measured here.
                </p>
                {{-- Alpine.js (the app's small reactive JS library) keeps the BMI
                     tile in step with the height and weight boxes as they are
                     typed. Display only: the server recomputes BMI from the
                     posted height and weight and ignores anything sent for
                     it (FR-KSK-09). --}}
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3"
                     x-data="{
                         heightCm: {{ Js::from($vitalValue('height_cm')) }},
                         weightKg: {{ Js::from($vitalValue('weight_kg')) }},
                         get bmi() {
                             const metres = Number(this.heightCm) / 100;
                             const kg = Number(this.weightKg);
                             if (!(metres > 0) || !(kg > 0)) return '—';
                             return (kg / (metres * metres)).toFixed(1);
                         },
                     }">
                    @foreach (\App\Models\ClearanceRecord::ENCODED_VITALS as $field => $vital)
                        @php $bounds = config("healthpass.validation.{$vital['bounds']}"); @endphp
                        <div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <label for="vital-{{ $field }}"
                                       class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                                    {{ $vital['label'] }} <span class="normal-case tracking-normal">({{ $vital['unit'] }})</span>
                                </label>
                                {{-- The kiosk's own screening flag (BR-14) — it
                                     describes the reading below as captured. --}}
                                @if ($kioskFlag($field))
                                    <x-hp.badge variant="flagged">⚑ Flagged</x-hp.badge>
                                @endif
                            </div>
                            <input type="number" id="vital-{{ $field }}" name="{{ $field }}"
                                   value="{{ $vitalValue($field) }}"
                                   step="{{ $vital['step'] }}" min="{{ $bounds['min'] }}" max="{{ $bounds['max'] }}"
                                   inputmode="{{ $vital['step'] === '1' ? 'numeric' : 'decimal' }}"
                                   required
                                   @if ($field === 'height_cm') x-model="heightCm" @endif
                                   @if ($field === 'weight_kg') x-model="weightKg" @endif
                                   class="mt-1 w-full rounded-lg border-[1.5px] border-hp-slate/25 px-3 py-2
                                          text-lg font-bold text-hp-slate transition-colors duration-hp-fast
                                          focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none
                                          @error($field) border-red-400 hp-anim-shake @enderror">
                            @error($field)
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    {{-- BMI is never typed — computed live here, recomputed on the server. --}}
                    <div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">BMI</span>
                            @if ($vs?->is_bmi_flagged)
                                <x-hp.badge variant="flagged">⚑ Flagged</x-hp.badge>
                            @endif
                        </div>
                        <p class="mt-1 rounded-lg bg-hp-bg px-3 py-2 text-lg font-bold text-hp-slate" x-text="bmi">—</p>
                        <p class="mt-1 text-[11px] text-hp-slate/45">From height &amp; weight</p>
                    </div>
                </div>
            @endif

            {{-- D-58: the Bluetooth BP monitor's own irregular-heartbeat
                 indicator, kept from the device reading. Shown to the clinic
                 only — the kiosk never displays it. --}}
            @if ($vs?->hasIrregularPulse())
                <p class="mt-3 flex flex-wrap items-center gap-2 text-sm text-hp-slate">
                    <x-hp.badge variant="flagged">⚑ Irregular pulse</x-hp.badge>
                    Detected by the blood-pressure monitor during this reading.
                </p>
            @endif

            @unless ($vs)
                <p class="mt-3 text-sm text-hp-slate/40">The kiosk recorded no vitals for this visit — enter them here.</p>
            @endunless
        </x-hp.card>

        {{-- ── Questionnaire — the form's twelve rows (D-63) + pregnancy/LMP (FR-KSK-10) ── --}}
        <x-hp.card>
            <h3 class="text-sm font-semibold text-hp-slate">Health Questionnaire</h3>
            <p class="mt-0.5 text-xs text-hp-slate/50">The student's own answers at the kiosk, with any details they typed.</p>
            @if ($sr)
                <div class="mt-2 grid gap-x-8 sm:grid-cols-2">
                    @foreach (\App\Models\ScreeningResponse::QUESTIONS as $key => $question)
                        {{-- Block form on purpose: this view also has PHP
                             blocks, and Blade pairs the one-line form with the
                             NEXT block's closing tag, swallowing the markup
                             in between. --}}
                        @php $answer = $sr->{$key}; @endphp
                        <div class="border-b border-hp-slate/10 py-2">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm text-hp-slate/70">{{ $question['label'] }}</span>
                                @if ($answer === null)
                                    <span class="text-xs text-hp-slate/40">—</span>
                                @else
                                    {{-- Kiosk colour language: orange = reported issue, green = all clear. --}}
                                    <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold
                                                 {{ $answer ? 'bg-hp-orange/15 text-hp-orange' : 'bg-emerald-50 text-emerald-600' }}">
                                        {{ $answer ? 'Yes' : 'No' }}
                                    </span>
                                @endif
                            </div>
                            {{-- The student's typed detail, under its YES (D-56). --}}
                            @if ($answer && $sr->detailFor($key) !== null)
                                <p class="mt-1 text-xs text-hp-slate/60">{{ $sr->detailFor($key) }}</p>
                            @endif
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
            Result is required; notes are optional. The purpose comes from the batch (BR-16).
        </p>

        {{-- Save & Close POSTs the assessment (FR-NRS-04). Fields re-populate
             from old() after a validation failure, falling back to the saved
             record in read-only mode. --}}
        <div class="mt-5 space-y-5">
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
                               @checked(old('result', $record?->result) === 'Fit') @disabled($readOnly)>
                        <span class="flex items-center justify-center rounded-xl border-2 border-hp-slate/20
                                     py-3 text-base font-bold text-hp-slate/50 transition-colors
                                     peer-checked:border-emerald-500 peer-checked:bg-emerald-500 peer-checked:text-white
                                     peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500 peer-focus-visible:ring-offset-2">
                            Fit
                        </span>
                    </label>
                    <label class="{{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                        <input type="radio" name="result" value="Unfit" class="peer sr-only"
                               @checked(old('result', $record?->result) === 'Unfit') @disabled($readOnly)>
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

            {{-- Purpose (D-62) — the College Admin's batch reason IS the
                 printed purpose, so the nurse no longer picks one. Shown
                 read-only: the saved record's copy once encoded, otherwise the
                 batch's, which Save & Close copies onto the record. --}}
            @php
                $purpose = $record
                    ? ['purpose' => $record->purpose, 'purpose_other' => $record->purpose_other]
                    : $visit->batchPurpose();
            @endphp
            <div class="space-y-1">
                <span class="text-sm font-semibold text-hp-slate">Purpose</span>
                @if ($purpose['purpose'])
                    <p class="text-sm text-hp-slate/80" data-purpose>
                        {{ $purpose['purpose'] }}@if ($purpose['purpose_other']): {{ $purpose['purpose_other'] }}@endif
                    </p>
                    <p class="text-xs text-hp-slate/45">From the college's batch request — printed on the form.</p>
                @else
                    <p class="text-sm text-hp-slate/40" data-purpose>Not specified</p>
                @endif
            </div>

            {{-- Physical Signs Disorder of (D-22): the physician examines the
                 student at the clinic; the nurse records the findings here.
                 Each row is optional — unanswered rows print as blank bubbles
                 on the official form (FR-PRT-02). --}}
            <div>
                <span class="text-sm font-semibold text-hp-slate">Physical Signs Disorder of</span>
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    Physician's exam findings — leave a row unanswered to keep it blank on the printed form.
                </p>
                <div class="mt-1.5 divide-y divide-hp-slate/10">
                    @foreach (\App\Models\ClearanceRecord::PHYSICAL_SIGNS as $column => $label)
                        @php
                            // old() posts back '1'/'0' strings; the saved record
                            // gives booleans — normalize both to '1'/'0'/null.
                            $saved = old($column, is_null($record?->{$column}) ? null : (string) (int) $record->{$column});

                            // Fresh form only: pre-check the row with the
                            // student's own kiosk answer — YES or NO — for all
                            // twelve rows (D-22/D-25 as amended by D-56/D-63).
                            // The kiosk asks the form's rows, so ps_<key> ← <key>.
                            // A NULL answer leaves the row blank.
                            if ($saved === null && ! $readOnly && $sr) {
                                $kioskAnswer = $sr->{Str::after($column, 'ps_')};
                                if ($kioskAnswer !== null) {
                                    $saved = $kioskAnswer ? '1' : '0';
                                }
                            }
                        @endphp
                        <div class="flex items-center justify-between gap-3 py-1.5">
                            <span class="text-sm text-hp-slate/70">{{ $label }}</span>
                            <span class="flex items-center gap-3 text-sm text-hp-slate/70">
                                <label class="flex items-center gap-1 {{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                                    <input type="radio" name="{{ $column }}" value="1" class="accent-hp-orange"
                                           @checked($saved === '1') @disabled($readOnly)>
                                    Yes
                                </label>
                                <label class="flex items-center gap-1 {{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                                    <input type="radio" name="{{ $column }}" value="0" class="accent-hp-orange"
                                           @checked($saved === '0') @disabled($readOnly)>
                                    No
                                </label>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Clinic Notes (D-64 label; the column stays nurse_notes) print
                 under REMARKS (FR-PRT-02). A visit not yet
                 encoded opens them pre-filled with the student's YES details,
                 one "SKIN: …" line each in the form's order — the form says "If
                 YES, give details under Remarks" (D-56). old() input wins and
                 the nurse edits freely; a read-only record shows its saved notes only. --}}
            @php
                $notes = $readOnly ? $record?->nurse_notes : ($sr?->detailsAsNotes() ?: null);
            @endphp
            <x-hp.textarea label="Clinic Notes" name="nurse_notes" rows="4" :disabled="$readOnly"
                           placeholder="Observations, advice given, follow-ups…">{{ old('nurse_notes', $notes) }}</x-hp.textarea>

            <div class="flex flex-col gap-2.5 pt-1">
                @if ($readOnly)
                    {{-- Reprint (FR-NRS-05): formaction re-routes this submit to
                         the reprint endpoint, which re-stamps printed_at and
                         returns the official form INTO the hidden iframe below
                         (formtarget); the script then prints the iframe. --}}
                    <x-hp.button type="submit" variant="soft" class="w-full"
                                 data-print-trigger
                                 formaction="{{ route('nurse.visits.print.reprint', $visit) }}"
                                 formtarget="hp-print-frame">
                        Reprint
                    </x-hp.button>
                @else
                    {{-- Flipped to 1 once Preview & Print fires, so Save & Close
                         can stamp printed_at (FR-NRS-05) — the clearance row
                         doesn't exist yet at pre-save print time. --}}
                    <input type="hidden" name="printed" id="hp-printed-flag" value="{{ old('printed', '0') }}">

                    {{-- Preview & Print (FR-NRS-05): posts the CURRENT (unsaved)
                         assessment to the preview route, targeted at the hidden
                         iframe — Chrome's print dialog is the preview. The
                         required Result radio gates this submit too. --}}
                    <x-hp.button type="submit" variant="ghost" class="w-full"
                                 data-print-trigger
                                 formaction="{{ route('nurse.visits.print.preview', $visit) }}"
                                 formtarget="hp-print-frame">
                        Preview &amp; Print
                    </x-hp.button>
                    {{-- data-pending-label: spinner + disable while the save
                         navigates (§5.6) — belt-and-braces double-submit
                         protection on top of the controller's one-shot guard.
                         The print buttons above are exempt automatically:
                         their formtarget posts into the iframe, and
                         page-motion.js keys off the SUBMITTER's target. --}}
                    <x-hp.button type="submit" variant="primary" class="w-full"
                                 data-pending-label="Saving…">Save &amp; Close</x-hp.button>
                @endif
            </div>
        </div>
    </x-hp.card>

</form>

{{-- Hidden print frame (FR-NRS-05): both print buttons post the official form
     in here. 0×0 instead of display:none — Chrome won't reliably print an
     unrendered frame. --}}
<iframe name="hp-print-frame" id="hp-print-frame" title="Clearance print preview"
        style="position: fixed; right: 0; bottom: 0; width: 0; height: 0; border: 0;"></iframe>

@push('scripts')
<script>
    // FR-NRS-05 — when the official form finishes loading in the hidden
    // iframe, print the iframe's window (NOT this page). `armed` skips the
    // iframe's initial about:blank load; the data-hp-print-doc marker makes
    // sure we only ever print the clearance form — never, say, a validation
    // redirect that landed this page back inside the iframe.
    (function () {
        const frame = document.getElementById('hp-print-frame');
        const printedFlag = document.getElementById('hp-printed-flag');
        let armed = false;

        document.querySelectorAll('[data-print-trigger]').forEach((btn) => {
            btn.addEventListener('click', () => { armed = true; });
        });

        frame.addEventListener('load', () => {
            if (!armed) return;
            armed = false;

            const doc = frame.contentDocument;
            if (!doc || !doc.body || !doc.body.hasAttribute('data-hp-print-doc')) return;

            if (printedFlag) printedFlag.value = '1';
            frame.contentWindow.focus();
            frame.contentWindow.print();
        });
    })();
</script>
@endpush

</x-layout.sidebar>
