<x-layout.sidebar title="Batch Roster">

@php
    // Appointments only exist once the Director has approved (BR-08), so the
    // Appointment / Time / Status / Result / Withdraw columns are meaningless
    // on a pending, rejected or cancelled batch — the roster there is just
    // "who was submitted".
    $isApproved = $batch->status === 'approved';

    $headers = $isApproved
        ? ['Student', 'Student No.', 'Appointment', 'Time', 'Status', 'Result', '']
        : ['Student', 'Student No.', 'Course & Year'];

    $rows = $batch->batchRequestStudents;

    // How many seats this batch is still holding — the number that matters
    // when the admin is deciding whether to withdraw someone.
    $activeCount = $rows->filter(
        fn ($row) => $row->appointment !== null && $row->appointment->status !== 'cancelled'
    )->count();

    $withdrawnCount = $rows->filter(
        fn ($row) => $row->appointment !== null && $row->appointment->status === 'cancelled'
    )->count();

    // ── D-53: the results roll-up ────────────────────────────────────────────
    // Counted from the SAME Appointment::clearanceProgress() and
    // clearanceResult() the rows below use, so the summary and the table can
    // never disagree — the rule lives in one place (the model) and both read it.
    $progress = $rows->map(fn ($row) => $row->appointment?->clearanceProgress());

    $fitCount = $rows->filter(fn ($row) => $row->appointment?->clearanceResult() === 'Fit')->count();
    $unfitCount = $rows->filter(fn ($row) => $row->appointment?->clearanceResult() === 'Unfit')->count();
    $toAttendCount = $progress->filter(fn (?string $p) => in_array($p, ['awaiting', 'in_clinic'], true))->count();
    $missedCount = $progress->filter(fn (?string $p) => $p === 'missed')->count();

    $resultParts = [];

    if ($fitCount > 0) {
        $resultParts[] = $fitCount.' Fit';
    }

    if ($unfitCount > 0) {
        $resultParts[] = $unfitCount.' Unfit';
    }

    if ($toAttendCount > 0) {
        $resultParts[] = $toAttendCount.' still to attend';
    }

    if ($missedCount > 0) {
        $resultParts[] = $missedCount.' did not attend';
    }

    if ($withdrawnCount > 0) {
        $resultParts[] = $withdrawnCount.' withdrawn';
    }
@endphp

{{-- ── Flash messages ───────────────────────────────────────────────────────── --}}
@if (session('status'))
    <div data-hp-flash class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <a href="{{ route('admin.batches.index') }}"
       class="inline-flex items-center gap-1.5 text-xs font-semibold text-hp-slate/50
              transition-colors hover:text-hp-orange">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        Back to Batch Tracking
    </a>

    <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-hp-slate">
                {{ $batch->reference_no }}
            </h2>
            <p class="mt-0.5 text-sm text-hp-slate/50">
                {{ $batch->service_type === 'medical' ? 'Medical Clearance' : 'Dental Check' }}
                for {{ $rows->count() }} {{ Str::plural('student', $rows->count()) }}
            </p>
        </div>
        <x-hp.badge :variant="$batch->status">{{ $batch->statusLabel() }}</x-hp.badge>
    </div>
</div>

{{-- ── Batch summary ────────────────────────────────────────────────────────── --}}
<x-hp.card class="mb-6">
    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Clinic date
            </dt>
            <dd class="mt-1 text-sm font-semibold text-hp-slate">
                {{ $batch->requested_date?->format('l, F j, Y') ?? '—' }}
            </dd>
        </div>
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Hour span
            </dt>
            {{-- "—" on pre-D-37 batches, which have no span --}}
            <dd class="mt-1 text-sm font-semibold text-hp-slate">{{ $batch->requestedSpanLabel() }}</dd>
        </div>
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Purpose
            </dt>
            {{-- The batch's own reason (BR-06), or the admin's typed text when
                 they picked "Others" — reasonText() already resolves both. --}}
            <dd class="mt-1 text-sm font-semibold text-hp-slate">{{ $batch->reasonText() }}</dd>
        </div>
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Students
            </dt>
            <dd class="mt-1 text-sm font-semibold text-hp-slate">
                {{ $rows->count() }}
                @if ($isApproved && $withdrawnCount > 0)
                    <span class="font-normal text-hp-slate/60">
                        ({{ $activeCount }} booked, {{ $withdrawnCount }} withdrawn)
                    </span>
                @endif
            </dd>
        </div>
    </dl>

    {{-- ── Results roll-up (FR-ADM-07 as amended by D-53) ───────────────────── --}}
    {{-- Only on an approved batch: nothing else can have a result. --}}
    @if ($isApproved)
        <div class="mt-5 border-t border-hp-slate/10 pt-4">
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Clearance results
            </dt>
            <dd class="mt-1 text-sm font-semibold text-hp-slate">
                {{-- Only reachable when the batch has no appointments at all —
                     every appointment lands in one of the counted states. --}}
                @if ($resultParts === [])
                    <span class="font-normal text-hp-slate/60">
                        Nothing to report — this batch has no appointments.
                    </span>
                @else
                    {{ implode(' · ', $resultParts) }}
                @endif
            </dd>
        </div>
    @endif
</x-hp.card>

{{-- ── Cancelled notice (FR-ADM-11, D-52) ───────────────────────────────────── --}}
@if ($batch->status === 'cancelled')
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-hp-slate/20 bg-hp-bg px-4 py-3.5">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-slate/50" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
        </svg>
        <p class="text-xs leading-relaxed text-hp-slate">
            This request was cancelled
            @if ($batch->canceller !== null)
                by <strong class="font-semibold">{{ $batch->canceller->name }}</strong>
            @endif
            @if ($batch->cancelled_at !== null)
                on {{ $batch->cancelled_at->format('M j, Y \a\t g:i A') }}
            @endif
            before the Clinic Director reviewed it. <strong class="font-semibold">No appointments
            were created</strong>, and the clinic hours it asked for were never held.
            To book these students, submit a new batch request.
        </p>
    </div>
@endif

{{-- ── Withdrawal guidance (FR-ADM-07, D-40) ────────────────────────────────── --}}
@if ($isApproved)
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-hp-orange/25 bg-hp-peach/40 px-4 py-3.5">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-xs leading-relaxed text-hp-slate">
            Students booked through a batch <strong>cannot cancel their own appointment</strong> —
            the email they received tells them to contact you. Withdrawing one here frees
            that student's seat, so another student can book the hour.
            A student who has already checked in at the kiosk can no longer be withdrawn.
        </p>
    </div>
@endif

{{-- ── Roster ───────────────────────────────────────────────────────────────── --}}
<x-hp.card>
    <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
        Students in this batch
    </p>

    @if ($rows->isEmpty())
        <p class="py-6 text-center text-sm text-hp-slate/50">
            This batch has no students on it.
        </p>
    @else
        <x-hp.table :headers="$headers">
            @foreach ($rows as $row)
                @php
                    $profile = $row->student?->studentProfile;
                    $appointment = $row->appointment;
                @endphp
                <x-hp.table-row>
                    <x-hp.table-cell label="Student" class="font-medium">
                        {{ $row->student?->name ?? 'Unknown student' }}
                    </x-hp.table-cell>

                    <x-hp.table-cell label="Student No." class="text-hp-slate/60">
                        {{ $profile?->student_number ?? '—' }}
                    </x-hp.table-cell>

                    @if ($isApproved)
                        <x-hp.table-cell label="Appointment" class="font-mono text-xs">
                            {{ $appointment?->reference_no ?? '—' }}
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Time">
                            {{-- "—" on pre-D-37 appointments, which sit in no slot --}}
                            {{ $appointment?->timeRangeLabel() ?? '—' }}
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Status">
                            @if ($appointment === null)
                                <span class="text-hp-slate/50">&mdash;</span>
                            @else
                                @php
                                    // D-53: one key from the model, mapped here to the
                                    // wording and the badge. Orange for the student who
                                    // is at the clinic RIGHT NOW (the one state that is
                                    // still moving), peach once it is done, slate for
                                    // everything that is waiting or did not happen.
                                    [$progressLabel, $progressVariant] = match ($appointment->clearanceProgress()) {
                                        'withdrawn' => ['Withdrawn', 'rejected'],
                                        'missed'    => ['Did not attend', 'rejected'],
                                        'awaiting'  => ['Not yet attended', 'pending'],
                                        'in_clinic' => ['At the clinic', 'live'],
                                        'completed' => ['Completed', 'approved'],
                                    };
                                @endphp
                                <x-hp.badge :variant="$progressVariant">{{ $progressLabel }}</x-hp.badge>
                            @endif
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Result">
                            {{-- D-53 (amends PRD §6.6): the OUTCOME only. Vitals,
                                 screening answers and nurse notes are not on this
                                 page and no link from it reaches them — the College
                                 Admin sees Fit or Unfit for their own college's
                                 students and nothing more of the clinical record. --}}
                            @php $result = $appointment?->clearanceResult(); @endphp
                            @if ($result !== null)
                                <x-hp.badge :variant="Str::lower($result)">{{ $result }}</x-hp.badge>
                            @else
                                <span class="text-hp-slate/50">&mdash;</span>
                            @endif
                        </x-hp.table-cell>

                        <x-hp.table-cell label="">
                            {{-- isAdminCancellable() is the SAME rule the endpoint
                                 enforces under a row lock, so this button can never
                                 offer something the server would refuse. --}}
                            @if ($appointment !== null && $appointment->isAdminCancellable())
                                <div x-data="{ confirming: false }">
                                    <x-hp.button variant="danger" size="sm"
                                                 x-show="!confirming"
                                                 @click="confirming = true">
                                        Withdraw
                                    </x-hp.button>

                                    <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-2">
                                        <form method="POST"
                                              action="{{ route('admin.batches.appointments.cancel', [$batch->id, $appointment->id]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-hp.button type="submit" variant="danger" size="sm"
                                                         data-pending-label="Withdrawing…">
                                                Confirm
                                            </x-hp.button>
                                        </form>
                                        <button type="button" @click="confirming = false"
                                                class="text-xs font-semibold text-hp-slate/50
                                                       transition-colors hover:text-hp-slate">
                                            Keep
                                        </button>
                                    </div>
                                </div>
                            @else
                                <span class="text-hp-slate/50">&mdash;</span>
                            @endif
                        </x-hp.table-cell>
                    @else
                        <x-hp.table-cell label="Course & Year" class="text-hp-slate/60">
                            {{ $profile?->course ?? '—' }}
                            @if ($profile?->year_level)
                                · Year {{ $profile->year_level }}
                            @endif
                        </x-hp.table-cell>
                    @endif
                </x-hp.table-row>
            @endforeach
        </x-hp.table>
    @endif
</x-hp.card>

</x-layout.sidebar>
