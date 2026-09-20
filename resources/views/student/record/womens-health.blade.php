{{-- ── V. Menstrual History + VI. OB/Pregnancy History (D-70) ──────────────────
     Female students only — the paper leaves both sections empty for anyone
     else, so the parent view does not include this partial at all. Sex is read
     from the profile on the SERVER (ClinicVisit::studentIsFemale()).

     Every box is optional; one never filled in shows a dash.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $sections  = $visit->clearanceRecord?->medicalAssessment;
    $menstrual = $sections?->menstrual_history ?? [];
    $ob        = $sections?->ob_history ?? [];

    // The paper's own labels and units, in its order.
    $menstrualRows = [
        'menarche_age' => ['Menarche', 'yrs old'],
        'first_intercourse_age' => ['Onset of sexual intercourse', 'yrs old'],
        'lmp' => ['Last Menstrual Period', ''],
        'period_days' => ['Period Duration', 'days'],
        'pads_per_day' => ['No. of Pads per Day', ''],
        'cycle_days' => ['Interval Cycle', 'days'],
        'contraceptive' => ['Contraceptive Method Used', ''],
        'menopause_age' => ['Menopause Age', 'yrs'],
    ];

    // "Gravida: __ Para: __ T: __ P: __ A: __ L: __" — the paper's line.
    $obCounts = [
        'gravida' => 'Gravida',
        'para' => 'Para',
        'term' => 'T',
        'preterm' => 'P',
        'abortion' => 'A',
        'living' => 'L',
    ];

    // A stored value as text: an LMP is a date, everything else prints as-is.
    $value = function (array $values, string $key) {
        $stored = $values[$key] ?? null;

        if (blank($stored)) {
            return '—';
        }

        return $key === 'lmp' ? date('F j, Y', (int) strtotime((string) $stored)) : (string) $stored;
    };
@endphp

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">V. Menstrual History</h3>

    <dl class="mt-3 grid gap-x-8 sm:grid-cols-2">
        @foreach ($menstrualRows as $key => [$label, $unit])
            @php
                $text = $value($menstrual, $key);
            @endphp
            <div class="flex items-start justify-between gap-3 border-b border-hp-slate/10 py-2">
                <dt class="text-sm text-hp-slate/70">{{ $label }}</dt>
                <dd class="shrink-0 text-right text-sm text-hp-slate">
                    {{ $text === '—' ? $text : trim($text.' '.$unit) }}
                </dd>
            </div>
        @endforeach
        <div class="flex items-center justify-between gap-3 border-b border-hp-slate/10 py-2">
            <dt class="text-sm text-hp-slate/70">Menopause</dt>
            <dd class="shrink-0"><x-hp.answer :answer="$menstrual['menopause'] ?? null" /></dd>
        </div>
    </dl>
</x-hp.card>

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">VI. OB/Pregnancy History</h3>

    <div class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-6">
        @foreach ($obCounts as $key => $label)
            <div class="rounded-xl bg-hp-bg p-3 text-center">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">{{ $label }}</p>
                <p class="mt-1 text-lg font-bold text-hp-slate">{{ $value($ob, $key) }}</p>
            </div>
        @endforeach
    </div>

    <dl class="mt-3 grid gap-x-8 sm:grid-cols-2">
        <div class="flex items-start justify-between gap-3 border-b border-hp-slate/10 py-2">
            <dt class="text-sm text-hp-slate/70">Type of Delivery</dt>
            <dd class="min-w-0 text-right text-sm text-hp-slate">{{ $value($ob, 'delivery_type') }}</dd>
        </div>
        <div class="flex items-center justify-between gap-3 border-b border-hp-slate/10 py-2">
            <dt class="text-sm text-hp-slate/70">Pregnancy Induced Hypertension</dt>
            <dd class="shrink-0"><x-hp.answer :answer="$ob['pih'] ?? null" /></dd>
        </div>
    </dl>
</x-hp.card>
