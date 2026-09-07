<x-layout.sidebar title="Record Detail">

    <a href="{{ route('director.anomalies') }}"
       class="mb-4 inline-block text-xs font-semibold text-hp-orange hover:underline">
        &larr; Flagged Anomalies
    </a>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">

        {{-- ── Visit & student ──────────────────────────────────────────── --}}
        <x-hp.card>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-hp-orange">{{ $visit->reference_no }}</h3>
                @if ($visit->status === 'captured')
                    <x-hp.badge variant="pending">Pending encode</x-hp.badge>
                @else
                    <x-hp.badge variant="positive">Encoded</x-hp.badge>
                @endif
            </div>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-hp-slate/50">Student</dt>
                    <dd class="font-semibold text-hp-slate">{{ $visit->student->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-hp-slate/50">Student number</dt>
                    <dd class="text-hp-slate">{{ $visit->student?->studentProfile?->student_number ?? '—' }}</dd>
                </div>
                {{-- Capture-time college snapshot (FR-STU-09, D-17) — a later
                     transfer never re-labels this record. --}}
                <div class="flex justify-between gap-4">
                    <dt class="text-hp-slate/50">College at visit</dt>
                    <dd class="text-hp-slate">{{ $visit->college->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-hp-slate/50">Checked in</dt>
                    <dd class="text-hp-slate">{{ $visit->checked_in_at?->format('M j, Y · g:i A') ?? '—' }}</dd>
                </div>
            </dl>
        </x-hp.card>

        {{-- ── Nurse assessment ─────────────────────────────────────────── --}}
        <x-hp.card>
            <h3 class="mb-4 text-sm font-semibold text-hp-slate">Nurse Assessment</h3>

            @if ($visit->clearanceRecord === null)
                <div class="flex flex-col items-center py-8 text-center">
                    <p class="text-sm font-medium text-hp-slate/60">Awaiting nurse encode</p>
                    <p class="mt-1 text-xs text-hp-slate/40">
                        This visit is still in the Live Queue &mdash; case category and
                        result appear once the nurse encodes it.
                    </p>
                </div>
            @else
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-hp-slate/50">Result</dt>
                        <dd>
                            <x-hp.badge :variant="$visit->clearanceRecord->result === 'Fit' ? 'fit' : 'unfit'">
                                {{ $visit->clearanceRecord->result }}
                            </x-hp.badge>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-hp-slate/50">Encoded</dt>
                        <dd class="text-hp-slate">{{ $visit->clearanceRecord->encoded_at?->format('M j, Y · g:i A') ?? '—' }}</dd>
                    </div>
                </dl>
            @endif
        </x-hp.card>

    </div>

    {{-- ── Vitals (flagged readings highlighted) ────────────────────────── --}}
    <x-hp.card class="mt-5">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-hp-slate">Vitals at Capture</h3>
            @if ($visit->vitalSigns)
                <span class="text-xs text-hp-slate/40">
                    {{ $visit->vitalSigns->entry_method === 'sensor' ? 'Sensor reading' : 'Manual entry' }}
                </span>
            @endif
        </div>

        @if ($visit->vitalSigns === null)
            <p class="py-6 text-center text-sm text-hp-slate/50">No vitals recorded for this visit.</p>
        @else
            @php $vitals = $visit->vitalSigns; @endphp
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Height</p>
                    <p class="mt-1 text-lg font-bold text-hp-slate">{{ $vitals->height_cm }} cm</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Weight</p>
                    <p class="mt-1 text-lg font-bold text-hp-slate">{{ $vitals->weight_kg }} kg</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">BMI</p>
                    <p class="mt-1 text-lg font-bold {{ $vitals->is_bmi_flagged ? 'text-hp-orange' : 'text-hp-slate' }}">
                        {{ $vitals->bmi }}
                    </p>
                    @if ($vitals->is_bmi_flagged)
                        <x-hp.badge variant="flagged" class="mt-1">Flagged</x-hp.badge>
                    @endif
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Temperature</p>
                    <p class="mt-1 text-lg font-bold {{ $vitals->is_temp_flagged ? 'text-hp-orange' : 'text-hp-slate' }}">
                        {{ $vitals->temperature_c }}&deg;C
                    </p>
                    @if ($vitals->is_temp_flagged)
                        <x-hp.badge variant="flagged" class="mt-1">Flagged</x-hp.badge>
                    @endif
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Heart Rate</p>
                    <p class="mt-1 text-lg font-bold text-hp-slate">{{ $vitals->heart_rate_bpm }} bpm</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Blood Pressure</p>
                    <p class="mt-1 text-lg font-bold {{ $vitals->is_bp_flagged ? 'text-hp-orange' : 'text-hp-slate' }}">
                        {{ $vitals->bp_systolic }}/{{ $vitals->bp_diastolic }}
                    </p>
                    @if ($vitals->is_bp_flagged)
                        <x-hp.badge variant="flagged" class="mt-1">Flagged</x-hp.badge>
                    @endif
                </div>
            </div>
        @endif
    </x-hp.card>

</x-layout.sidebar>
