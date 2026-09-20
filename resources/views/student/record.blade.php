<x-layout.sidebar title="Clinic Record">

{{-- ── One clinic visit's full record (FR-STU-07, D-73) ─────────────────────────
     A read-only page, not the old modal: everything the clinic encoded for
     this visit, in the order the official document lays it out, plus Save as
     PDF (FR-STU-15) — the SAME document, as one file.

     Only an ENCODED visit reaches this view (FR-STU-08); the controller 404s
     anything else, and anything belonging to another student.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $record  = $visit->clearanceRecord;
    $sr      = $visit->screeningResponse;
    $profile = $visit->student?->studentProfile;

    // D-62: the form type is the SERVER's, read from the batch behind the
    // appointment — it decides which sections below exist at all.
    $isAssessment = $visit->formType() === 'assessment';
    $formLabel    = \App\Models\BatchRequest::FORM_TYPES[$visit->formType()];

    // D-69/D-70: the Medical Assessment Form's own sections, NULL on a
    // Medical Clearance record, which has none of them.
    $sections = $record?->medicalAssessment;

    // D-70: sections V and VI are the female student's; the paper leaves them
    // empty for anyone else, so the page does not render them at all.
    $isFemale = $visit->studentIsFemale();

    $visitDate = ($visit->checked_in_at ?? $visit->created_at)?->format('F j, Y');

    // The batch reason IS the printed purpose (D-62), with the College Admin's
    // specify text after it when the reason was "Others".
    $purpose = $record?->purpose;
    if ($purpose !== null && filled($record?->purpose_other)) {
        $purpose .= ' — '.$record->purpose_other;
    }
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <a href="{{ route('student.records') }}"
       class="text-sm font-medium text-hp-slate/60 transition-colors hover:text-hp-orange">
        ← Back to My Records
    </a>
    <div class="mt-2 flex flex-wrap items-center gap-2.5">
        <h2 class="text-xl font-semibold text-hp-slate">Clinic Record</h2>
        <x-hp.badge variant="neutral" data-form-type="{{ $visit->formType() }}">{{ $formLabel }}</x-hp.badge>
    </div>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        <span class="font-mono">{{ $visit->reference_no }}</span> · {{ $visitDate ?? '—' }}
    </p>
</div>

<div class="space-y-5">

    {{-- ── Result + the document ───────────────────────────────────────────── --}}
    <x-hp.card>
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 space-y-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Result</p>
                    {{-- FR-STU-08: visible here and nowhere before encoding —
                         never on the kiosk. --}}
                    <p class="mt-1 text-2xl font-bold {{ $record->result === 'Fit' ? 'text-emerald-600' : 'text-hp-orange' }}">
                        {{ $record->result }}
                    </p>
                </div>

                <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Purpose</dt>
                        <dd class="mt-0.5 text-hp-slate">{{ $purpose ?? '—' }}</dd>
                    </div>
                    <div>
                        {{-- D-64: name AND role, so a physician's encode reads as theirs. --}}
                        <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Encoded by</dt>
                        <dd class="mt-0.5 text-hp-slate">
                            {{ $record->encoder?->name ?? '—' }}@if ($record->encoder) ({{ $record->encoder->roleLabel() }})@endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Encoded on</dt>
                        <dd class="mt-0.5 text-hp-slate">{{ $record->encoded_at?->format('F j, Y g:i A') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Student</dt>
                        <dd class="mt-0.5 text-hp-slate">
                            {{ $visit->student?->name ?? '—' }}
                            <span class="block font-mono text-xs text-hp-slate/45">{{ $profile?->student_number ?? '—' }}</span>
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- FR-STU-15 — Save as PDF: a plain GET link, because the response
                 is a downloaded file and this page never navigates. ONE file:
                 the Medical Assessment Form's two Legal pages come down
                 together, for a print shop to run back-to-back (D-71). --}}
            <div class="shrink-0 sm:text-right">
                <a href="{{ route('student.records.pdf', $visit) }}"
                   class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-hp-orange
                          px-6 py-2.5 text-sm font-semibold text-white transition-colors duration-hp-fast
                          hover:bg-orange-500 focus-visible:outline-none focus-visible:ring-2
                          focus-visible:ring-hp-orange focus-visible:ring-offset-1 sm:w-auto">
                    Save as PDF
                </a>
                <p class="mt-2 text-xs text-hp-slate/50 sm:max-w-[15rem]">
                    @if ($isAssessment)
                        Both pages in one file — ask a print shop for long bond (US Legal), back-to-back.
                    @else
                        One page — US Letter.
                    @endif
                </p>
            </div>
        </div>
    </x-hp.card>

    @include('student.record.vitals')
    @include('student.record.physical-signs')

    {{-- ── Clinic notes ────────────────────────────────────────────────────── --}}
    <x-hp.card>
        <h3 class="text-sm font-semibold text-hp-slate">Clinic Notes</h3>
        <p class="mt-0.5 text-xs text-hp-slate/50">What the clinic wrote under REMARKS on your document.</p>
        @if (filled($record->nurse_notes))
            <p class="mt-3 whitespace-pre-line text-sm text-hp-slate/70">{{ $record->nurse_notes }}</p>
        @else
            <p class="mt-3 text-sm text-hp-slate/40">No notes were recorded for this visit.</p>
        @endif
    </x-hp.card>

    {{-- ── The Medical Assessment Form's own sections (D-69/D-70) ───────────
         In the paper's order, Assessment visits only — a Medical Clearance
         has none of them. Each is a partial of its own; this page is long
         enough without them inline. --}}
    @if ($isAssessment)
        @include('student.record.social-history')
        @include('student.record.medical-history')
        @include('student.record.immunizations')
        @include('student.record.surgical-history')
        @if ($isFemale)
            @include('student.record.womens-health')
        @endif
        @include('student.record.physical-exam')
    @endif

</div>

</x-layout.sidebar>
