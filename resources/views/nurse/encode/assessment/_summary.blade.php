{{-- ── The nurse's interview, as the physician reads it (D-127) ─────────────────
     Shown to the physician on an INTERVIEWED Medical Assessment visit: a
     compact, read-only summary of what the nurse entered — only what was
     answered or ticked; a section with nothing in it reads "—". Every label
     comes from the MedicalAssessment / ClearanceRecord constants the editable
     fields and the print use, so the three cannot drift apart.

     "Edit interview" swaps this card for the editable fields (the Vital Signs
     card and the section partials), pre-filled. They are in the form all
     along, only hidden — so Save without Edit posts the nurse's own values
     unchanged, and the edits post together with Fit / Unfit.
     Variables from the parent view: $assessment, $interviewVitals, $isFemale,
     $editOpen (Alpine's `editing` lives on the parent <form>).
──────────────────────────────────────────────────────────────────────────── --}}
@php
    $join = fn (array $parts): string => $parts === [] ? '—' : implode(' · ', $parts);
    $filled = fn ($value): bool => $value !== null && $value !== '';

    // ── Vital signs: the values the nurse confirmed at the interview ─────────
    $vitalParts = [];
    foreach (\App\Models\ClearanceRecord::ENCODED_VITALS as $field => $vital) {
        $value = $interviewVitals[$field] ?? null;
        if ($value === null) {
            continue;
        }
        $number = $vital['step'] === '0.1' ? number_format((float) $value, 1) : (string) (int) $value;
        $vitalParts[] = "{$vital['label']} {$number} {$vital['unit']}";
        // BMI (recomputed server-side at Submit) reads best beside the weight.
        if ($field === 'weight_kg' && isset($interviewVitals['bmi'])) {
            $vitalParts[] = 'BMI '.number_format((float) $interviewVitals['bmi'], 1);
        }
    }

    // ── Past Medical & Family History: "Patient: Asthma · Family: …" ────────
    // A row with a blank line on the paper shows its note: "Hypertension
    // (Highest BP: 150/95)".
    $conditionName = function (string $key) use ($assessment): string {
        $condition = \App\Models\MedicalAssessment::CONDITIONS[$key];
        if ($condition['specify'] === null) {
            return $condition['label'];
        }
        $name = \Illuminate\Support\Str::before($condition['label'], ' (');
        $note = $assessment->specifyFor($key);

        return $note === null ? $name : "{$name} ({$condition['specify']}: {$note})";
    };
    $historyParts = [];
    foreach (array_keys(\App\Models\MedicalAssessment::HISTORY_COLUMNS) as $column) {
        $names = collect(\App\Models\MedicalAssessment::conditionKeys())
            ->filter(fn (string $key): bool => $assessment->hasCondition($column, $key))
            ->map($conditionName);
        if ($names->isNotEmpty()) {
            $historyParts[] = ucfirst($column).': '.$names->implode(', ');
        }
    }

    // ── II. Immunization Profile. The two "None" boxes mean different things,
    // so each says which group it belongs to. ─────────────────────────────────
    $vaccines = [];
    foreach (\App\Models\MedicalAssessment::IMMUNIZATIONS as $group => $list) {
        foreach ($list as $key => $label) {
            if ($assessment->hasImmunization($key)) {
                $vaccines[] = $label === 'None' ? "None ({$group})" : $label;
            }
        }
    }
    $immunizationParts = $vaccines === [] ? [] : [implode(', ', $vaccines)];
    if ($filled($assessment->immunizations['others'] ?? null)) {
        $immunizationParts[] = 'Others: '.$assessment->immunizations['others'];
    }

    // ── III. Family Planning Access — NULL is "not answered", not "No". ─────
    $familyPlanning = match ($assessment->family_planning_access) {
        true => 'Yes',
        false => 'No',
        default => '—',
    };

    // ── Past Surgical History: "Appendectomy (2019)" ────────────────────────
    $surgery = $assessment->surgical_history ?? [];
    $surgicalParts = [];
    if ($filled($surgery['procedures'] ?? null)) {
        $surgicalParts[] = $surgery['procedures']
            .($filled($surgery['date_done'] ?? null) ? " ({$surgery['date_done']})" : '');
    } elseif ($filled($surgery['date_done'] ?? null)) {
        $surgicalParts[] = "Date done: {$surgery['date_done']}";
    }

    $yesNo = fn ($answer): ?string => $answer === null ? null : ((bool) $answer ? 'Yes' : 'No');

    // ── V. Menstrual History and VI. OB/Pregnancy History (female only) ─────
    $menstrual = $assessment->menstrual_history ?? [];
    $menstrualParts = [];
    foreach (\App\Models\MedicalAssessment::MENSTRUAL_LABELS as $key => [$label, $unit]) {
        $value = $menstrual[$key] ?? null;
        if (! $filled($value)) {
            continue;
        }
        if ($key === 'lmp') {
            $value = \Illuminate\Support\Carbon::parse($value)->format('M j, Y');
        }
        $menstrualParts[] = trim("{$label}: {$value} {$unit}");
    }
    if ($yesNo($menstrual['menopause'] ?? null) !== null) {
        $menstrualParts[] = 'Menopause: '.$yesNo($menstrual['menopause']);
    }

    $ob = $assessment->ob_history ?? [];
    $obParts = [];
    foreach (\App\Models\MedicalAssessment::OB_LABELS as $key => $label) {
        if ($filled($ob[$key] ?? null)) {
            $obParts[] = "{$label} {$ob[$key]}";
        }
    }
    if ($filled($ob['delivery_type'] ?? null)) {
        $obParts[] = 'Type of Delivery: '.$ob['delivery_type'];
    }
    if ($yesNo($ob['pih'] ?? null) !== null) {
        $obParts[] = 'Pregnancy Induced Hypertension: '.$yesNo($ob['pih']);
    }

    $rows = [
        'Vital signs' => $join($vitalParts),
        'Medical history' => $join($historyParts),
        'Immunizations' => $join($immunizationParts),
        'Family planning access' => $familyPlanning,
        'Surgical history' => $join($surgicalParts),
    ];
    if ($isFemale) {
        $rows['Menstrual history'] = $join($menstrualParts);
        $rows['OB/Pregnancy history'] = $join($obParts);
    }

    // ── Pertinent Physical Examination: one line per group with something ───
    $examLines = [];
    foreach (\App\Models\MedicalAssessment::PHYSICAL_EXAM as $group => $section) {
        $findings = collect($section['findings'])
            ->filter(fn (string $label, string $key): bool => $assessment->hasFinding($group, $key))
            ->values()
            ->all();
        $parts = $findings === [] ? [] : [implode(', ', $findings)];
        if ($filled($assessment->findingOthers($group))) {
            $parts[] = 'Others: '.$assessment->findingOthers($group);
        }
        if ($parts !== []) {
            $examLines[$section['label']] = $join($parts);
        }
    }
@endphp

<div x-show="! editing" @if ($editOpen) x-cloak @endif data-interview-summary>
    <x-hp.card>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold text-hp-slate">Nurse's interview</h3>
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    What the nurse entered — only what was answered or ticked.
                    Edit it if something needs correcting; your changes save with Fit / Unfit.
                </p>
            </div>
            <x-hp.button variant="ghost" size="sm" @click="editing = true" data-edit-interview>
                Edit interview
            </x-hp.button>
        </div>

        <dl class="mt-3 divide-y divide-hp-slate/10 text-sm">
            @foreach ($rows as $label => $text)
                <div class="grid gap-1 py-2 sm:grid-cols-[11rem_1fr] sm:gap-4">
                    <dt class="text-hp-slate/50">{{ $label }}</dt>
                    <dd class="{{ $text === '—' ? 'text-hp-slate/40' : 'text-hp-slate' }}">{{ $text }}</dd>
                </div>
            @endforeach
            <div class="grid gap-1 py-2 sm:grid-cols-[11rem_1fr] sm:gap-4">
                <dt class="text-hp-slate/50">Physical examination</dt>
                <dd>
                    @forelse ($examLines as $group => $text)
                        <p class="text-hp-slate">
                            <span class="font-semibold">{{ $group }}:</span> {{ $text }}
                        </p>
                    @empty
                        <span class="text-hp-slate/40">—</span>
                    @endforelse
                </dd>
            </div>
        </dl>
    </x-hp.card>
</div>
