<x-layout.sidebar title="Manual Screening">

{{--
    Manual Screening — one student's form (D-133). The nurse's only.

    Everything the kiosk collects (Q11) — consent, the six vitals, the twelve
    Physical Signs rows, pregnancy for a female student and, on a Medical
    Assessment, the Personal / Social History — plus, on a Medical Assessment,
    the nurse's interview (the 06 partials + respiratory rate). One submit at
    the bottom sends the student to the physician's queue.

    Which cards show is the batch's form type (D-62), resolved on the server
    from the appointment; nothing on the page names the student or the form,
    and the server re-reads both from the route-bound appointment.
    Fit / Unfit never appears here — only the physician decides it.
--}}
@php
    $isAssessment = $formType === 'assessment';
    $schedule = app(\App\Services\ClinicScheduleService::class);
    $sexLabel = match ($profile?->sex) { 'M' => 'Male', 'F' => 'Female', default => '—' };

    // ── What the interview partials (nurse/encode/assessment/*) read ──────────
    // On the encode page these also fall back to a saved row; here there is
    // never one, so every box opens with a failed submit's own input or empty.
    $readOnly = false;
    $conditionChecked = fn (string $column, string $key) => in_array($key, (array) old("medical_history.{$column}", []), true);
    $specifyValue = fn (string $key) => old("medical_history.specify.{$key}");
    $immunizationChecked = fn (string $key) => in_array($key, (array) old('immunizations.given', []), true);
    $immunizationOthers = fn () => old('immunizations.others');
    $familyPlanning = fn () => old('family_planning_access');
    $surgical = fn (string $key) => old("surgical_history.{$key}");
    $menstrual = fn (string $key) => old("menstrual_history.{$key}");
    $ob = fn (string $key) => old("ob_history.{$key}");
    $yesNo = fn (string $section, string $key) => old("{$section}.{$key}");
    $finding = fn (string $group, string $key) => in_array($key, (array) old("physical_exam.{$group}.findings", []), true);
    $findingOthers = fn (string $group) => old("physical_exam.{$group}.others");
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <a href="{{ route('nurse.manual.index') }}"
       class="text-sm font-medium text-hp-slate/60 transition-colors hover:text-hp-orange">
        ← Back to search
    </a>
    <div class="mt-2 flex flex-wrap items-center gap-2.5">
        <h2 class="text-xl font-semibold text-hp-slate">Manual Screening</h2>
        <x-hp.badge variant="neutral" data-form-type="{{ $formType }}">
            {{ \App\Models\BatchRequest::FORM_TYPES[$formType] }}
        </x-hp.badge>
    </div>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Enter what the kiosk would have collected{{ $isAssessment ? ', then the interview' : '' }}.
        The student then waits in the physician’s queue.
    </p>
</div>

<form method="POST" action="{{ route('nurse.manual.store', $appointment) }}" class="max-w-4xl space-y-5">
    @csrf

    {{-- ── Student ─────────────────────────────────────────────────────── --}}
    <x-hp.card>
        <h3 class="text-sm font-bold text-hp-slate">Student</h3>
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Name</dt>
                <dd class="mt-0.5 font-semibold text-hp-slate">{{ $student->name }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Student ID</dt>
                <dd class="mt-0.5 font-mono text-hp-slate">{{ $profile?->student_number ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">College</dt>
                <dd class="mt-0.5 text-hp-slate">{{ $profile?->college?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Sex</dt>
                <dd class="mt-0.5 text-hp-slate">{{ $sexLabel }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Batch ID</dt>
                <dd class="mt-0.5 text-hp-slate">{{ $appointment->batchRequest?->reference_no ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Hour</dt>
                <dd class="mt-0.5 text-hp-slate">
                    {{ $appointment->scheduled_time ? $schedule->label($appointment->scheduled_time) : '—' }}
                    @if ($appointment->arrivalTimeLabel())
                        <span class="block text-xs text-hp-slate/50">Arrival {{ $appointment->arrivalTimeLabel() }}</span>
                    @endif
                </dd>
            </div>
        </dl>
    </x-hp.card>

    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            <p class="font-semibold">Some answers need another look:</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Data privacy (FR-KSK-04) — the kiosk's consent screen ──────────── --}}
    <x-hp.card>
        <h3 class="text-sm font-bold text-hp-slate">Data Privacy</h3>
        <p class="mt-0.5 text-xs text-hp-slate/50">Republic Act No. 10173 — Data Privacy Act of 2012</p>
        <label class="mt-3 flex cursor-pointer items-start gap-2.5 text-sm text-hp-slate/80">
            <input type="checkbox" name="privacy_consent" value="1" required
                   class="mt-0.5 rounded border-hp-slate/40 text-hp-orange focus:ring-hp-orange"
                   @checked(old('privacy_consent'))>
            <span>
                The student agreed to PSU University Health Services collecting and processing their
                personal and health information for this clinic visit.
            </span>
        </label>
        @error('privacy_consent')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </x-hp.card>

    @include('nurse.manual-screening._vitals')
    @include('nurse.manual-screening._physical-signs')

    @if ($isAssessment)
        @include('nurse.manual-screening._social-history')

        {{-- ── The nurse's interview (D-126) — the encode page's own partials,
             in the paper's order. V and VI grey out for anyone not female. --}}
        <div class="pt-2">
            <h3 class="text-base font-semibold text-hp-slate">Interview</h3>
            <p class="mt-0.5 text-xs text-hp-slate/50">The Medical Assessment Form’s own sections. Every one is optional.</p>
        </div>
        @include('nurse.encode.assessment.medical-history')
        @include('nurse.encode.assessment.immunizations')
        @include('nurse.encode.assessment.family-planning')
        @include('nurse.encode.assessment.surgical-history')
        @include('nurse.encode.assessment.menstrual-history')
        @include('nurse.encode.assessment.ob-history')
        @include('nurse.encode.assessment.physical-exam')
    @endif

    {{-- ── Submit ──────────────────────────────────────────────────────── --}}
    <x-hp.card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-hp-slate/60">
            {{ $student->name }} will wait in the physician’s queue.
        </p>
        <x-hp.button type="submit" size="lg" data-pending-label="Sending…">
            {{ $isAssessment ? 'Submit to physician' : 'Send to physician' }}
        </x-hp.button>
    </x-hp.card>
</form>

</x-layout.sidebar>
