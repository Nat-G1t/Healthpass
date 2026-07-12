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

    // Kiosk answers pre-check the matching physical-sign row (D-22 as
    // amended by D-25): YES *and* NO both pre-fill — the student already
    // answered, so the row opens on their answer for the nurse to confirm
    // or correct after the exam. Column → questionnaire key; GUT and BREAST
    // have no kiosk counterpart (they stay unanswered → blank bubbles), and
    // vision/hearing exist to help the nurse pick the "Eyes, Ears, Nose &
    // Throat Disorders" case category (D-23), not to pre-fill a row.
    $kioskPrefill = [
        'ps_skin'         => 'skin',
        'ps_abdomen_git'  => 'digestive',
        'ps_heent'        => 'nose',
        'ps_chest_lungs'  => 'respiratory',
        'ps_extremities'  => 'bones',
        'ps_heart_cvs'    => 'heart',
        'ps_neurological' => 'nervous',
    ];

    // Saved categories for the multi-select (D-23), old() first after a
    // validation bounce, then the saved child rows in read-only mode.
    $savedCategories = old('case_categories', $record?->categoryNames() ?? []);

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

{{-- Re-submit flash (FR-NRS-04) — a Save on an already-encoded visit lands
     here with a friendly note instead of a second record. --}}
@if (session('status'))
    <div class="mb-5 rounded-xl border border-hp-peach bg-hp-peach/30 px-4 py-3 text-sm text-hp-slate">
        {{ session('status') }}
    </div>
@endif

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

        {{-- Save & Close POSTs the assessment (FR-NRS-04). Fields re-populate
             from old() after a validation failure, falling back to the saved
             record in read-only mode. --}}
        <form method="POST" action="{{ route('nurse.visits.encode.store', $visit) }}" class="mt-5 space-y-5">
            @csrf

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

            {{-- Medical Case Categories — multi-select (D-23): a case can span
                 several systems; each checked category counts once in the
                 Director's cases-per-category analytics. The kiosk's
                 vision/hearing answers (left column) are decision support for
                 the "Eyes, Ears, Nose & Throat Disorders" pick. --}}
            <div>
                <span class="text-sm font-semibold text-hp-slate">Medical Case Categories</span>
                <p class="mt-0.5 text-xs text-hp-slate/50">Optional — tick every system the case involves.</p>
                <div class="mt-1.5 space-y-1">
                    @foreach (\App\Models\ClearanceRecord::CASE_CATEGORIES as $category)
                        <label class="flex items-center gap-2 {{ $readOnly ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                            <input type="checkbox" name="case_categories[]" value="{{ $category }}"
                                   class="accent-hp-orange"
                                   @checked(in_array($category, $savedCategories, true)) @disabled($readOnly)>
                            <span class="text-sm text-hp-slate/70">{{ $category }}</span>
                        </label>
                    @endforeach
                </div>
                @error('case_categories.*')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Purpose — the four locked values plus the form's "Others,
                 Specify" line. Alpine mirrors the select into `purpose` so the
                 specify input only shows (and only submits meaningfully) when
                 Others is picked; the Form Request drops stray text otherwise. --}}
            @php $savedPurpose = old('purpose', $record?->purpose); @endphp
            <div x-data="{ purpose: @js($savedPurpose ?? '') }" class="space-y-2">
                <x-hp.select label="Purpose" name="purpose" x-model="purpose" :disabled="$readOnly">
                    <option value="">— Optional —</option>
                    @foreach (\App\Models\ClearanceRecord::PURPOSES as $purpose)
                        <option value="{{ $purpose }}" @selected($savedPurpose === $purpose)>{{ $purpose }}</option>
                    @endforeach
                    <option value="{{ \App\Models\ClearanceRecord::PURPOSE_OTHERS }}"
                            @selected($savedPurpose === \App\Models\ClearanceRecord::PURPOSE_OTHERS)>
                        Others, Specify…
                    </option>
                </x-hp.select>
                <div x-show="purpose === @js(\App\Models\ClearanceRecord::PURPOSE_OTHERS)" x-cloak>
                    <x-hp.input name="purpose_other" maxlength="120" :disabled="$readOnly"
                                placeholder="Specify the event, e.g. Regional quiz bee at PSU Lubao"
                                value="{{ old('purpose_other', $record?->purpose_other) }}" />
                    @error('purpose_other')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
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
                            // student's kiosk answer — YES or NO (D-22/D-25,
                            // see $kioskPrefill). Unmapped rows stay blank.
                            if ($saved === null && ! $readOnly) {
                                $kioskKey = $kioskPrefill[$column] ?? null;
                                if ($kioskKey && $sr) {
                                    $saved = $sr->{$kioskKey} ? '1' : '0';
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

            <x-hp.textarea label="Nurse Notes" name="nurse_notes" rows="4" :disabled="$readOnly"
                           placeholder="Observations, advice given, follow-ups…">{{ old('nurse_notes', $record?->nurse_notes) }}</x-hp.textarea>

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
                    <x-hp.button type="submit" variant="primary" class="w-full">Save &amp; Close</x-hp.button>
                @endif
            </div>
        </form>
    </x-hp.card>

</div>

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
