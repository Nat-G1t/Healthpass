{{-- ── Past Surgical History / Procedures (D-69) ───────────────────────────────
     The back page's last pair of lines. "Date Done" is free text on the paper
     too — "2019" or "Grade 5" is a normal answer.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $surgical = $visit->clearanceRecord?->medicalAssessment?->surgical_history ?? [];
@endphp

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">Past Surgical History / Procedures</h3>

    <dl class="mt-3 grid gap-x-8 sm:grid-cols-2">
        <div class="flex items-start justify-between gap-3 border-b border-hp-slate/10 py-2">
            <dt class="text-sm text-hp-slate/70">Procedures</dt>
            <dd class="min-w-0 text-right text-sm text-hp-slate">{{ $surgical['procedures'] ?? null ?: '—' }}</dd>
        </div>
        <div class="flex items-start justify-between gap-3 border-b border-hp-slate/10 py-2">
            <dt class="text-sm text-hp-slate/70">Date Done</dt>
            <dd class="min-w-0 text-right text-sm text-hp-slate">{{ $surgical['date_done'] ?? null ?: '—' }}</dd>
        </div>
    </dl>
</x-hp.card>
