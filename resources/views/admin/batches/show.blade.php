<x-layout.sidebar title="Batch Roster">

@php
    // Appointments only exist once the Director has approved (BR-08), so the
    // Appointment / Time / Withdraw columns are meaningless on a pending,
    // rejected or cancelled batch — the roster there is just "who was
    // submitted".
    //
    // D-55: the Status and Result columns and the results roll-up D-53 put on
    // this page are gone. Each student's outcome now lives on the Batch Results
    // popup on Batch Tracking (FR-ADM-12), where every approved batch sits in
    // one place; the roster is back to who is booked, when, and withdrawing.
    $isApproved = $batch->status === 'approved';

    $headers = $isApproved
        ? ['Student', 'Student No.', 'Appointment', 'Time', '']
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
                Medical Clearance
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
                Form
            </dt>
            {{-- D-62: the official form the clinic uses for this batch --}}
            <dd class="mt-1 text-sm font-semibold text-hp-slate">{{ $batch->formTypeLabel() }}</dd>
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
